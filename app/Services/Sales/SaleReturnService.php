<?php

namespace App\Services\Sales;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\DocumentNumberService;
use App\Services\Accounting\PostingService;
use App\Services\Cash\CashRegisterService;
use App\Services\Inventory\StockService;
use App\Services\Parties\LedgerService;
use App\Support\InventoryAccess;
use App\Support\Rounding;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer returns. Stock comes back at the cost it left with; the refund is the price actually charged (tax included).
 * The refund goes either to the customer's account (store credit) or is paid out. Money paid out can never exceed what
 * the customer really paid for the sale, so a credit sale's unpaid part is always settled through the account.
 */
class SaleReturnService
{
    public function __construct(
        private PostingService $posting,
        private CashRegisterService $cash,
        private StockService $stock,
        private DocumentNumberService $numbers,
        private InventoryAccess $access,
        private LedgerService $ledger,
        private SaleService $sales,
    ) {}

    /** @param array{refund_method:string,return_date?:?string,notes?:?string,items:list<array{sale_item_id:int,quantity:string}>} $data */
    public function create(Sale $sale, array $data, User $user): SaleReturn
    {
        if (empty($data['items'])) {
            throw ValidationException::withMessages(['items' => 'Choose at least one item to return.']);
        }

        return DB::transaction(function () use ($sale, $data, $user) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status !== 'completed') {
                throw ValidationException::withMessages(['status' => 'Only a completed sale can have returns.']);
            }
            $this->access->assertUsable($user, $sale->warehouse_id);

            $method = $data['refund_method'];
            if ($method === 'account' && ! $sale->customer_id) {
                throw ValidationException::withMessages(['refund_method' => 'A walk-in sale has no customer account. Refund it as cash, card or bank.']);
            }

            $date = $data['return_date'] ?? $this->sales->today();
            $return = SaleReturn::create([
                'reference_no' => $this->numbers->next('sale_return', 'SRT'), 'sale_id' => $sale->id, 'customer_id' => $sale->customer_id,
                'warehouse_id' => $sale->warehouse_id, 'refund_method' => $method, 'return_date' => $date,
                'notes' => $data['notes'] ?? null, 'created_by' => $user->id,
            ]);

            $items = SaleItem::query()->where('sale_id', $sale->id)->lockForUpdate()->get()->keyBy('id');
            $refund = BigDecimal::zero();
            $taxPart = BigDecimal::zero();
            $trackedCost = BigDecimal::zero();
            $seen = [];

            foreach ($data['items'] as $i => $row) {
                $item = $items->get((int) $row['sale_item_id']);
                if (! $item || isset($seen[$item->id])) {
                    throw ValidationException::withMessages(["items.$i.sale_item_id" => 'This line is not part of the sale, or is listed twice.']);
                }
                $seen[$item->id] = true;

                $qty = BigDecimal::of((string) $row['quantity']);
                $left = BigDecimal::of($item->quantity)->minus($item->returned_qty);
                if (! $qty->isPositive() || $qty->isGreaterThan($left)) {
                    throw ValidationException::withMessages(["items.$i.quantity" => 'You can return at most '.rtrim(rtrim((string) $left->toScale(4), '0'), '.').' of '.$item->name.'.']);
                }

                // Returning the last units of a line hands back whatever is left, so rounding never leaves cents behind.
                $last = $qty->isEqualTo($left);
                $lineRefund = $last
                    ? BigDecimal::of($item->line_total)->minus($item->refunded_total)
                    : BigDecimal::of($item->line_total)->multipliedBy($qty)->dividedBy($item->quantity, 4, Rounding::halfUp());
                $lineCost = $last
                    ? BigDecimal::of($item->cost_total)->minus($item->returned_cost)
                    : BigDecimal::of($item->cost_total)->multipliedBy($qty)->dividedBy($item->quantity, 4, Rounding::halfUp());

                // The tax inside a refund is the same share of it as the tax in the line was of the line.
                if (! BigDecimal::of($item->line_total)->isZero()) {
                    $taxPart = $taxPart->plus($lineRefund->multipliedBy($item->tax_amount)->dividedBy($item->line_total, 4, Rounding::halfUp()));
                }

                if ($item->tracks_stock) {
                    $trackedCost = $trackedCost->plus($lineCost);
                    $unit = $lineCost->dividedBy($qty, 4, Rounding::halfUp());
                    $this->stock->receive(
                        $sale->warehouse_id, $item->product_id, $item->variant_id, (string) $qty, (string) $unit, 'sale_return',
                        'sale_return', $return->id, null, null, $return->reference_no, (string) $lineCost->toScale(4), false,
                    );
                }

                $return->items()->create([
                    'sale_item_id' => $item->id, 'product_id' => $item->product_id, 'variant_id' => $item->variant_id,
                    'quantity' => (string) $qty->toScale(4), 'refund_amount' => (string) $lineRefund->toScale(4), 'cost_amount' => (string) $lineCost->toScale(4),
                ]);
                $item->update([
                    'returned_qty' => (string) BigDecimal::of($item->returned_qty)->plus($qty)->toScale(4),
                    'refunded_total' => (string) BigDecimal::of($item->refunded_total)->plus($lineRefund)->toScale(4),
                    'returned_cost' => (string) BigDecimal::of($item->returned_cost)->plus($lineCost)->toScale(4),
                ]);
                $refund = $refund->plus($lineRefund);
            }

            if ($method !== 'account') {
                $room = BigDecimal::of($sale->paid_total)->minus($sale->refunded_cash);
                if ($refund->isGreaterThan($room)) {
                    throw ValidationException::withMessages(['refund_method' => 'At most '.$room->toScale(2).' can be paid back; the rest of this return can only be credited to the customer account.']);
                }
                $sale->update(['refunded_cash' => (string) BigDecimal::of($sale->refunded_cash)->plus($refund)->toScale(4)]);
            }

            // Query-builder write on purpose: the return model is immutable and its total is only known after the lines.
            DB::table('sale_returns')->where('id', $return->id)->update(['refund_total' => (string) $refund->toScale(4)]);

            if ($sale->customer_id && ! $refund->isZero()) {
                $customer = Customer::query()->withTrashed()->findOrFail($sale->customer_id);
                $this->ledger->post($customer, 'sale_return', (string) $refund->negated(), $date, 'Return '.$return->reference_no.' of '.$sale->reference_no, 'sale_return', $return->id);
                if ($method !== 'account') {
                    $this->ledger->post($customer, 'payment', (string) $refund, $date, 'Refund paid for '.$return->reference_no.' ('.$method.')', 'sale_return', $return->id);
                }
            }

            if ($method === 'cash') {
                $this->cash->record($user, 'refund', (string) $refund->negated(), 'sale_return', $return->id, $return->reference_no);
            }
            $this->posting->saleReturn($return->reference_no, $return->id, $sale->branch_id, $method, (string) $refund->toScale(4), (string) $taxPart->toScale(4), (string) $trackedCost->toScale(4), $date);

            ActivityLog::record('sale.returned', $return, ['reference_no' => $return->reference_no, 'refund' => (string) $refund, 'method' => $method]);

            return $return->refresh()->load('items');
        });
    }
}
