<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockAdjustmentRequest;
use App\Models\StockAdjustment;
use App\Services\Inventory\AdjustmentService;
use App\Support\InventoryAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockAdjustmentController extends Controller
{
    public function __construct(private AdjustmentService $adjustments, private InventoryAccess $access) {}

    public function index(Request $request): Response
    {
        $rows = StockAdjustment::query()->with(['warehouse:id,name', 'author:id,name'])->withCount('items')
            ->whereIn('warehouse_id', $this->access->warehouseIds($request->user()))
            ->orderByDesc('id')->paginate(20)->withQueryString()
            ->through(fn ($a) => [
                'id' => $a->id, 'reference_no' => $a->reference_no, 'warehouse' => $a->warehouse?->name, 'direction' => $a->direction,
                'reason' => $a->reason, 'adjusted_at' => $a->adjusted_at?->toDateString(), 'items_count' => $a->items_count, 'user' => $a->author?->name,
            ]);

        return Inertia::render('Inventory/Adjustments/Index', ['adjustments' => $rows]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Inventory/Adjustments/Form', [
            'warehouses' => $this->access->warehouses($request->user(), true),
            'reasons' => AdjustmentService::REASONS,
        ]);
    }

    public function store(StockAdjustmentRequest $request): RedirectResponse
    {
        $adjustment = $this->adjustments->create($request->validated(), $request->user());

        return redirect()->route('inventory.adjustments.show', $adjustment)->with('success', "Adjustment {$adjustment->reference_no} posted.");
    }

    public function show(Request $request, StockAdjustment $adjustment): Response
    {
        abort_unless($this->access->canView($request->user(), $adjustment->warehouse_id), 404);
        $adjustment->load(['items.product:id,name,sku', 'items.variant:id,name', 'items.batch:id,batch_number,expiry_date', 'warehouse:id,name', 'author:id,name']);

        return Inertia::render('Inventory/Adjustments/Show', [
            'adjustment' => [
                'id' => $adjustment->id, 'reference_no' => $adjustment->reference_no, 'warehouse' => $adjustment->warehouse?->name,
                'direction' => $adjustment->direction, 'reason' => $adjustment->reason, 'adjusted_at' => $adjustment->adjusted_at?->toDateString(),
                'notes' => $adjustment->notes, 'user' => $adjustment->author?->name,
                'items' => $adjustment->items->map(fn ($i) => [
                    'id' => $i->id, 'product' => $i->product?->name, 'sku' => $i->product?->sku, 'variant' => $i->variant?->name,
                    'batch' => $i->batch?->batch_number, 'expiry_date' => $i->batch?->expiry_date?->toDateString(),
                    'quantity' => (string) $i->quantity, 'unit_cost' => (string) $i->unit_cost, 'total_cost' => (string) $i->total_cost,
                ])->values(),
            ],
        ]);
    }
}
