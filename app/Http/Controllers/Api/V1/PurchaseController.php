<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseReceiveRequest;
use App\Http\Requests\PurchaseRequest;
use App\Models\Purchase;
use App\Services\Purchasing\PurchaseService;
use App\Support\InventoryAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function __construct(private PurchaseService $purchases, private InventoryAccess $access) {}

    /** Filters: status, supplier_id, from, to (order date), per_page (max 100). */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Purchase::class);

        $page = Purchase::query()->whereIn('warehouse_id', $this->access->warehouseIds($request->user()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('order_date', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('order_date', '<=', $request->query('to')))
            ->orderByDesc('id')->paginate(min($request->integer('per_page', 25), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn ($p) => $this->present($p))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, Purchase $purchase): JsonResponse
    {
        $this->authorize('view', $purchase);
        abort_unless($this->access->canView($request->user(), $purchase->warehouse_id), 404);

        return response()->json(['data' => $this->present($purchase->load('items'), true)]);
    }

    /** Send `receive_now: true` (needs purchases.receive) to receive the goods in the same call. */
    public function store(PurchaseRequest $request): JsonResponse
    {
        $receive = $request->boolean('receive_now');
        abort_if($receive && ! $request->user()->can('purchases.receive'), 403);

        return response()->json(['data' => $this->present($this->purchases->create($request->validated(), $request->user(), $receive), true)], 201);
    }

    public function receive(PurchaseReceiveRequest $request, Purchase $purchase): JsonResponse
    {
        $this->authorize('view', $purchase);
        $data = $request->validated();
        $purchase = $this->purchases->receive($purchase, $request->user(), $data['paid_amount'] ?? null, $data['payment_method'] ?? 'cash', $data['received_at'] ?? null);

        return response()->json(['data' => $this->present($purchase, true)]);
    }

    private function present(Purchase $p, bool $withItems = false): array
    {
        $row = $p->only('id', 'reference_no', 'supplier_id', 'warehouse_id', 'currency_id', 'status', 'supplier_invoice_no', 'notes')
            + [
                'order_date' => $p->order_date?->toDateString(), 'received_at' => $p->received_at?->toDateString(),
                'exchange_rate' => (string) $p->exchange_rate, 'subtotal' => (string) $p->subtotal, 'discount_total' => (string) $p->discount_total,
                'tax_total' => (string) $p->tax_total, 'total' => (string) $p->total, 'total_base' => (string) $p->total_base, 'paid_base' => (string) $p->paid_base,
            ];

        if ($withItems) {
            $row['items'] = $p->items->map(fn ($i) => [
                'id' => $i->id, 'product_id' => $i->product_id, 'variant_id' => $i->variant_id, 'quantity' => (string) $i->quantity,
                'unit_cost' => (string) $i->unit_cost, 'discount' => (string) $i->discount, 'tax_amount' => (string) $i->tax_amount,
                'line_total' => (string) $i->line_total, 'returned_qty' => (string) $i->returned_qty,
            ])->values();
        }

        return $row;
    }
}
