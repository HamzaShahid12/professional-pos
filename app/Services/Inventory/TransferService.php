<?php

namespace App\Services\Inventory;

use App\Models\ActivityLog;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\DocumentNumberService;
use App\Support\InventoryAccess;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single-step transfer between two warehouses: stock leaves the source and enters the destination in one
 * transaction, carrying its cost and batch/expiry, so a half-finished transfer can never exist.
 */
class TransferService
{
    public function __construct(
        private StockService $stock,
        private DocumentNumberService $numbers,
        private InventoryAccess $access,
    ) {}

    /**
     * @param  array{from_warehouse_id:int,to_warehouse_id:int,transferred_at:string,notes?:?string,items:list<array<string,mixed>>}  $data
     */
    public function create(array $data, User $user): StockTransfer
    {
        $from = (int) $data['from_warehouse_id'];
        $to = (int) $data['to_warehouse_id'];

        if ($from === $to) {
            throw ValidationException::withMessages(['to_warehouse_id' => 'The destination must be a different warehouse.']);
        }
        $this->access->assertUsable($user, $from, 'from_warehouse_id');
        $this->access->assertUsable($user, $to, 'to_warehouse_id');
        if (empty($data['items'])) {
            throw ValidationException::withMessages(['items' => 'Add at least one product.']);
        }

        // Stable order keeps concurrent transfers from locking rows in opposite order; the transaction retries on a deadlock.
        $items = collect($data['items'])->sortBy([['product_id', 'asc'], ['variant_id', 'asc']])->values()->all();

        return DB::transaction(function () use ($data, $user, $from, $to, $items) {
            $transfer = StockTransfer::create([
                'reference_no' => $this->numbers->next('transfer', 'TRF'),
                'from_warehouse_id' => $from, 'to_warehouse_id' => $to, 'status' => 'completed',
                'transferred_at' => $data['transferred_at'], 'notes' => $data['notes'] ?? null, 'created_by' => $user->id,
            ]);

            foreach ($items as $item) {
                $variantId = ! empty($item['variant_id']) ? (int) $item['variant_id'] : null;
                $productId = (int) $item['product_id'];

                $out = $this->stock->issue($from, $productId, $variantId, (string) $item['quantity'], 'transfer_out', 'stock_transfer', $transfer->id, $transfer->reference_no);

                foreach ($out['lines'] as $line) {
                    $this->stock->receive(
                        $to, $productId, $variantId, $line['quantity'], $line['unit_cost'], 'transfer_in', 'stock_transfer', $transfer->id,
                        $line['batch_number'], $line['expiry_date'], $transfer->reference_no, $line['total_cost'],
                    );
                }

                $transfer->items()->create([
                    'product_id' => $productId, 'variant_id' => $variantId,
                    'quantity' => (string) BigDecimal::of($out['quantity'])->toScale(4),
                    'unit_cost' => $out['unit_cost'], 'total_cost' => $out['total_cost'],
                ]);
            }

            ActivityLog::record('stock.transferred', $transfer, ['reference_no' => $transfer->reference_no, 'from' => $from, 'to' => $to]);

            return $transfer->load('items');
        }, 3);
    }
}
