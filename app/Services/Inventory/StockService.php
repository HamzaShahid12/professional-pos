<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Settings\SettingsService;
use App\Support\Rounding;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The ONLY writer of stock. Every change runs inside a transaction, takes row locks, appends immutable
 * ledger rows and updates the stock row, so `stocks.total_value` always equals SUM(stock_movements.total_cost).
 *
 * Costing: weighted average by default; FIFO (or FEFO for expiry-tracked products) consumes cost layers kept in
 * `product_batches`. Quantity without a layer (stock received before FIFO was switched on) is costed at average.
 */
class StockService
{
    public const INBOUND = ['opening', 'purchase_received', 'sale_return', 'adjustment_in', 'transfer_in'];

    public const OUTBOUND = ['sale', 'purchase_return', 'adjustment_out', 'transfer_out'];

    private const SCALE = 4;

    public function __construct(private SettingsService $settings) {}

    // ---- receive ---------------------------------------------------------------------------------

    /**
     * Add stock. `$totalCost` (optional) overrides qty x unit cost so transfers move the exact value.
     * `$enforceBatch=false` lets stock-count gains create an unbatched layer for expiry-tracked products.
     */
    public function receive(
        int $warehouseId, int $productId, ?int $variantId, string $quantity, string $unitCost, string $type,
        ?string $referenceType = null, ?int $referenceId = null, ?string $batchNumber = null,
        ?string $expiryDate = null, ?string $notes = null, ?string $totalCost = null, bool $enforceBatch = true,
    ): StockMovement {
        if (! in_array($type, self::INBOUND, true)) {
            throw new LogicException("'$type' is not an inbound movement type.");
        }

        return DB::transaction(function () use (
            $warehouseId, $productId, $variantId, $quantity, $unitCost, $type, $referenceType, $referenceId,
            $batchNumber, $expiryDate, $notes, $totalCost, $enforceBatch,
        ) {
            [$warehouse, $product] = $this->resolve($warehouseId, $productId, $variantId);
            $qty = $this->quantity($product, $quantity);
            $cost = $this->money($unitCost, 'unit_cost');

            $needsExpiry = $product->track_expiry && $type !== 'transfer_in' && $enforceBatch;
            if ($needsExpiry && (blank($batchNumber) || blank($expiryDate))) {
                throw $this->fail("{$product->name} tracks expiry: enter a batch number and an expiry date.");
            }

            $total = $totalCost !== null
                ? $this->money($totalCost, 'total_cost')
                : $qty->multipliedBy($cost)->toScale(self::SCALE, Rounding::halfUp());

            $stock = $this->lockStock($warehouse, $product, $variantId);
            $oldQty = BigDecimal::of($stock->quantity);
            $newQty = $oldQty->plus($qty);

            $batchId = null;
            if ($this->usesLayers($product)) {
                // Part of the receipt may only cover an earlier negative balance: it needs no layer.
                $deficit = $oldQty->isNegative() ? $oldQty->negated() : BigDecimal::zero();
                $layerQty = $qty->minus($deficit->isLessThan($qty) ? $deficit : $qty);
                if ($layerQty->isPositive()) {
                    $batch = ProductBatch::create([
                        'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'variant_id' => $variantId,
                        'batch_number' => $batchNumber, 'expiry_date' => $expiryDate,
                        'quantity' => (string) $layerQty->toScale(self::SCALE), 'unit_cost' => (string) $cost,
                        'received_at' => now(),
                    ]);
                    $batchId = $batch->id;
                }
            }

            $stock->update([
                'quantity' => (string) $newQty->toScale(self::SCALE),
                'total_value' => (string) BigDecimal::of($stock->total_value)->plus($total)->toScale(self::SCALE),
            ]);

            return $this->record($warehouse, $product, $variantId, $batchId, $type, $qty, $cost, $total, $newQty, $referenceType, $referenceId, $notes);
        });
    }

    // ---- issue -----------------------------------------------------------------------------------

    /**
     * Remove stock and cost it. Returns the cost of what left plus one entry per cost layer consumed.
     *
     * @return array{quantity:string,unit_cost:string,total_cost:string,lines:list<array<string,mixed>>}
     */
    public function issue(
        int $warehouseId, int $productId, ?int $variantId, string $quantity, string $type,
        ?string $referenceType = null, ?int $referenceId = null, ?string $notes = null,
    ): array {
        if (! in_array($type, self::OUTBOUND, true)) {
            throw new LogicException("'$type' is not an outbound movement type.");
        }

        return DB::transaction(function () use ($warehouseId, $productId, $variantId, $quantity, $type, $referenceType, $referenceId, $notes) {
            [$warehouse, $product] = $this->resolve($warehouseId, $productId, $variantId);
            $qty = $this->quantity($product, $quantity);

            $stock = $this->lockStock($warehouse, $product, $variantId);
            $onHand = BigDecimal::of($stock->quantity);
            $value = BigDecimal::of($stock->total_value);
            $allowNegative = (bool) $this->settings->get('inventory.allow_negative_stock', false);

            if ($onHand->isLessThan($qty) && ! $allowNegative) {
                throw $this->fail(sprintf(
                    'Not enough stock of %s in %s: %s available, %s requested.',
                    $product->name, $warehouse->name, $this->plain($onHand->isNegative() ? BigDecimal::zero() : $onHand), $this->plain($qty),
                ));
            }

            $available = $onHand->isPositive() ? $onHand : BigDecimal::zero();
            $fromStock = $qty->isLessThan($available) ? $qty : $available;
            $shortfall = $qty->minus($fromStock);

            $lines = [];
            $layered = BigDecimal::zero();
            $layeredCost = BigDecimal::zero();

            if ($this->usesLayers($product) && $fromStock->isPositive()) {
                $left = $fromStock;
                foreach ($this->layers($warehouse->id, $product, $variantId) as $batch) {
                    if (! $left->isPositive()) {
                        break;
                    }
                    $have = BigDecimal::of($batch->quantity);
                    $take = $have->isLessThan($left) ? $have : $left;
                    $cost = $take->multipliedBy($batch->unit_cost)->toScale(self::SCALE, Rounding::halfUp());

                    $batch->update(['quantity' => (string) $have->minus($take)->toScale(self::SCALE)]);
                    $lines[] = [
                        'batch_id' => $batch->id, 'batch_number' => $batch->batch_number,
                        'expiry_date' => $batch->expiry_date?->toDateString(),
                        'quantity' => $take, 'unit_cost' => BigDecimal::of($batch->unit_cost), 'total_cost' => $cost,
                    ];
                    $layered = $layered->plus($take);
                    $layeredCost = $layeredCost->plus($cost);
                    $left = $left->minus($take);
                }
            }

            // Whatever is on hand but not in a layer is costed at the running average of the stock row.
            $unlayered = $fromStock->minus($layered);
            if ($unlayered->isPositive()) {
                $restQty = $onHand->minus($layered);
                $restValue = $value->minus($layeredCost);
                $cost = $unlayered->isEqualTo($restQty)
                    ? $restValue->toScale(self::SCALE, Rounding::halfUp())
                    : $restValue->multipliedBy($unlayered)->dividedBy($restQty, self::SCALE, Rounding::halfUp());
                $lines[] = [
                    'batch_id' => null, 'batch_number' => null, 'expiry_date' => null,
                    'quantity' => $unlayered, 'unit_cost' => $this->unit($cost, $unlayered), 'total_cost' => $cost,
                ];
            }

            // Only possible with negative stock enabled: sold more than on hand. Cost at the purchase price.
            if ($shortfall->isPositive()) {
                $fallback = $this->fallbackCost($product, $variantId);
                $lines[] = [
                    'batch_id' => null, 'batch_number' => null, 'expiry_date' => null,
                    'quantity' => $shortfall, 'unit_cost' => $fallback,
                    'total_cost' => $shortfall->multipliedBy($fallback)->toScale(self::SCALE, Rounding::halfUp()),
                ];
            }

            $newQty = $onHand->minus($qty);
            $issued = array_reduce($lines, fn (BigDecimal $c, array $l) => $c->plus($l['total_cost']), BigDecimal::zero());

            // Emptying the stock row must leave a book value of exactly zero: push rounding residue into the last line.
            if ($newQty->isZero() && ! $value->minus($issued)->isZero() && $lines !== []) {
                $last = array_key_last($lines);
                $lines[$last]['total_cost'] = $lines[$last]['total_cost']->plus($value->minus($issued));
                $lines[$last]['unit_cost'] = $this->unit($lines[$last]['total_cost'], $lines[$last]['quantity']);
                $issued = $value;
            }

            $stock->update([
                'quantity' => (string) $newQty->toScale(self::SCALE),
                'total_value' => (string) $value->minus($issued)->toScale(self::SCALE),
            ]);

            $balance = $onHand;
            $out = [];
            foreach ($lines as $line) {
                $balance = $balance->minus($line['quantity']);
                $this->record(
                    $warehouse, $product, $variantId, $line['batch_id'], $type, $line['quantity']->negated(),
                    $line['unit_cost'], $line['total_cost']->negated(), $balance, $referenceType, $referenceId, $notes,
                );
                $out[] = [
                    'batch_id' => $line['batch_id'], 'batch_number' => $line['batch_number'], 'expiry_date' => $line['expiry_date'],
                    'quantity' => (string) $line['quantity']->toScale(self::SCALE),
                    'unit_cost' => (string) $line['unit_cost']->toScale(self::SCALE),
                    'total_cost' => (string) $line['total_cost']->toScale(self::SCALE),
                ];
            }

            return [
                'quantity' => (string) $qty->toScale(self::SCALE),
                'unit_cost' => (string) $this->unit($issued, $qty)->toScale(self::SCALE),
                'total_cost' => (string) $issued->toScale(self::SCALE),
                'lines' => $out,
            ];
        });
    }

    // ---- helpers used by other services ----------------------------------------------------------

    /** Current average unit cost of a stock row, falling back to the purchase price when it is empty. */
    public function averageCost(int $warehouseId, Product $product, ?int $variantId): BigDecimal
    {
        $stock = Stock::query()->where('warehouse_id', $warehouseId)->where('product_id', $product->id)
            ->where('variant_key', $variantId ?? 0)->first();

        if ($stock && BigDecimal::of($stock->quantity)->isPositive()) {
            return BigDecimal::of($stock->total_value)->dividedBy($stock->quantity, self::SCALE, Rounding::halfUp());
        }

        return $this->fallbackCost($product, $variantId);
    }

    /** Validates product/variant and returns [Warehouse, Product]; used before any stock write. */
    public function resolve(int $warehouseId, int $productId, ?int $variantId): array
    {
        $warehouse = Warehouse::query()->find($warehouseId);
        if (! $warehouse || ! $warehouse->is_active) {
            throw $this->fail('The warehouse does not exist or is inactive.', 'warehouse_id');
        }

        $product = Product::query()->with('unit')->find($productId);
        if (! $product) {
            throw $this->fail('The product does not exist or is archived.', 'product_id');
        }
        if (! $product->track_inventory) {
            throw $this->fail("{$product->name} does not track inventory, so it has no stock.", 'product_id');
        }

        if ($product->has_variants) {
            if (! $variantId || ! ProductVariant::query()->where('product_id', $product->id)->whereKey($variantId)->exists()) {
                throw $this->fail("Choose a valid variant of {$product->name}.", 'variant_id');
            }
        } elseif ($variantId) {
            throw $this->fail("{$product->name} has no variants.", 'variant_id');
        }

        return [$warehouse, $product];
    }

    // ---- internals -------------------------------------------------------------------------------

    private function lockStock(Warehouse $warehouse, Product $product, ?int $variantId): Stock
    {
        $find = fn () => Stock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)
            ->where('variant_key', $variantId ?? 0)->lockForUpdate()->first();

        $stock = $find();
        if (! $stock) {
            // insertOrIgnore: two requests creating the first row at once cannot fail each other.
            DB::table('stocks')->insertOrIgnore([
                'business_id' => $warehouse->business_id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
                'variant_id' => $variantId, 'variant_key' => $variantId ?? 0, 'quantity' => 0, 'total_value' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $stock = $find();
        }

        return $stock;
    }

    private function usesLayers(Product $product): bool
    {
        return $product->track_expiry || $this->settings->get('inventory.costing_method', 'average') === 'fifo';
    }

    private function layers(int $warehouseId, Product $product, ?int $variantId)
    {
        $query = ProductBatch::query()->where('warehouse_id', $warehouseId)->where('product_id', $product->id)
            ->where('quantity', '>', 0);
        $variantId === null ? $query->whereNull('variant_id') : $query->where('variant_id', $variantId);

        if ($product->track_expiry) {
            // First expired, first out. Batches without an expiry date go last.
            $query->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date');
        }

        return $query->orderBy('received_at')->orderBy('id')->lockForUpdate()->get();
    }

    private function record(
        Warehouse $warehouse, Product $product, ?int $variantId, ?int $batchId, string $type, BigDecimal $qty,
        BigDecimal $unitCost, BigDecimal $totalCost, BigDecimal $balance, ?string $refType, ?int $refId, ?string $notes,
    ): StockMovement {
        return StockMovement::create([
            'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
            'variant_id' => $variantId, 'batch_id' => $batchId, 'type' => $type,
            'quantity' => (string) $qty->toScale(self::SCALE), 'unit_cost' => (string) $unitCost->toScale(self::SCALE),
            'total_cost' => (string) $totalCost->toScale(self::SCALE), 'balance_after' => (string) $balance->toScale(self::SCALE),
            'reference_type' => $refType, 'reference_id' => $refId, 'notes' => $notes, 'created_by' => auth()->id(),
        ]);
    }

    private function quantity(Product $product, string $quantity): BigDecimal
    {
        try {
            $qty = BigDecimal::of($quantity);
        } catch (\Throwable) {
            throw $this->fail('Quantity must be a number.');
        }

        if (! $qty->isPositive()) {
            throw $this->fail("Quantity for {$product->name} must be greater than zero.");
        }
        if ($qty->getScale() > self::SCALE && ! $qty->toScale(self::SCALE, Rounding::halfUp())->isEqualTo($qty)) {
            throw $this->fail('Quantity allows at most 4 decimal places.');
        }
        if (! $product->unit?->allow_decimal && $qty->hasNonZeroFractionalPart()) {
            throw $this->fail("{$product->name} is counted in whole {$product->unit?->name}: decimals are not allowed.");
        }

        return $qty->toScale(self::SCALE);
    }

    private function money(string $value, string $field): BigDecimal
    {
        try {
            $amount = BigDecimal::of($value);
        } catch (\Throwable) {
            throw $this->fail('Cost must be a number.', $field);
        }
        if ($amount->isNegative()) {
            throw $this->fail('Cost cannot be negative.', $field);
        }

        return $amount->toScale(self::SCALE, Rounding::halfUp());
    }

    private function fallbackCost(Product $product, ?int $variantId): BigDecimal
    {
        $price = null;
        if ($variantId) {
            $price = ProductVariant::query()->whereKey($variantId)->value('purchase_price');
        }

        return BigDecimal::of($price ?? $product->purchase_price ?? '0')->toScale(self::SCALE, Rounding::halfUp());
    }

    private function unit(BigDecimal $total, BigDecimal $qty): BigDecimal
    {
        return $qty->isZero() ? BigDecimal::zero()->toScale(self::SCALE) : $total->dividedBy($qty, self::SCALE, Rounding::halfUp());
    }

    private function plain(BigDecimal $value): string
    {
        $text = (string) $value->toScale(self::SCALE);

        return rtrim(rtrim($text, '0'), '.') ?: '0';
    }

    private function fail(string $message, string $field = 'items'): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }
}
