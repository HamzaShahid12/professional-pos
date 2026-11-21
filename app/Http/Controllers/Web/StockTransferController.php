<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockTransferRequest;
use App\Models\StockTransfer;
use App\Services\Inventory\TransferService;
use App\Support\InventoryAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockTransferController extends Controller
{
    public function __construct(private TransferService $transfers, private InventoryAccess $access) {}

    public function index(Request $request): Response
    {
        $ids = $this->access->warehouseIds($request->user());
        $rows = StockTransfer::query()->with(['from:id,name', 'to:id,name', 'author:id,name'])->withCount('items')
            ->where(fn ($q) => $q->whereIn('from_warehouse_id', $ids)->orWhereIn('to_warehouse_id', $ids))
            ->orderByDesc('id')->paginate(20)->withQueryString()
            ->through(fn ($t) => [
                'id' => $t->id, 'reference_no' => $t->reference_no, 'from' => $t->from?->name, 'to' => $t->to?->name,
                'transferred_at' => $t->transferred_at?->toDateString(), 'items_count' => $t->items_count, 'user' => $t->author?->name,
            ]);

        return Inertia::render('Inventory/Transfers/Index', ['transfers' => $rows]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Inventory/Transfers/Form', ['warehouses' => $this->access->warehouses($request->user(), true)]);
    }

    public function store(StockTransferRequest $request): RedirectResponse
    {
        $transfer = $this->transfers->create($request->validated(), $request->user());

        return redirect()->route('inventory.transfers.show', $transfer)->with('success', "Transfer {$transfer->reference_no} completed.");
    }

    public function show(Request $request, StockTransfer $transfer): Response
    {
        $ids = $this->access->warehouseIds($request->user());
        abort_unless($ids->contains($transfer->from_warehouse_id) || $ids->contains($transfer->to_warehouse_id), 404);
        $transfer->load(['items.product:id,name,sku', 'items.variant:id,name', 'from:id,name', 'to:id,name', 'author:id,name']);

        return Inertia::render('Inventory/Transfers/Show', [
            'transfer' => [
                'id' => $transfer->id, 'reference_no' => $transfer->reference_no, 'from' => $transfer->from?->name, 'to' => $transfer->to?->name,
                'transferred_at' => $transfer->transferred_at?->toDateString(), 'notes' => $transfer->notes, 'user' => $transfer->author?->name,
                'items' => $transfer->items->map(fn ($i) => [
                    'id' => $i->id, 'product' => $i->product?->name, 'sku' => $i->product?->sku, 'variant' => $i->variant?->name,
                    'quantity' => (string) $i->quantity, 'unit_cost' => (string) $i->unit_cost, 'total_cost' => (string) $i->total_cost,
                ])->values(),
            ],
        ]);
    }
}
