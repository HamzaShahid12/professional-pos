<?php

namespace App\Services\Purchasing;

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\DocumentNumberService;
use App\Services\Accounting\PostingService;
use App\Services\Inventory\StockService;
use App\Services\Parties\LedgerService;
use App\Services\Parties\PaymentService;
use App\Support\InventoryAccess;
use App\Support\Rounding;
use App\Support\TaxCalculator;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase orders and goods receipts.
 *
 * `ordered` documents hold no stock and no debt; they can be edited or cancelled. Receiving posts, in one transaction,
 * the stock (at the net, tax-excluded cost converted to base currency), the supplier ledger entry and an optional
 * payment. A received purchase is immutable; corrections are purchase returns. Purchase tax is treated as
 * recoverable (it is part of what you owe the supplier but not of the stock cost).
 */
class PurchaseService
{
    public function __construct(
        private PostingService $posting,
        private StockService $stock,
        private DocumentNumberService $numbers,
        private InventoryAccess $access,
        private TaxCalculator $taxes,
        private CurrencyService $currencies,
        private LedgerService $ledger,
        private PaymentService $payments,
    ) {}

    /** Create an `ordered` purchase, or create and receive it straight away when `$receiveNow` is true. */
    public function create(array $data, User $user, bool $receiveNow = false): Purchase
    {
        return DB::transaction(function () use ($data, $user, $receiveNow) {
            $purchase = Purchase::create($this->header($data, $user) + [
                'reference_no' => $this->numbers->next('purchase', 'PUR'),
                'status' => 'ordered', 'created_by' => $user->id,
            ]);
            $this->writeLines($purchase, $data['items'] ?? []);
            ActivityLog::record('purchase.created', $purchase, ['reference_no' => $purchase->reference_no]);

            if ($receiveNow) {
                return $this->receive($purchase, $user, $data['paid_amount'] ?? null, $data['payment_method'] ?? 'cash', $data['received_at'] ?? null);
            }

            return $purchase->load('items');
        });
    }

    public function update(Purchase $purchase, array $data, User $user): Purchase
    {
        return DB::transaction(function () use ($purchase, $data, $user) {
            $purchase = $this->lockOrdered($purchase);
            $purchase->update($this->header($data, $user));
            $purchase->items()->delete();
            $this->writeLines($purchase, $data['items'] ?? []);
            ActivityLog::record('purchase.updated', $purchase, ['reference_no' => $purchase->reference_no]);

            return $purchase->load('items');
        });
    }

    public function cancel(Purchase $purchase): Purchase
    {
        return DB::transaction(function () use ($purchase) {
            $purchase = $this->lockOrdered($purchase);
            $purchase->update(['status' => 'cancelled']);
            ActivityLog::record('purchase.cancelled', $purchase, ['reference_no' => $purchase->reference_no]);

            return $purchase;
        });
    }

    /** Receive the goods: stock in, supplier owed, optional payment. `$paidAmount` is in the document currency. */
    public function receive(Purchase $purchase, User $user, ?string $paidAmount = null, string $method = 'cash', ?string $receivedAt = null): Purchase
    {
        return DB::transaction(function () use ($purchase, $user, $paidAmount, $method, $receivedAt) {
            $purchase = $this->lockOrdered($purchase);
            $this->access->assertUsable($user, $purchase->warehouse_id);

            $currency = Currency::query()->findOrFail($purchase->currency_id);
            $rate = BigDecimal::of($this->currencies->rateAt($currency));
            $date = $receivedAt ?: now()->toDateString();
            $totalBase = BigDecimal::zero();
            $stockCost = BigDecimal::zero();

            foreach ($purchase->items()->get() as $item) {
                $netBase = BigDecimal::of($item->net_amount)->multipliedBy($rate)->toScale(4, Rounding::halfUp());
                $unitBase = BigDecimal::of($item->quantity)->isZero() ? BigDecimal::zero()
                    : $netBase->dividedBy($item->quantity, 4, Rounding::halfUp());
                $lineBase = BigDecimal::of($item->line_total)->multipliedBy($rate)->toScale(4, Rounding::halfUp());

                $this->stock->receive(
                    $purchase->warehouse_id, $item->product_id, $item->variant_id, $item->quantity, (string) $unitBase,
                    'purchase_received', 'purchase', $purchase->id, $item->batch_number, $item->expiry_date?->toDateString(),
                    $purchase->reference_no, (string) $netBase,
                );

                $item->update(['net_unit_cost_base' => (string) $unitBase, 'line_total_base' => (string) $lineBase]);
                $totalBase = $totalBase->plus($lineBase);
                $stockCost = $stockCost->plus($netBase);
            }
            $this->posting->purchaseReceived($purchase->reference_no, $purchase->id, $purchase->warehouse_id, (string) $stockCost->toScale(4), (string) $totalBase->toScale(4), $date);

            $supplier = Supplier::query()->findOrFail($purchase->supplier_id);
            if (! $totalBase->isZero()) {
                $this->ledger->post($supplier, 'purchase', (string) $totalBase, $date, 'Purchase '.$purchase->reference_no, 'purchase', $purchase->id);
            }

            $paidBase = BigDecimal::zero();
            if ($paidAmount !== null && $paidAmount !== '' && BigDecimal::of($paidAmount)->isPositive()) {
                if (BigDecimal::of($paidAmount)->isGreaterThan($purchase->total)) {
                    throw ValidationException::withMessages(['paid_amount' => 'The payment cannot exceed the purchase total.']);
                }
                $paidBase = BigDecimal::of($paidAmount)->multipliedBy($rate)->toScale(4, Rounding::halfUp());
                if ($paidBase->isGreaterThan($totalBase)) {
                    $paidBase = $totalBase;
                }
                $this->payments->record('supplier', $supplier->id, [
                    'amount' => (string) $paidBase, 'method' => $method, 'paid_at' => $date, 'notes' => 'Paid with '.$purchase->reference_no,
                ], $user->id);
            }

            $purchase->update([
                'status' => 'received', 'received_at' => $date, 'received_by' => $user->id, 'exchange_rate' => (string) $rate,
                'total_base' => (string) $totalBase, 'paid_base' => (string) $paidBase,
            ]);
            ActivityLog::record('purchase.received', $purchase, ['reference_no' => $purchase->reference_no, 'total_base' => (string) $totalBase]);

            return $purchase->load('items');
        });
    }

    // ---- internals -------------------------------------------------------------------------------

    private function lockOrdered(Purchase $purchase): Purchase
    {
        $locked = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'ordered') {
            throw ValidationException::withMessages(['status' => "Only an ordered purchase can be changed. This one is {$locked->status}."]);
        }

        return $locked;
    }

    /** Validates the header and returns the columns to store. */
    private function header(array $data, User $user): array
    {
        $warehouse = $this->access->assertUsable($user, (int) $data['warehouse_id']);

        $supplier = Supplier::query()->where('is_active', true)->find($data['supplier_id']);
        if (! $supplier) {
            throw ValidationException::withMessages(['supplier_id' => 'Choose an active supplier.']);
        }

        $currency = ! empty($data['currency_id'])
            ? Currency::query()->where('is_active', true)->find($data['currency_id'])
            : Currency::query()->where('is_base', true)->first();
        if (! $currency) {
            throw ValidationException::withMessages(['currency_id' => 'Choose an active currency.']);
        }

        return [
            'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'branch_id' => $warehouse->branch_id,
            'currency_id' => $currency->id, 'exchange_rate' => $this->currencies->rateAt($currency),
            'order_date' => $data['order_date'], 'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function writeLines(Purchase $purchase, array $items): void
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one product.']);
        }

        $subtotal = $discountTotal = $taxTotal = $total = BigDecimal::zero();

        foreach (array_values($items) as $i => $row) {
            $variantId = ! empty($row['variant_id']) ? (int) $row['variant_id'] : null;
            [, $product] = $this->stock->resolve($purchase->warehouse_id, (int) $row['product_id'], $variantId);

            $qty = BigDecimal::of((string) $row['quantity']);
            $cost = BigDecimal::of((string) $row['unit_cost']);
            $discount = BigDecimal::of((string) ($row['discount'] ?? '0'));
            $gross = $qty->multipliedBy($cost)->toScale(4, Rounding::halfUp());

            if (! $qty->isPositive() || $cost->isNegative() || $discount->isNegative() || $discount->isGreaterThan($gross)) {
                throw ValidationException::withMessages(["items.$i.quantity" => "Check the quantity, cost and discount of {$product->name}."]);
            }

            $taxRows = $this->taxRows($product);
            $calc = $this->taxes->calculate((string) $gross->minus($discount), $taxRows);

            $purchase->items()->create([
                'product_id' => $product->id, 'variant_id' => $variantId, 'quantity' => (string) $qty->toScale(4),
                'unit_cost' => (string) $cost->toScale(4), 'discount' => (string) $discount->toScale(4),
                'net_amount' => $calc['net'], 'tax_amount' => $calc['tax_total'], 'line_total' => $calc['gross'],
                'tax_snapshot' => $calc['lines'], 'batch_number' => $row['batch_number'] ?? null, 'expiry_date' => $row['expiry_date'] ?? null,
            ]);

            $subtotal = $subtotal->plus($calc['net']);
            $discountTotal = $discountTotal->plus($discount);
            $taxTotal = $taxTotal->plus($calc['tax_total']);
            $total = $total->plus($calc['gross']);
        }

        $purchase->update([
            'subtotal' => (string) $subtotal->toScale(4), 'discount_total' => (string) $discountTotal->toScale(4),
            'tax_total' => (string) $taxTotal->toScale(4), 'total' => (string) $total->toScale(4),
        ]);
    }

    private function taxRows(Product $product): array
    {
        return $product->taxes()->where('is_active', true)->get()
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'rate' => (string) $t->rate, 'is_inclusive' => (bool) $t->is_inclusive])->all();
    }
}
