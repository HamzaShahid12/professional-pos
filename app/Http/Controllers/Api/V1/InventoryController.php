<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockAdjustmentRequest;
use App\Http\Requests\StockTransferRequest;
use App\Http\Resources\Api\V1\StockMovementResource;
use App\Services\Inventory\AdjustmentService;
use App\Services\Inventory\InventoryQueryService;
use App\Services\Inventory\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryController extends Controller
{
    public function __construct(
        private InventoryQueryService $query,
        private AdjustmentService $adjustments,
        private TransferService $transfers,
    ) {}

    /** Filters: search, warehouse_id, category_id, status (in|low|out), per_page (max 100). */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('inventory.view'), 403);
        $filters = $request->only('search', 'warehouse_id', 'category_id', 'status');
        $page = $this->query->overview($request->user(), $filters, min($request->integer('per_page', 25), 100));
        $warehouseId = $request->filled('warehouse_id') ? $request->integer('warehouse_id') : null;

        return response()->json([
            'data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'valuation' => $this->query->valuation($request->user(), $warehouseId),
        ]);
    }

    /** Filters: warehouse_id, product_id, type, from, to (YYYY-MM-DD), per_page (max 100). */
    public function movements(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('inventory.view'), 403);
        $page = $this->query->movements($request->user(), $request->only('warehouse_id', 'product_id', 'type', 'from', 'to'), min($request->integer('per_page', 30), 100));

        return StockMovementResource::collection($page);
    }

    public function adjust(StockAdjustmentRequest $request): JsonResponse
    {
        $adjustment = $this->adjustments->create($request->validated(), $request->user());

        return response()->json(['data' => $this->document($adjustment, ['warehouse_id', 'direction', 'reason', 'adjusted_at'])], 201);
    }

    public function transfer(StockTransferRequest $request): JsonResponse
    {
        $transfer = $this->transfers->create($request->validated(), $request->user());

        return response()->json(['data' => $this->document($transfer, ['from_warehouse_id', 'to_warehouse_id', 'status', 'transferred_at'])], 201);
    }

    private function document($model, array $fields): array
    {
        return ['id' => $model->id, 'reference_no' => $model->reference_no] + $model->only($fields) + [
            'items' => $model->items->map(fn ($i) => [
                'product_id' => $i->product_id, 'variant_id' => $i->variant_id, 'quantity' => (string) $i->quantity,
                'unit_cost' => (string) $i->unit_cost, 'total_cost' => (string) $i->total_cost,
            ])->values(),
        ];
    }
}
