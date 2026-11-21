<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PartyPaymentRequest;
use App\Http\Requests\PartyRequest;
use App\Services\Parties\LedgerService;
use App\Services\Parties\PartyService;
use App\Services\Parties\PaymentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** REST access to customers/suppliers (list, read, create, update, receive/pay). Money fields are strings. */
abstract class PartyApiController extends Controller
{
    abstract protected function type(): string;

    public function __construct(private PartyService $parties, private PaymentService $payments) {}

    private function model(): string
    {
        return LedgerService::modelFor($this->type());
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', $this->model());
        $page = $this->model()::query()->search($request->query('search'))
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('name')->paginate(min($request->integer('per_page', 25), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn ($p) => $this->present($p))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(string $party): JsonResponse
    {
        $model = $this->model()::query()->findOrFail($party);
        $this->authorize('view', $model);

        return response()->json(['data' => $this->present($model)]);
    }

    public function store(PartyRequest $request): JsonResponse
    {
        $this->authorize('create', $this->model());

        return response()->json(['data' => $this->present($this->parties->create($this->type(), $request->validated()))], 201);
    }

    public function update(PartyRequest $request, string $party): JsonResponse
    {
        $model = $this->model()::query()->findOrFail($party);
        $this->authorize('update', $model);

        return response()->json(['data' => $this->present($this->parties->update($model, $request->validated()))]);
    }

    public function payment(PartyPaymentRequest $request, string $party): JsonResponse
    {
        $model = $this->model()::query()->findOrFail($party);
        $this->authorize('update', $model);
        $payment = $this->payments->record($this->type(), $model->id, $request->validated(), $request->user()->id);

        return response()->json(['data' => [
            'reference_no' => $payment->reference_no, 'amount' => (string) $payment->amount,
            'party_balance' => (string) $model->fresh()->balance,
        ]], 201);
    }

    private function present(Model $p): array
    {
        return $p->only('id', 'code', 'name', 'phone', 'email', 'address', 'tax_number', 'notes', 'is_active')
            + ['balance' => (string) $p->balance]
            + ($this->type() === 'customer'
                ? ['credit_limit' => (string) $p->credit_limit]
                : ['contact_person' => $p->contact_person, 'payment_terms_days' => $p->payment_terms_days]);
    }
}
