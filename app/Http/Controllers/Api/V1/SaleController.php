<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaleRequest;
use App\Models\Sale;
use App\Services\Sales\SaleService;
use App\Support\InventoryAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function __construct(private SaleService $sales, private InventoryAccess $access) {}

    /** Filters: status (completed|voided), customer_id, payment_status, from, to (sale date), per_page (max 100). */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);

        $page = Sale::query()->whereIn('status', ['completed', 'voided'])
            ->whereIn('warehouse_id', $this->access->warehouseIds($request->user()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->query('payment_status')))
            ->soldBetween($request->query('from'), $request->query('to'))
            ->orderByDesc('id')->paginate(min($request->integer('per_page', 25), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn ($s) => $this->present($s))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, Sale $sale): JsonResponse
    {
        $this->authorize('view', $sale);
        abort_unless($this->access->canView($request->user(), $sale->warehouse_id), 404);

        return response()->json(['data' => $this->present($sale->load(['items', 'payments']), true)]);
    }

    /** Complete a sale. The server prices it; `unit_price` / `discount` need the matching permissions. */
    public function store(SaleRequest $request): JsonResponse
    {
        $sale = $this->sales->complete($request->validated(), $request->user());

        return response()->json(['data' => $this->present($sale, true)], 201);
    }

    private function present(Sale $s, bool $detail = false): array
    {
        $row = $s->only('id', 'reference_no', 'status', 'payment_status', 'warehouse_id', 'branch_id', 'customer_id', 'notes')
            + ['sold_at' => $s->sold_at?->toIso8601String()]
            + array_map('strval', $s->only('subtotal', 'discount_total', 'tax_total', 'total', 'paid_total', 'change_due', 'balance_due'));

        if ($detail) {
            $row['items'] = $s->items->map(fn ($i) => [
                'id' => $i->id, 'product_id' => $i->product_id, 'variant_id' => $i->variant_id, 'name' => $i->name, 'quantity' => (string) $i->quantity,
                'unit_price' => (string) $i->unit_price, 'discount' => (string) $i->discount, 'tax_amount' => (string) $i->tax_amount, 'line_total' => (string) $i->line_total,
            ])->values();
            $row['payments'] = $s->payments->map(fn ($p) => ['method' => $p->method, 'amount' => (string) $p->amount, 'reference' => $p->reference])->values();
        }

        return $row;
    }
}
