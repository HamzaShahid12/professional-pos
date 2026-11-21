<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseReceiveRequest;
use App\Http\Requests\PurchaseRequest;
use App\Http\Requests\PurchaseReturnRequest;
use App\Models\Currency;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\Parties\PaymentService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\PurchaseService;
use App\Support\InventoryAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController extends Controller
{
    public function __construct(
        private PurchaseService $purchases,
        private PurchaseReturnService $returns,
        private InventoryAccess $access,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Purchase::class);
        $filters = $request->only('search', 'status', 'supplier_id', 'from', 'to');

        $rows = Purchase::query()->with(['supplier:id,name', 'warehouse:id,name', 'currency:id,code,symbol,decimal_places'])
            ->whereIn('warehouse_id', $this->access->warehouseIds($request->user()))
            ->when($filters['search'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('reference_no', 'like', '%'.addcslashes($t, '%_\\').'%')->orWhere('supplier_invoice_no', 'like', '%'.addcslashes($t, '%_\\').'%')))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('order_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('order_date', '<=', $v))
            ->orderByDesc('id')->paginate(20)->withQueryString()
            ->through(fn ($p) => [
                'id' => $p->id, 'reference_no' => $p->reference_no, 'supplier' => $p->supplier?->name, 'warehouse' => $p->warehouse?->name,
                'order_date' => $p->order_date?->toDateString(), 'status' => $p->status, 'total' => $p->total,
                'total_base' => $p->total_base, 'currency' => $p->currency?->only('code', 'symbol', 'decimal_places'),
            ]);

        return Inertia::render('Purchases/Index', [
            'purchases' => $rows, 'filters' => $filters,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Purchase::class);

        return Inertia::render('Purchases/Form', ['purchase' => null] + $this->formOptions($request));
    }

    public function store(PurchaseRequest $request): RedirectResponse
    {
        $receive = $request->boolean('receive_now');
        abort_if($receive && ! $request->user()->can('purchases.receive'), 403);

        $purchase = $this->purchases->create($request->validated(), $request->user(), $receive);

        return redirect()->route('purchases.show', $purchase)->with('success', $receive ? "{$purchase->reference_no} received into stock." : "{$purchase->reference_no} saved as an order.");
    }

    public function show(Request $request, Purchase $purchase): Response
    {
        $this->authorize('view', $purchase);
        abort_unless($this->access->canView($request->user(), $purchase->warehouse_id), 404);
        $purchase->load(['items.product:id,name,sku', 'items.variant:id,name', 'supplier:id,name,code', 'warehouse:id,name', 'currency', 'author:id,name', 'returns.items']);

        return Inertia::render('Purchases/Show', [
            'purchase' => [
                'id' => $purchase->id, 'reference_no' => $purchase->reference_no, 'status' => $purchase->status,
                'supplier' => $purchase->supplier?->only('id', 'name', 'code'), 'warehouse' => $purchase->warehouse?->name,
                'currency' => $purchase->currency?->only('code', 'symbol', 'decimal_places'), 'exchange_rate' => $purchase->exchange_rate,
                'order_date' => $purchase->order_date?->toDateString(), 'received_at' => $purchase->received_at?->toDateString(),
                'supplier_invoice_no' => $purchase->supplier_invoice_no, 'notes' => $purchase->notes, 'user' => $purchase->author?->name,
                'subtotal' => $purchase->subtotal, 'discount_total' => $purchase->discount_total, 'tax_total' => $purchase->tax_total,
                'total' => $purchase->total, 'total_base' => $purchase->total_base, 'paid_base' => $purchase->paid_base,
                'items' => $purchase->items->map(fn ($i) => [
                    'id' => $i->id, 'product' => $i->product?->name, 'sku' => $i->product?->sku, 'variant' => $i->variant?->name,
                    'quantity' => (string) $i->quantity, 'returned_qty' => (string) $i->returned_qty, 'unit_cost' => (string) $i->unit_cost,
                    'discount' => (string) $i->discount, 'tax_amount' => (string) $i->tax_amount, 'line_total' => (string) $i->line_total,
                    'batch_number' => $i->batch_number, 'expiry_date' => $i->expiry_date?->toDateString(),
                ])->values(),
                'returns' => $purchase->returns->sortByDesc('id')->map(fn ($r) => [
                    'id' => $r->id, 'reference_no' => $r->reference_no, 'return_date' => $r->return_date?->toDateString(), 'total_base' => $r->total_base,
                ])->values(),
            ],
            'methods' => PaymentService::METHODS,
        ]);
    }

    public function edit(Request $request, Purchase $purchase): Response
    {
        $this->authorize('update', $purchase);
        abort_unless($purchase->status === 'ordered', 403, 'Only an ordered purchase can be edited.');
        abort_unless($this->access->canView($request->user(), $purchase->warehouse_id), 404);
        $purchase->load(['items.product.unit', 'items.product.variants']);

        return Inertia::render('Purchases/Form', [
            'purchase' => [
                'id' => $purchase->id, 'reference_no' => $purchase->reference_no, 'supplier_id' => $purchase->supplier_id,
                'warehouse_id' => $purchase->warehouse_id, 'currency_id' => $purchase->currency_id,
                'order_date' => $purchase->order_date?->toDateString(), 'supplier_invoice_no' => $purchase->supplier_invoice_no, 'notes' => $purchase->notes,
                'items' => $purchase->items->map(fn ($i) => [
                    'product' => [
                        'id' => $i->product->id, 'name' => $i->product->name, 'sku' => $i->product->sku, 'unit' => $i->product->unit?->short_name,
                        'allow_decimal' => (bool) $i->product->unit?->allow_decimal, 'track_expiry' => $i->product->track_expiry, 'has_variants' => $i->product->has_variants,
                        'variants' => $i->product->variants->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->values(),
                    ],
                    'variant_id' => $i->variant_id ?? '', 'quantity' => (string) $i->quantity, 'unit_cost' => (string) $i->unit_cost,
                    'discount' => (string) $i->discount, 'batch_number' => $i->batch_number ?? '', 'expiry_date' => $i->expiry_date?->toDateString() ?? '',
                ])->values(),
            ],
        ] + $this->formOptions($request));
    }

    public function update(PurchaseRequest $request, Purchase $purchase): RedirectResponse
    {
        $this->purchases->update($purchase, $request->validated(), $request->user());

        return redirect()->route('purchases.show', $purchase)->with('success', 'Purchase updated.');
    }

    public function cancel(Request $request, Purchase $purchase): RedirectResponse
    {
        $this->authorize('update', $purchase);
        $this->purchases->cancel($purchase);

        return back()->with('success', 'Purchase cancelled.');
    }

    public function receive(PurchaseReceiveRequest $request, Purchase $purchase): RedirectResponse
    {
        $this->authorize('view', $purchase);
        $data = $request->validated();
        $this->purchases->receive($purchase, $request->user(), $data['paid_amount'] ?? null, $data['payment_method'] ?? 'cash', $data['received_at'] ?? null);

        return back()->with('success', 'Goods received into stock.');
    }

    public function storeReturn(PurchaseReturnRequest $request, Purchase $purchase): RedirectResponse
    {
        $this->authorize('view', $purchase);
        $return = $this->returns->create($purchase, $request->validated(), $request->user());

        return back()->with('success', "Return {$return->reference_no} posted. The supplier has been credited.");
    }

    private function formOptions(Request $request): array
    {
        return [
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'warehouses' => $this->access->warehouses($request->user(), true),
            'currencies' => Currency::query()->where('is_active', true)->orderByDesc('is_base')->orderBy('code')->get(['id', 'code', 'symbol', 'decimal_places', 'is_base']),
            'methods' => PaymentService::METHODS,
            'can_receive' => $request->user()->can('purchases.receive'),
        ];
    }
}
