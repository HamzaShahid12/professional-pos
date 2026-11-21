<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaleReturnRequest;
use App\Http\Requests\SaleVoidRequest;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\Sales\SaleReturnService;
use App\Services\Sales\SaleService;
use App\Services\Settings\SettingsService;
use App\Support\InventoryAccess;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    public function __construct(
        private SaleService $sales,
        private SaleReturnService $returns,
        private InventoryAccess $access,
        private SettingsService $settings,
        private TenantContext $tenant,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Sale::class);
        $filters = $request->only('search', 'status', 'customer_id', 'payment_status', 'from', 'to');

        $rows = Sale::query()->whereIn('status', ['completed', 'voided'])->with(['customer:id,name', 'cashier:id,name', 'warehouse:id,name'])
            ->whereIn('warehouse_id', $this->access->warehouseIds($request->user()))
            ->when($filters['search'] ?? null, fn ($q, $t) => $q->where('reference_no', 'like', '%'.addcslashes($t, '%_\\').'%'))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($filters['payment_status'] ?? null, fn ($q, $v) => $q->where('payment_status', $v))
            ->soldBetween($filters['from'] ?? null, $filters['to'] ?? null)
            ->orderByDesc('id')->paginate(20)->withQueryString()
            ->through(fn ($s) => [
                'id' => $s->id, 'reference_no' => $s->reference_no, 'sold_at' => $s->sold_at?->toIso8601String(), 'customer' => $s->customer?->name,
                'cashier' => $s->cashier?->name, 'warehouse' => $s->warehouse?->name, 'status' => $s->status, 'payment_status' => $s->payment_status,
                'total' => (string) $s->total, 'balance_due' => (string) $s->balance_due,
            ]);

        return Inertia::render('Sales/Index', [
            'sales' => $rows, 'filters' => $filters,
            'customers' => $request->user()->can('customers.manage') ? Customer::query()->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    public function show(Request $request, Sale $sale): Response
    {
        $this->authorize('view', $sale);

        return Inertia::render('Sales/Show', ['sale' => $this->detail($request, $sale), 'refundMethods' => array_merge(['account'], SaleService::METHODS)]);
    }

    public function receipt(Request $request, Sale $sale): Response
    {
        $this->authorize('view', $sale);

        return Inertia::render('Sales/Receipt', [
            'sale' => $this->detail($request, $sale),
            'business' => $this->tenant->business()?->only('name', 'legal_name', 'address', 'phone', 'email', 'tax_number'),
            'width' => (string) $this->settings->get('sales.receipt_width', '80'),
            'footer' => $this->settings->get('sales.invoice_footer'),
        ]);
    }

    /** A4 invoice as a PDF download. Same data and the same access checks as the on-screen sale. */
    public function pdf(Request $request, Sale $sale): \Illuminate\Http\Response
    {
        $this->authorize('view', $sale);
        $detail = $this->detail($request, $sale);
        $currency = Currency::query()->where('is_base', true)->first(['code', 'symbol', 'decimal_places']);
        $business = $this->tenant->business();
        $tz = $business?->timezone ?? config('app.timezone');

        return Pdf::loadView('pdf.invoice', [
            'sale' => $detail,
            'soldAt' => $sale->sold_at?->timezone($tz)->format('Y-m-d H:i'),
            'business' => $business?->only('name', 'legal_name', 'address', 'phone', 'email', 'tax_number'),
            'symbol' => $currency?->symbol ?? '',
            'decimals' => (int) ($currency?->decimal_places ?? 2),
            'footer' => $this->settings->get('sales.invoice_footer'),
            'terms' => $this->settings->get('sales.invoice_terms'),
        ])->setPaper('a4')->download('invoice-'.$sale->reference_no.'.pdf');
    }

    public function void(SaleVoidRequest $request, Sale $sale): RedirectResponse
    {
        $this->sales->void($sale, $request->validated('reason'), $request->user());

        return back()->with('success', 'Sale voided. Stock was returned and the customer account reversed.');
    }

    public function storeReturn(SaleReturnRequest $request, Sale $sale): RedirectResponse
    {
        $this->authorize('view', $sale);
        $return = $this->returns->create($sale, $request->validated(), $request->user());

        return back()->with('success', "Return {$return->reference_no} posted.");
    }

    private function detail(Request $request, Sale $sale): array
    {
        abort_unless($this->access->canView($request->user(), $sale->warehouse_id), 404);
        $sale->load(['items', 'payments', 'customer:id,name,code,phone,address', 'cashier:id,name', 'warehouse:id,name', 'branch:id,name', 'returns.items']);

        return [
            'id' => $sale->id, 'reference_no' => $sale->reference_no, 'status' => $sale->status, 'payment_status' => $sale->payment_status,
            'sold_at' => $sale->sold_at?->toIso8601String(), 'customer' => $sale->customer?->only('id', 'name', 'code', 'phone', 'address'),
            'cashier' => $sale->cashier?->name, 'warehouse' => $sale->warehouse?->name, 'branch' => $sale->branch?->name, 'notes' => $sale->notes,
            'subtotal' => (string) $sale->subtotal, 'discount_total' => (string) $sale->discount_total, 'tax_total' => (string) $sale->tax_total,
            'total' => (string) $sale->total, 'paid_total' => (string) $sale->paid_total, 'change_due' => (string) $sale->change_due,
            'balance_due' => (string) $sale->balance_due, 'void_reason' => $sale->void_reason,
            'items' => $sale->items->map(fn ($i) => [
                'id' => $i->id, 'name' => $i->name, 'sku' => $i->sku, 'quantity' => (string) $i->quantity, 'returned_qty' => (string) $i->returned_qty,
                'unit_price' => (string) $i->unit_price, 'discount' => (string) $i->discount, 'tax_amount' => (string) $i->tax_amount, 'line_total' => (string) $i->line_total,
            ])->values(),
            'payments' => $sale->payments->map(fn ($p) => ['id' => $p->id, 'method' => $p->method, 'amount' => (string) $p->amount, 'reference' => $p->reference])->values(),
            'returns' => $sale->returns->sortByDesc('id')->map(fn ($r) => [
                'id' => $r->id, 'reference_no' => $r->reference_no, 'return_date' => $r->return_date?->toDateString(),
                'refund_total' => (string) $r->refund_total, 'refund_method' => $r->refund_method,
            ])->values(),
            'permissions' => [
                'return' => $request->user()->can('sales.return') && $sale->status === 'completed',
                'void' => $request->user()->can('sales.cancel') && $sale->status === 'completed' && $sale->returns->isEmpty(),
            ],
        ];
    }
}
