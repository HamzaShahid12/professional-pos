<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaleRequest;
use App\Models\Currency;
use App\Models\Sale;
use App\Services\Sales\SaleService;
use App\Support\InventoryAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The till. Everything except the first page load is JSON so the cashier never leaves the screen. */
class PosController extends Controller
{
    public function __construct(private SaleService $sales, private InventoryAccess $access) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $warehouses = $this->access->warehouses($user, true);

        $resumed = null;
        if ($request->filled('resume')) {
            $held = Sale::query()->where('status', 'held')->with(['items', 'customer:id,name,code,phone,balance,credit_limit'])->find($request->integer('resume'));
            abort_unless($held && $this->access->canView($user, $held->warehouse_id), 404);
            $resumed = [
                'id' => $held->id, 'reference_no' => $held->reference_no, 'warehouse_id' => $held->warehouse_id, 'notes' => $held->notes,
                'customer' => $held->customer?->only('id', 'name', 'code', 'phone', 'balance', 'credit_limit'),
                'items' => $held->items->map(fn ($i) => [
                    'product_id' => $i->product_id, 'variant_id' => $i->variant_id, 'name' => $i->name, 'sku' => $i->sku,
                    'quantity' => (string) $i->quantity, 'unit_price' => (string) $i->unit_price, 'discount' => (string) $i->discount,
                ])->values(),
            ];
        }

        return Inertia::render('Pos/Index', [
            'warehouses' => $warehouses,
            'defaultWarehouse' => $warehouses->firstWhere('is_default', true)?->id ?? $warehouses->first()?->id,
            'methods' => SaleService::METHODS,
            'currency' => Currency::query()->where('is_base', true)->first(['code', 'symbol', 'decimal_places']),
            'canDiscount' => $user->can('sales.discount'),
            'canOverridePrice' => $user->can('sales.override_price'),
            'canPickCustomer' => $user->can('customers.manage'),
            'resumed' => $resumed,
        ]);
    }

    public function quote(SaleRequest $request): JsonResponse
    {
        return response()->json($this->sales->quote($request->validated('items'), $request->user()));
    }

    public function hold(SaleRequest $request): JsonResponse
    {
        $existing = $request->filled('held_id') ? Sale::query()->findOrFail($request->integer('held_id')) : null;
        $sale = $this->sales->hold($request->validated(), $request->user(), $existing);

        return response()->json(['id' => $sale->id, 'reference_no' => $sale->reference_no], 201);
    }

    public function complete(SaleRequest $request): JsonResponse
    {
        $held = $request->filled('held_id') ? Sale::query()->findOrFail($request->integer('held_id')) : null;
        $sale = $this->sales->complete($request->validated(), $request->user(), $held);

        return response()->json([
            'id' => $sale->id, 'reference_no' => $sale->reference_no, 'total' => $sale->total, 'paid_total' => $sale->paid_total,
            'change_due' => $sale->change_due, 'balance_due' => $sale->balance_due, 'receipt_url' => route('sales.receipt', $sale),
        ], 201);
    }

    public function held(Request $request): JsonResponse
    {
        $rows = Sale::query()->where('status', 'held')->with('customer:id,name')->withCount('items')
            ->whereIn('warehouse_id', $this->access->warehouseIds($request->user()))
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn ($s) => [
                'id' => $s->id, 'reference_no' => $s->reference_no, 'customer' => $s->customer?->name, 'items_count' => $s->items_count,
                'total' => (string) $s->total, 'held_at' => $s->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function discard(Request $request, Sale $sale): JsonResponse
    {
        $this->authorize('create', Sale::class);
        abort_unless($this->access->canView($request->user(), $sale->warehouse_id), 404);
        $this->sales->discardHeld($sale);

        return response()->json(['ok' => true]);
    }
}
