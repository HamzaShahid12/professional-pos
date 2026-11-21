<?php

namespace App\Services\Inventory;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Services\DocumentNumberService;
use App\Support\InventoryAccess;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Posts stock adjustments (opening stock, corrections, damage, expiry write-offs). Posted adjustments are immutable. */
class AdjustmentService
{
    public const REASONS = [
        // reason => allowed directions
        'opening_stock' => ['in'],
        'found' => ['in'],
        'correction' => ['in', 'out'],
        'damaged' => ['out'],
        'expired' => ['out'],
        'other' => ['in', 'out'],
    ];

    public function __construct(
        private PostingService $posting,
        private StockService $stock,
        private DocumentNumberService $numbers,
        private InventoryAccess $access,
    ) {}

    /**
     * @param  array{warehouse_id:int,direction:string,reason:string,adjusted_at:string,notes?:?string,items:list<array<string,mixed>>}  $data
     */
    public function create(array $data, User $user): StockAdjustment
    {
        $this->access->assertUsable($user, (int) $data['warehouse_id']);

        $allowed = self::REASONS[$data['reason']] ?? null;
        if (! $allowed || ! in_array($data['direction'], $allowed, true)) {
            throw ValidationException::withMessages(['reason' => 'This reason cannot be used for a stock '.($data['direction'] === 'in' ? 'increase' : 'decrease').'.']);
        }
        if (empty($data['items'])) {
            throw ValidationException::withMessages(['items' => 'Add at least one product.']);
        }

        return DB::transaction(function () use ($data, $user) {
            $adjustment = StockAdjustment::create([
                'warehouse_id' => $data['warehouse_id'],
                'reference_no' => $this->numbers->next('adjustment', 'ADJ'),
                'direction' => $data['direction'],
                'reason' => $data['reason'],
                'adjusted_at' => $data['adjusted_at'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $type = $data['direction'] === 'out' ? 'adjustment_out' : ($data['reason'] === 'opening_stock' ? 'opening' : 'adjustment_in');
            $note = ucfirst(str_replace('_', ' ', $data['reason'])).(! empty($data['notes']) ? ': '.$data['notes'] : '');

            $value = BigDecimal::zero();
            foreach ($data['items'] as $i => $item) {
                $variantId = ! empty($item['variant_id']) ? (int) $item['variant_id'] : null;
                $qty = (string) $item['quantity'];

                if ($data['direction'] === 'in') {
                    $unitCost = $item['unit_cost'] ?? null;
                    if ($unitCost === null || $unitCost === '') {
                        $product = Product::query()->findOrFail($item['product_id']);
                        $unitCost = (string) $this->stock->averageCost((int) $data['warehouse_id'], $product, $variantId);
                    }
                    $movement = $this->stock->receive(
                        (int) $data['warehouse_id'], (int) $item['product_id'], $variantId, $qty, (string) $unitCost, $type,
                        'stock_adjustment', $adjustment->id, $item['batch_number'] ?? null, $item['expiry_date'] ?? null, $note,
                    );
                    $row = ['batch_id' => $movement->batch_id, 'unit_cost' => $movement->unit_cost, 'total_cost' => $movement->total_cost];
                    $value = $value->plus($movement->total_cost);
                } else {
                    $result = $this->stock->issue(
                        (int) $data['warehouse_id'], (int) $item['product_id'], $variantId, $qty, $type,
                        'stock_adjustment', $adjustment->id, $note,
                    );
                    $row = ['batch_id' => $result['lines'][0]['batch_id'] ?? null, 'unit_cost' => $result['unit_cost'], 'total_cost' => $result['total_cost']];
                    $value = $value->minus($result['total_cost']);
                }

                $adjustment->items()->create([
                    'product_id' => $item['product_id'], 'variant_id' => $variantId, 'quantity' => (string) BigDecimal::of($qty)->toScale(4),
                ] + $row);
            }

            $this->posting->stockAdjustment('stock_adjustment', $adjustment->id, $adjustment->reference_no, (int) $data['warehouse_id'], (string) $value->toScale(4), $type === 'opening', substr((string) $data['adjusted_at'], 0, 10));
            ActivityLog::record('stock.adjusted', $adjustment, ['reference_no' => $adjustment->reference_no, 'reason' => $data['reason'], 'direction' => $data['direction']]);

            return $adjustment->load('items');
        });
    }
}
