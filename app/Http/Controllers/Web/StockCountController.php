<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockCountRequest;
use App\Models\StockCount;
use App\Services\Inventory\StockCountService;
use App\Support\InventoryAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockCountController extends Controller
{
    public function __construct(private StockCountService $counts, private InventoryAccess $access) {}

    public function index(Request $request): Response
    {
        $rows = StockCount::query()->with(['warehouse:id,name', 'author:id,name'])->withCount('items')
            ->whereIn('warehouse_id', $this->access->warehouseIds($request->user()))
            ->orderByDesc('id')->paginate(20)->withQueryString()
            ->through(fn ($c) => [
                'id' => $c->id, 'reference_no' => $c->reference_no, 'warehouse' => $c->warehouse?->name, 'status' => $c->status,
                'items_count' => $c->items_count, 'user' => $c->author?->name, 'created_at' => $c->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Inventory/Counts/Index', [
            'counts' => $rows,
            'warehouses' => $this->access->warehouses($request->user(), true),
        ]);
    }

    public function store(StockCountRequest $request): RedirectResponse
    {
        $count = $this->counts->create((int) $request->validated('warehouse_id'), $request->validated('notes'), $request->user());

        return redirect()->route('inventory.counts.show', $count)->with('success', "Count {$count->reference_no} started.");
    }

    public function show(Request $request, StockCount $count): Response
    {
        abort_unless($this->access->canView($request->user(), $count->warehouse_id), 404);
        $count->load(['items.product:id,name,sku', 'items.variant:id,name', 'items.product.unit:id,short_name,allow_decimal', 'warehouse:id,name', 'author:id,name']);

        return Inertia::render('Inventory/Counts/Show', [
            'count' => [
                'id' => $count->id, 'reference_no' => $count->reference_no, 'warehouse' => $count->warehouse?->name, 'status' => $count->status,
                'notes' => $count->notes, 'user' => $count->author?->name, 'completed_at' => $count->completed_at?->toIso8601String(),
                'items' => $count->items->sortBy(fn ($i) => $i->product?->name)->map(fn ($i) => [
                    'id' => $i->id, 'product' => $i->product?->name, 'sku' => $i->product?->sku, 'variant' => $i->variant?->name,
                    'unit' => $i->product?->unit?->short_name, 'allow_decimal' => (bool) $i->product?->unit?->allow_decimal,
                    'system_qty' => (string) $i->system_qty, 'counted_qty' => $i->counted_qty === null ? null : (string) $i->counted_qty,
                    'expected_qty' => $i->expected_qty === null ? null : (string) $i->expected_qty,
                    'difference' => $i->difference === null ? null : (string) $i->difference,
                ])->values(),
            ],
        ]);
    }

    public function update(StockCountRequest $request, StockCount $count): RedirectResponse
    {
        $this->counts->saveCounts($count, $request->validated('items'), $request->user());

        return back()->with('success', 'Counts saved.');
    }

    public function complete(Request $request, StockCount $count): RedirectResponse
    {
        $this->counts->complete($count, $request->user());

        return back()->with('success', 'Count completed. Differences were posted to stock.');
    }

    public function cancel(Request $request, StockCount $count): RedirectResponse
    {
        $this->counts->cancel($count, $request->user());

        return redirect()->route('inventory.counts.index')->with('success', 'Count cancelled.');
    }
}
