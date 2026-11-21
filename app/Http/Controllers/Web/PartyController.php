<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\PartyAdjustmentRequest;
use App\Http\Requests\PartyPaymentRequest;
use App\Http\Requests\PartyRequest;
use App\Models\PartyPayment;
use App\Services\Parties\LedgerService;
use App\Services\Parties\PartyService;
use App\Services\Parties\PaymentService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Shared behaviour of the customer and supplier screens; subclasses only name the party type. */
abstract class PartyController extends Controller
{
    /** 'customer' | 'supplier' */
    abstract protected function type(): string;

    public function __construct(
        protected PartyService $parties,
        protected PaymentService $payments,
        protected LedgerService $ledger,
    ) {}

    private function plural(): string
    {
        return $this->type().'s';
    }

    /** @return class-string<Model> */
    private function model(): string
    {
        return LedgerService::modelFor($this->type());
    }

    private function find(int|string $id): Model
    {
        return $this->model()::query()->findOrFail($id); // tenant scoped: another business's id is a 404
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', $this->model());
        $class = $this->model();
        $filters = $request->only('search', 'status');

        $rows = $class::query()->search($filters['search'] ?? null)
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when(($filters['status'] ?? null) === 'balance', fn ($q) => $q->where('balance', '!=', 0))
            ->orderBy('name')->paginate(20)->withQueryString()
            ->through(fn ($p) => $this->present($p));

        return Inertia::render('Parties/Index', [
            'type' => $this->type(),
            'parties' => $rows,
            'filters' => $filters,
            'totals' => [
                'receivable_or_payable' => (string) $class::query()->where('balance', '>', 0)->sum('balance'),
                'advances' => (string) BigDecimal::of((string) $class::query()->where('balance', '<', 0)->sum('balance'))->abs(),
            ],
        ]);
    }

    public function store(PartyRequest $request): RedirectResponse
    {
        $this->authorize('create', $this->model());
        $party = $this->parties->create($this->type(), $request->validated());

        return redirect()->route($this->plural().'.show', $party->id)->with('success', ucfirst($this->type()).' created.');
    }

    public function show(Request $request, string $party): Response
    {
        $model = $this->find($party);
        $this->authorize('view', $model);

        $from = $request->query('from');
        $to = $request->query('to');
        $statement = $this->ledger->statement($model, $from, $to);

        return Inertia::render('Parties/Show', [
            'type' => $this->type(),
            'party' => $this->present($model) + $model->only('address', 'tax_number', 'notes') + [
                'contact_person' => $model->contact_person ?? null,
                'payment_terms_days' => $model->payment_terms_days ?? null,
                'credit_limit' => $model->credit_limit ?? null,
                'credit_available' => $this->type() === 'customer' ? $this->ledger->creditAvailable($model) : null,
            ],
            'statement' => [
                'opening' => $statement['opening'], 'closing' => $statement['closing'], 'filters' => ['from' => $from, 'to' => $to],
                'entries' => $statement['entries']->through(fn ($e) => [
                    'id' => $e->id, 'date' => $e->entry_date->toDateString(), 'type' => $e->entry_type, 'description' => $e->description,
                    'amount' => $e->amount, 'balance_after' => $e->balance_after, 'user' => $e->author?->name,
                ]),
            ],
            'methods' => PaymentService::METHODS,
        ]);
    }

    public function update(PartyRequest $request, string $party): RedirectResponse
    {
        $model = $this->find($party);
        $this->authorize('update', $model);
        $this->parties->update($model, $request->validated());

        return back()->with('success', ucfirst($this->type()).' updated.');
    }

    public function destroy(string $party): RedirectResponse
    {
        $model = $this->find($party);
        $this->authorize('delete', $model);
        $this->parties->archive($model);

        return redirect()->route($this->plural().'.index')->with('success', ucfirst($this->type()).' archived.');
    }

    public function payment(PartyPaymentRequest $request, string $party): RedirectResponse
    {
        $model = $this->find($party);
        $this->authorize('update', $model);
        $payment = $this->payments->record($this->type(), $model->id, $request->validated(), $request->user()->id);

        return back()->with('success', ($this->type() === 'customer' ? 'Receipt ' : 'Payment ').$payment->reference_no.' recorded.');
    }

    public function adjust(PartyAdjustmentRequest $request, string $party): RedirectResponse
    {
        $model = $this->find($party);
        $this->authorize('update', $model);
        $data = $request->validated();
        $this->payments->adjust($this->type(), $model->id, (string) $data['amount'], $data['entry_date'], $data['reason']);

        return back()->with('success', 'Adjustment posted to the ledger.');
    }

    /** JSON for pickers (POS customer selector, purchase supplier selector). */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', $this->model());

        $rows = $this->model()::query()->where('is_active', true)->search($request->query('q'))->orderBy('name')->limit(20)->get()
            ->map(fn ($p) => $this->present($p));

        return response()->json(['data' => $rows]);
    }

    private function present(Model $p): array
    {
        return $p->only('id', 'code', 'name', 'phone', 'email', 'is_active') + ['balance' => (string) $p->balance]
            + ($this->type() === 'customer' ? ['credit_limit' => (string) $p->credit_limit] : ['contact_person' => $p->contact_person, 'payment_terms_days' => $p->payment_terms_days]);
    }
}
