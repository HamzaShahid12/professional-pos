<?php

namespace App\Services\Inventory;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockCount;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Services\DocumentNumberService;
use App\Support\InventoryAccess;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Physical stock count. A draft snapshots the warehouse's stock; the counter enters quantities; completing it posts
 * adjustment movements for the difference between what was counted and what the system holds at that moment.
 * Lines left blank are not touched.
 */
class StockCountService
{
    public function __construct(
        private PostingService $posting,
        private StockService $stock,
        private DocumentNumberService $numbers,
        private InventoryAccess $access,
    ) {}

    public function create(int $warehouseId, ?string $notes, User $user): StockCount
    {
        $this->access->assertUsable($user, $warehouseId);

        return DB::transaction(function () use ($warehouseId, $notes, $user) {
            $rows = Stock::query()->where('warehouse_id', $warehouseId)->where('quantity', '!=', 0)
                ->whereHas('product', fn ($q) => $q->where('track_inventory', true))->get();

            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['warehouse_id' => 'This warehouse holds no stock to count.']);
            }

            $count = StockCount::create([
                'warehouse_id' => $warehouseId, 'reference_no' => $this->numbers->next('stock_count', 'CNT'),
                'status' => 'draft', 'notes' => $notes, 'created_by' => $user->id,
            ]);

            $count->items()->createMany($rows->map(fn (Stock $s) => [
                'product_id' => $s->product_id, 'variant_id' => $s->variant_id, 'variant_key' => $s->variant_key, 'system_qty' => $s->quantity,
            ])->all());

            ActivityLog::record('stock_count.created', $count, ['reference_no' => $count->reference_no]);

            return $count;
        });
    }

    /** @param list<array{id:int,counted_qty:?string}> $items */
    public function saveCounts(StockCount $count, array $items, User $user): StockCount
    {
        $this->access->assertUsable($user, $count->warehouse_id);

        return DB::transaction(function () use ($count, $items) {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);
            $this->assertDraft($count);

            $lines = $count->items()->with('product.unit')->get()->keyBy('id');

            foreach ($items as $i => $row) {
                $line = $lines->get((int) $row['id']);
                if (! $line) {
                    throw ValidationException::withMessages(["items.$i.id" => 'This line does not belong to the count.']);
                }

                $counted = $row['counted_qty'] ?? null;
                if ($counted === null || $counted === '') {
                    $line->update(['counted_qty' => null]);

                    continue;
                }
                $qty = BigDecimal::of($counted);
                if ($qty->isNegative()) {
                    throw ValidationException::withMessages(["items.$i.counted_qty" => 'Counted quantity cannot be negative.']);
                }
                if (! $line->product->unit?->allow_decimal && $qty->hasNonZeroFractionalPart()) {
                    throw ValidationException::withMessages(["items.$i.counted_qty" => "{$line->product->name} is counted in whole units."]);
                }
                $line->update(['counted_qty' => (string) $qty->toScale(4)]);
            }

            return $count->load('items');
        });
    }

    public function complete(StockCount $count, User $user): StockCount
    {
        $this->access->assertUsable($user, $count->warehouse_id);

        return DB::transaction(function () use ($count, $user) {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);
            $this->assertDraft($count);

            $lines = $count->items()->whereNotNull('counted_qty')->orderBy('id')->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Enter at least one counted quantity before completing.']);
            }

            $value = BigDecimal::zero();
            foreach ($lines as $line) {
                $variantId = $line->variant_id;
                $current = Stock::query()->where('warehouse_id', $count->warehouse_id)->where('product_id', $line->product_id)
                    ->where('variant_key', $line->variant_key)->value('quantity') ?? '0';

                $expected = BigDecimal::of($current);
                $diff = BigDecimal::of($line->counted_qty)->minus($expected);

                if ($diff->isPositive()) {
                    $product = Product::query()->findOrFail($line->product_id);
                    $cost = $this->stock->averageCost($count->warehouse_id, $product, $variantId);
                    $in = $this->stock->receive(
                        $count->warehouse_id, $line->product_id, $variantId, (string) $diff, (string) $cost, 'adjustment_in',
                        'stock_count', $count->id, null, null, $count->reference_no, null, false,
                    );
                    $value = $value->plus($in->total_cost);
                } elseif ($diff->isNegative()) {
                    $out = $this->stock->issue(
                        $count->warehouse_id, $line->product_id, $variantId, (string) $diff->negated(), 'adjustment_out',
                        'stock_count', $count->id, $count->reference_no,
                    );
                    $value = $value->minus($out['total_cost']);
                }

                $line->update(['expected_qty' => (string) $expected->toScale(4), 'difference' => (string) $diff->toScale(4)]);
            }

            $this->posting->stockAdjustment('stock_count', $count->id, $count->reference_no, $count->warehouse_id, (string) $value->toScale(4), false, $this->posting->today());
            $count->update(['status' => 'completed', 'completed_by' => $user->id, 'completed_at' => now()]);
            ActivityLog::record('stock_count.completed', $count, ['reference_no' => $count->reference_no]);

            return $count->load('items');
        }, 3);
    }

    public function cancel(StockCount $count, User $user): StockCount
    {
        $this->access->assertUsable($user, $count->warehouse_id);

        return DB::transaction(function () use ($count) {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);
            $this->assertDraft($count);
            $count->update(['status' => 'cancelled']);
            ActivityLog::record('stock_count.cancelled', $count, ['reference_no' => $count->reference_no]);

            return $count;
        });
    }

    private function assertDraft(StockCount $count): void
    {
        if ($count->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a draft count can be changed. This one is '.$count->status.'.']);
        }
    }
}
