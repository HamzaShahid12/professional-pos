<?php

namespace App\Services\Purchasing;

use App\Models\ActivityLog;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DocumentNumberService;
use App\Services\Accounting\PostingService;
use App\Services\Inventory\StockService;
use App\Services\Parties\LedgerService;
use App\Support\InventoryAccess;
use App\Support\Rounding;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Goods sent back to a supplier: stock out, supplier credit. The credit is the purchase price (tax included) of the returned quantity. */
class PurchaseReturnService
{
    public function __construct(
        private PostingService $posting,
        private StockService $stock,
        private DocumentNumberService $numbers,
        private InventoryAccess $access,
        private LedgerService $ledger,
    ) {}

    /** @param array{return_date:string,notes?:?string,items:list<array{purchase_item_id:int,quantity:string}>} $data */
    public function create(Purchase $purchase, array $data, User $user): PurchaseReturn
    {
        if (empty($data['items'])) {
            throw ValidationException::withMessages(['items' => 'Choose at least one item to return.']);
        }

        return DB::transaction(function () use ($purchase, $data, $user) {
            $purchase = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($purchase->status !== 'received') {
                throw ValidationException::withMessages(['status' => 'Only a received purchase can have returns.']);
            }
            $this->access->assertUsable($user, $purchase->warehouse_id);

            $return = PurchaseReturn::create([
                'reference_no' => $this->numbers->next('purchase_return', 'PRT'), 'purchase_id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id, 'warehouse_id' => $purchase->warehouse_id,
                'return_date' => $data['return_date'], 'notes' => $data['notes'] ?? null, 'created_by' => $user->id,
            ]);

            $items = PurchaseItem::query()->where('purchase_id', $purchase->id)->lockForUpdate()->get()->keyBy('id');
            $credit = BigDecimal::zero();
            $inventoryCost = BigDecimal::zero();
            $taxCredit = BigDecimal::zero();
            $seen = [];

            foreach ($data['items'] as $i => $row) {
                $item = $items->get((int) $row['purchase_item_id']);
                if (! $item || isset($seen[$item->id])) {
                    throw ValidationException::withMessages(["items.$i.purchase_item_id" => 'This line does not belong to the purchase, or is listed twice.']);
                }
                $seen[$item->id] = true;

                $qty = BigDecimal::of((string) $row['quantity']);
                $left = BigDecimal::of($item->quantity)->minus($item->returned_qty);
                if (! $qty->isPositive() || $qty->isGreaterThan($left)) {
                    throw ValidationException::withMessages(["items.$i.quantity" => 'You can return at most '.rtrim(rtrim((string) $left->toScale(4), '0'), '.').' of this line.']);
                }

                // The last unit of a line returns whatever credit is left, so rounding never leaves cents behind.
                $lineCredit = $qty->isEqualTo($left)
                    ? BigDecimal::of($item->line_total_base)->minus($item->credited_base)
                    : BigDecimal::of($item->line_total_base)->multipliedBy($qty)->dividedBy($item->quantity, 4, Rounding::halfUp());

                $out = $this->stock->issue($purchase->warehouse_id, $item->product_id, $item->variant_id, (string) $qty, 'purchase_return', 'purchase_return', $return->id, $return->reference_no);
                $inventoryCost = $inventoryCost->plus($out['total_cost']);
                if (! BigDecimal::of($item->line_total)->isZero()) {
                    $taxCredit = $taxCredit->plus($lineCredit->multipliedBy(BigDecimal::of($item->line_total)->minus($item->net_amount))->dividedBy($item->line_total, 4, Rounding::halfUp()));
                }

                $return->items()->create([
                    'purchase_item_id' => $item->id, 'product_id' => $item->product_id, 'variant_id' => $item->variant_id,
                    'quantity' => (string) $qty->toScale(4), 'credit_base' => (string) $lineCredit->toScale(4),
                ]);
                $item->update([
                    'returned_qty' => (string) BigDecimal::of($item->returned_qty)->plus($qty)->toScale(4),
                    'credited_base' => (string) BigDecimal::of($item->credited_base)->plus($lineCredit)->toScale(4),
                ]);
                $credit = $credit->plus($lineCredit);
            }

            // Query-builder write on purpose: the model is immutable, and the total is only known once every line is processed.
            DB::table('purchase_returns')->where('id', $return->id)->update(['total_base' => (string) $credit->toScale(4)]);
            $this->posting->purchaseReturn($return->reference_no, $return->id, $purchase->warehouse_id, (string) $credit->toScale(4), (string) $inventoryCost->toScale(4), (string) $taxCredit->toScale(4), $data['return_date']);
            if (! $credit->isZero()) {
                $supplier = Supplier::query()->findOrFail($purchase->supplier_id);
                $this->ledger->post($supplier, 'purchase_return', (string) $credit->negated(), $data['return_date'], 'Return '.$return->reference_no.' of '.$purchase->reference_no, 'purchase_return', $return->id);
            }
            ActivityLog::record('purchase.returned', $return, ['reference_no' => $return->reference_no, 'credit' => (string) $credit]);

            return $return->refresh()->load('items');
        });
    }
}
