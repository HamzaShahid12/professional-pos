<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryQueryService;
use App\Support\InventoryAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function __construct(private InventoryQueryService $query, private InventoryAccess $access) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'warehouse_id', 'category_id', 'status');
        $user = $request->user();
        $warehouseId = $request->filled('warehouse_id') ? $request->integer('warehouse_id') : null;

        return Inertia::render('Inventory/Index', [
            'stock' => $this->query->overview($user, $filters),
            'valuation' => $this->query->valuation($user, $warehouseId),
            'counts' => $this->query->alertCounts($user),
            'filters' => $filters,
            'warehouses' => $this->access->warehouses($user),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function movements(Request $request): Response
    {
        $filters = $request->only('warehouse_id', 'product_id', 'type', 'from', 'to');

        return Inertia::render('Inventory/Movements', [
            'movements' => $this->query->movements($request->user(), $filters)->through(fn (StockMovement $m) => [
                'id' => $m->id, 'at' => $m->created_at?->toIso8601String(), 'type' => $m->type,
                'product' => $m->product?->name, 'sku' => $m->product?->sku, 'variant' => $m->variant?->name,
                'warehouse' => $m->warehouse?->name, 'quantity' => $m->quantity, 'unit_cost' => $m->unit_cost,
                'total_cost' => $m->total_cost, 'balance_after' => $m->balance_after, 'notes' => $m->notes,
                'reference_type' => $m->reference_type, 'reference_id' => $m->reference_id, 'user' => $m->author?->name,
            ]),
            'filters' => $filters,
            'types' => StockMovement::TYPES,
            'warehouses' => $this->access->warehouses($request->user()),
            'product' => $request->filled('product_id') ? Product::query()->whereKey($request->integer('product_id'))->first(['id', 'name', 'sku']) : null,
        ]);
    }

    public function expiry(Request $request): Response
    {
        $days = min(max($request->integer('days', 30), 0), 365);

        return Inertia::render('Inventory/Expiry', [
            'batches' => $this->query->expiry($request->user(), $days),
            'days' => $days,
        ]);
    }
}
