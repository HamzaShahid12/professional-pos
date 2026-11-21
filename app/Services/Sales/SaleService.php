<?php

namespace App\Services\Sales;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Services\DocumentNumberService;
use App\Services\Accounting\PostingService;
use App\Services\Cash\CashRegisterService;
use App\Services\Inventory\StockService;
use App\Services\Parties\LedgerService;
use App\Services\Settings\SettingsService;
use App\Support\InventoryAccess;
use App\Support\Rounding;
use App\Support\TaxCalculator;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Point-of-sale invoices. Prices, discounts, tax, totals, change and credit are all decided HERE; the browser only
 * sends what was scanned and tendered.
 *
 * Completing a sale is one transaction: stock out (cost of goods sold comes from StockService), invoice, tender rows,
 * and, for a known customer, ledger entries (invoice, then each payment). Completed sales are never edited; they
 * are corrected with a return or a void.
 */
class SaleService
{
    public const METHODS = ['cash', 'bank', 'card', 'cheque', 'other'];

    public function __construct(
        private PostingService $posting,
        private CashRegisterService $cash,
        private StockService $stock,
        private DocumentNumberService $numbers,
        private InventoryAccess $access,
        private TaxCalculator $taxes,
        private LedgerService $ledger,
        private SettingsService $settings,
        private TenantContext $tenant,
    ) {}

    /** Preview totals exactly as the server will charge them. Writes nothing. */
    public function quote(array $items, User $user): array
    {
        $lines = $this->buildLines($items, $user);

        return ['lines' => array_map(fn ($l) => $this->present($l), $lines)] + $this->totals($lines);
    }

    /** Park the cart without touching stock or money. */
    public function hold(array $data, User $user, ?Sale $existing = null): Sale
    {
        $warehouse = $this->access->assertUsable($user, (int) $data['warehouse_id']);
        $customer = $this->customer($data['customer_id'] ?? null);

        return DB::transaction(function () use ($data, $user, $warehouse, $customer, $existing) {
            $lines = $this->buildLines($data['items'] ?? [], $user);

            if ($existing) {
                $sale = $this->lockHeld($existing);
                $sale->items()->delete();
            } else {
                $sale = new Sale(['reference_no' => $this->numbers->next('held_sale', 'HLD'), 'status' => 'held', 'created_by' => $user->id]);
            }

            $sale->fill(['warehouse_id' => $warehouse->id, 'branch_id' => $warehouse->branch_id, 'customer_id' => $customer?->id, 'notes' => $data['notes'] ?? null]
                + $this->totalsColumns($lines))->save();
            $this->writeItems($sale, $lines);

            return $sale->load('items');
        });
    }

    public function discardHeld(Sale $sale): void
    {
        DB::transaction(function () use ($sale) {
            $this->lockHeld($sale)->delete(); // a hold is a draft, not a financial record
        });
    }

    /**
     * @param  array{warehouse_id:int,customer_id?:?int,items:list<array>,payments?:list<array{method:string,amount:string,reference?:?string}>,notes?:?string}  $data
     */
    public function complete(array $data, User $user, ?Sale $held = null): Sale
    {
        $warehouse = $this->access->assertUsable($user, (int) $data['warehouse_id']);

        return DB::transaction(function () use ($data, $user, $warehouse, $held) {
            $lines = $this->buildLines($data['items'] ?? [], $user);
            $totals = $this->totals($lines);
            $total = BigDecimal::of($totals['total']);

            [$payments, $paid, $change] = $this->tender($data['payments'] ?? [], $total);
            $due = $total->minus($paid);
            $cashTaken = $this->cashOf($payments);
            if ($cashTaken->isPositive()) {
                $this->cash->assertCanTakeCash($user);
            }

            $customer = null;
            if (! empty($data['customer_id'])) {
                $customer = Customer::query()->where('is_active', true)->lockForUpdate()->find($data['customer_id']);
                if (! $customer) {
                    throw ValidationException::withMessages(['customer_id' => 'Choose an active customer.']);
                }
            }
            if ($due->isPositive()) {
                $this->assertCredit($customer, $due);
            }

            $reference = $this->numbers->next('sale', (string) $this->settings->get('sales.invoice_prefix', 'INV'));
            $attrs = [
                'reference_no' => $reference, 'status' => 'completed', 'warehouse_id' => $warehouse->id, 'branch_id' => $warehouse->branch_id,
                'customer_id' => $customer?->id, 'paid_total' => (string) $paid->toScale(4), 'change_due' => (string) $change->toScale(4),
                'balance_due' => (string) $due->toScale(4), 'payment_status' => $due->isPositive() ? ($paid->isPositive() ? 'partial' : 'unpaid') : 'paid',
                'sold_at' => now(), 'notes' => $data['notes'] ?? null,
            ] + $this->totalsColumns($lines);

            if ($held) {
                $sale = $this->lockHeld($held);
                $sale->items()->delete();
                $sale->fill($attrs)->save();
            } else {
                $sale = Sale::create($attrs + ['created_by' => $user->id]);
            }

            $items = $this->writeItems($sale, $lines);

            // Issue stock in a stable order so concurrent sales lock rows the same way (the transaction retries a deadlock).
            $order = array_keys($lines);
            usort($order, fn ($a, $b) => [$lines[$a]['product']->id, $lines[$a]['variant_id'] ?? 0] <=> [$lines[$b]['product']->id, $lines[$b]['variant_id'] ?? 0]);

            $cogs = BigDecimal::zero();
            foreach ($order as $i) {
                $line = $lines[$i];
                if ($line['tracks_stock']) {
                    $out = $this->stock->issue($warehouse->id, $line['product']->id, $line['variant_id'], $line['quantity'], 'sale', 'sale', $sale->id, $reference);
                    $cost = $out['total_cost'];
                } else {
                    $cost = (string) BigDecimal::of($line['quantity'])->multipliedBy($line['product']->purchase_price ?? '0')->toScale(4, Rounding::halfUp());
                }
                $items[$i]->update(['cost_total' => $cost]);
                $cogs = $cogs->plus($cost);
            }
            $sale->update(['cogs_total' => (string) $cogs->toScale(4)]);

            foreach ($payments as $p) {
                $sale->payments()->create($p);
            }
            $this->cash->record($user, 'sale', (string) $cashTaken, 'sale', $sale->id, $reference);

            if ($customer) {
                $this->postCustomerLedger($customer, $sale, $total, $payments);
            }
            $this->posting->sale($sale->refresh(), $payments, $this->today());

            ActivityLog::record('sale.completed', $sale, ['reference_no' => $reference, 'total' => (string) $total, 'customer_id' => $customer?->id]);

            return $sale->load(['items', 'payments']);
        }, 3);
    }

    /** Cancel a completed sale that has no returns: stock goes back at its original cost, the customer account is reversed. */
    public function void(Sale $sale, string $reason, User $user): Sale
    {
        return DB::transaction(function () use ($sale, $reason, $user) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status !== 'completed') {
                throw ValidationException::withMessages(['status' => "Only a completed sale can be voided. This one is {$sale->status}."]);
            }
            if ($sale->returns()->exists()) {
                throw ValidationException::withMessages(['status' => 'This sale already has returns. Return the remaining items instead of voiding it.']);
            }
            $this->access->assertUsable($user, $sale->warehouse_id);

            foreach ($sale->items()->get() as $item) {
                if ($item->tracks_stock) {
                    $unit = BigDecimal::of($item->quantity)->isZero() ? '0' : (string) BigDecimal::of($item->cost_total)->dividedBy($item->quantity, 4, Rounding::halfUp());
                    $this->stock->receive($sale->warehouse_id, $item->product_id, $item->variant_id, $item->quantity, $unit, 'sale_return', 'sale', $sale->id, null, null, 'Void '.$sale->reference_no, $item->cost_total, false);
                }
            }

            if ($sale->customer_id) {
                $customer = Customer::query()->withTrashed()->findOrFail($sale->customer_id);
                if (! BigDecimal::of($sale->total)->isZero()) {
                    $this->ledger->post($customer, 'sale_return', (string) BigDecimal::of($sale->total)->negated(), $this->today(), 'Void of '.$sale->reference_no, 'sale', $sale->id);
                }
                if (BigDecimal::of($sale->paid_total)->isPositive()) {
                    $this->ledger->post($customer, 'payment', (string) $sale->paid_total, $this->today(), 'Refund of voided '.$sale->reference_no, 'sale', $sale->id);
                }
            }

            $cashBack = $this->cashOf($sale->payments()->get(['method', 'amount'])->all());
            $this->cash->record($user, 'refund', (string) $cashBack->negated(), 'sale', $sale->id, 'Void of '.$sale->reference_no);
            $sale->update(['status' => 'voided', 'voided_by' => $user->id, 'voided_at' => now(), 'void_reason' => $reason]);
            $this->posting->voidSale($sale, $this->today());
            ActivityLog::record('sale.voided', $sale, ['reference_no' => $sale->reference_no, 'reason' => $reason]);

            return $sale;
        });
    }

    /** Cash part of a set of tenders (arrays or payment rows). */
    private function cashOf(array $payments): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($payments as $p) {
            if (($p['method'] ?? null) === 'cash') {
                $sum = $sum->plus($p['amount']);
            }
        }

        return $sum;
    }

    public function today(): string
    {
        return now($this->tenant->business()?->timezone ?? config('app.timezone'))->toDateString();
    }

    // ---- internals -------------------------------------------------------------------------------

    private function lockHeld(Sale $sale): Sale
    {
        $locked = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'held') {
            throw ValidationException::withMessages(['status' => 'This sale is no longer on hold.']);
        }

        return $locked;
    }

    private function customer(mixed $id): ?Customer
    {
        if (empty($id)) {
            return null;
        }

        return Customer::query()->where('is_active', true)->find($id) ?? throw ValidationException::withMessages(['customer_id' => 'Choose an active customer.']);
    }

    /** @return list<array<string,mixed>> */
    private function buildLines(array $items, User $user): array
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'The cart is empty.']);
        }

        $lines = [];
        foreach (array_values($items) as $i => $row) {
            $product = Product::query()->with(['unit', 'taxes' => fn ($q) => $q->where('is_active', true)])->where('is_active', true)->find($row['product_id'] ?? 0);
            if (! $product) {
                throw ValidationException::withMessages(["items.$i.product_id" => 'This product is not available for sale.']);
            }

            $variant = null;
            if ($product->has_variants) {
                $variant = ProductVariant::query()->where('product_id', $product->id)->where('is_active', true)->find($row['variant_id'] ?? 0);
                if (! $variant) {
                    throw ValidationException::withMessages(["items.$i.variant_id" => "Choose a variant of {$product->name}."]);
                }
            } elseif (! empty($row['variant_id'])) {
                throw ValidationException::withMessages(["items.$i.variant_id" => "{$product->name} has no variants."]);
            }

            $qty = BigDecimal::of((string) ($row['quantity'] ?? '0'));
            if (! $qty->isPositive()) {
                throw ValidationException::withMessages(["items.$i.quantity" => "Quantity of {$product->name} must be greater than zero."]);
            }
            if (! $product->unit?->allow_decimal && $qty->hasNonZeroFractionalPart()) {
                throw ValidationException::withMessages(["items.$i.quantity" => "{$product->name} is sold in whole units."]);
            }

            $listPrice = BigDecimal::of($variant?->selling_price ?? $product->selling_price);
            $price = $listPrice;
            if (isset($row['unit_price']) && $row['unit_price'] !== '' && ! BigDecimal::of((string) $row['unit_price'])->isEqualTo($listPrice)) {
                if (! $user->can('sales.override_price')) {
                    throw ValidationException::withMessages(["items.$i.unit_price" => 'You are not allowed to change prices.']);
                }
                $price = BigDecimal::of((string) $row['unit_price']);
            }
            if ($price->isNegative()) {
                throw ValidationException::withMessages(["items.$i.unit_price" => 'The price cannot be negative.']);
            }

            $gross = $qty->multipliedBy($price)->toScale(4, Rounding::halfUp());
            $discount = BigDecimal::of((string) ($row['discount'] ?? '0'));
            if ($discount->isNegative() || $discount->isGreaterThan($gross)) {
                throw ValidationException::withMessages(["items.$i.discount" => "The discount on {$product->name} must be between 0 and the line amount."]);
            }
            if ($discount->isPositive() && ! $user->can('sales.discount')) {
                throw ValidationException::withMessages(["items.$i.discount" => 'You are not allowed to give discounts.']);
            }
            $minPrice = BigDecimal::of($product->min_selling_price ?? '0');
            if ($minPrice->isPositive() && $gross->minus($discount)->isLessThan($minPrice->multipliedBy($qty))) {
                throw ValidationException::withMessages(["items.$i.unit_price" => "{$product->name} cannot be sold below its minimum price of {$minPrice->toScale(2)}."]);
            }

            $calc = $this->taxes->calculate((string) $gross->minus($discount), $product->taxes->map(fn ($t) => [
                'id' => $t->id, 'name' => $t->name, 'rate' => (string) $t->rate, 'is_inclusive' => (bool) $t->is_inclusive,
            ])->all());

            $lines[] = [
                'product' => $product, 'variant_id' => $variant?->id, 'tracks_stock' => (bool) $product->track_inventory,
                'name' => $product->name.($variant ? ' · '.$variant->name : ''), 'sku' => $variant?->sku ?: $product->sku,
                'quantity' => (string) $qty->toScale(4), 'unit_price' => (string) $price->toScale(4), 'discount' => (string) $discount->toScale(4),
                'net' => $calc['net'], 'tax' => $calc['tax_total'], 'total' => $calc['gross'], 'taxes' => $calc['lines'],
            ];
        }

        return $lines;
    }

    private function totals(array $lines): array
    {
        $sum = fn (string $key) => (string) array_reduce($lines, fn (BigDecimal $c, array $l) => $c->plus($l[$key]), BigDecimal::zero())->toScale(4);

        return ['subtotal' => $sum('net'), 'discount_total' => $sum('discount'), 'tax_total' => $sum('tax'), 'total' => $sum('total')];
    }

    private function totalsColumns(array $lines): array
    {
        return $this->totals($lines);
    }

    private function present(array $l): array
    {
        return [
            'product_id' => $l['product']->id, 'variant_id' => $l['variant_id'], 'name' => $l['name'], 'sku' => $l['sku'],
            'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'discount' => $l['discount'],
            'net_amount' => $l['net'], 'tax_amount' => $l['tax'], 'line_total' => $l['total'],
        ];
    }

    /** @return array<int, \App\Models\SaleItem> */
    private function writeItems(Sale $sale, array $lines): array
    {
        $items = [];
        foreach ($lines as $i => $l) {
            $items[$i] = $sale->items()->create([
                'product_id' => $l['product']->id, 'variant_id' => $l['variant_id'], 'name' => $l['name'], 'sku' => $l['sku'],
                'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'discount' => $l['discount'], 'net_amount' => $l['net'],
                'tax_amount' => $l['tax'], 'line_total' => $l['total'], 'tax_snapshot' => $l['taxes'], 'tracks_stock' => $l['tracks_stock'],
            ]);
        }

        return $items;
    }

    /**
     * Splits what the customer handed over into money kept and change. Only cash can produce change.
     *
     * @return array{0: list<array<string,mixed>>, 1: BigDecimal, 2: BigDecimal}
     */
    private function tender(array $payments, BigDecimal $total): array
    {
        $rows = [];
        $tendered = BigDecimal::zero();
        $cash = BigDecimal::zero();

        foreach ($payments as $i => $p) {
            $amount = BigDecimal::of((string) ($p['amount'] ?? '0'));
            if ($amount->isNegative() || ! in_array($p['method'] ?? '', self::METHODS, true)) {
                throw ValidationException::withMessages(["payments.$i.amount" => 'Check the payment method and amount.']);
            }
            if ($amount->isZero()) {
                continue;
            }
            $rows[] = ['method' => $p['method'], 'amount' => $amount, 'reference' => $p['reference'] ?? null];
            $tendered = $tendered->plus($amount);
            if ($p['method'] === 'cash') {
                $cash = $cash->plus($amount);
            }
        }

        $change = BigDecimal::zero();
        if ($tendered->isGreaterThan($total)) {
            $change = $tendered->minus($total);
            if ($change->isGreaterThan($cash)) {
                throw ValidationException::withMessages(['payments' => 'Card, bank and cheque payments cannot exceed the amount due. Only cash gives change.']);
            }
            // take the change out of the cash rows, last first
            $left = $change;
            for ($k = count($rows) - 1; $k >= 0 && $left->isPositive(); $k--) {
                if ($rows[$k]['method'] !== 'cash') {
                    continue;
                }
                $cut = $rows[$k]['amount']->isLessThan($left) ? $rows[$k]['amount'] : $left;
                $rows[$k]['amount'] = $rows[$k]['amount']->minus($cut);
                $left = $left->minus($cut);
            }
        }

        $kept = [];
        $paid = BigDecimal::zero();
        foreach ($rows as $r) {
            if ($r['amount']->isPositive()) {
                $kept[] = ['method' => $r['method'], 'amount' => (string) $r['amount']->toScale(4), 'reference' => $r['reference']];
                $paid = $paid->plus($r['amount']);
            }
        }

        return [$kept, $paid, $change];
    }

    private function assertCredit(?Customer $customer, BigDecimal $due): void
    {
        if (! $customer) {
            throw ValidationException::withMessages(['payments' => 'The full amount must be paid for a walk-in customer. Choose a customer to sell on credit.']);
        }
        $available = BigDecimal::of($customer->credit_limit)->minus($customer->balance);
        if ($due->isGreaterThan($available)) {
            $shown = $available->isNegative() ? '0.00' : (string) $available->toScale(2);
            throw ValidationException::withMessages(['payments' => "Credit limit exceeded: {$customer->name} can owe {$shown} more."]);
        }
    }

    private function postCustomerLedger(Customer $customer, Sale $sale, BigDecimal $total, array $payments): void
    {
        $date = $this->today();
        if (! $total->isZero()) {
            $this->ledger->post($customer, 'invoice', (string) $total, $date, 'Sale '.$sale->reference_no, 'sale', $sale->id);
        }
        foreach ($payments as $p) {
            $this->ledger->post($customer, 'payment', (string) BigDecimal::of($p['amount'])->negated(), $date, 'Sale '.$sale->reference_no.' paid by '.$p['method'], 'sale', $sale->id);
        }
    }
}
