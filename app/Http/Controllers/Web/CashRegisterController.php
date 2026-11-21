<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashRegisterRequest;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Services\Cash\CashRegisterService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashRegisterController extends Controller
{
    public function __construct(private CashRegisterService $cash) {}

    private function visibleBranchIds(Request $request)
    {
        return $request->user()->accessibleBranchIds(app(TenantContext::class)->businessId());
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $full = $user->hasFullBranchAccess(app(TenantContext::class)->businessId());
        $branchIds = $this->visibleBranchIds($request);

        $registers = CashRegister::query()->with(['branch:id,name'])->whereIn('branch_id', $branchIds)->orderBy('name')->get();
        $open = CashSession::query()->where('status', 'open')->whereIn('cash_register_id', $registers->pluck('id'))->get()->keyBy('cash_register_id');
        $mine = $this->cash->openFor($user);

        $sessions = CashSession::query()->with(['register:id,name,branch_id', 'cashier:id,name'])
            ->whereIn('cash_register_id', CashRegister::query()->whereIn('branch_id', $branchIds)->select('id'))
            ->when(! $full, fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('id')->paginate(15)->withQueryString()
            ->through(fn ($s) => $this->present($s));

        return Inertia::render('CashRegister/Index', [
            'registers' => $registers->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'branch_id' => $r->branch_id, 'branch' => $r->branch?->name, 'is_active' => $r->is_active,
                'open_session_id' => $open->get($r->id)?->id,
            ]),
            'mine' => $mine ? $this->present($mine->load(['register:id,name', 'cashier:id,name'])) + ['summary' => $this->cash->summary($mine)] : null,
            'sessions' => $sessions,
            'branches' => Branch::query()->whereIn('id', $branchIds)->orderBy('name')->get(['id', 'name']),
            'can_configure' => $user->can('settings.manage'),
        ]);
    }

    public function storeRegister(CashRegisterRequest $request): RedirectResponse
    {
        CashRegister::create($request->validated() + ['is_active' => true]);

        return back()->with('success', 'Register created.');
    }

    public function updateRegister(CashRegisterRequest $request, CashRegister $register): RedirectResponse
    {
        $register->update($request->validated());

        return back()->with('success', 'Register updated.');
    }

    public function open(Request $request, CashRegister $register): RedirectResponse
    {
        $data = $request->validate(['opening_float' => ['required', 'numeric', 'min:0', 'max:9999999999', 'regex:/^\d+(\.\d{1,4})?$/']]);
        $session = $this->cash->openSession($register, $request->user(), (string) $data['opening_float']);

        return redirect()->route('cash-register.index')->with('success', "Session {$session->reference_no} opened.");
    }

    public function show(Request $request, CashSession $session): Response
    {
        $user = $request->user();
        abort_unless($this->cash->canOperate($session, $user) || $user->hasFullBranchAccess($session->business_id), 404);
        $session->load(['register:id,name,branch_id', 'cashier:id,name', 'closer:id,name', 'movements']);

        return Inertia::render('CashRegister/Show', [
            'session' => $this->present($session) + ['notes' => $session->notes, 'closer' => $session->closer?->name],
            'summary' => $this->cash->summary($session),
            'movements' => $session->movements->map(fn ($m) => [
                'id' => $m->id, 'type' => $m->type, 'amount' => $m->amount, 'counter' => $m->counter, 'notes' => $m->notes, 'at' => $m->created_at?->toIso8601String(),
            ])->values(),
            'can_operate' => $session->status === 'open' && $this->cash->canOperate($session, $user),
        ]);
    }

    public function movement(Request $request, CashSession $session): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:in,out'], 'counter' => ['required', 'in:owner,bank'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'regex:/^\d+(\.\d{1,4})?$/'], 'notes' => ['required', 'string', 'max:255'],
        ]);
        $this->cash->payInOut($session, $request->user(), $data['direction'], $data['counter'], (string) $data['amount'], $data['notes']);

        return back()->with('success', 'Cash movement recorded.');
    }

    public function close(Request $request, CashSession $session): RedirectResponse
    {
        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:9999999999', 'regex:/^\d+(\.\d{1,4})?$/'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $closed = $this->cash->close($session, $request->user(), (string) $data['counted_cash'], $data['notes'] ?? null);

        return redirect()->route('cash-register.show', $closed)->with('success', "Session {$closed->reference_no} closed.");
    }

    private function present(CashSession $s): array
    {
        return [
            'id' => $s->id, 'reference_no' => $s->reference_no, 'status' => $s->status, 'register' => $s->register?->name, 'cashier' => $s->cashier?->name,
            'opened_at' => $s->opened_at?->toIso8601String(), 'closed_at' => $s->closed_at?->toIso8601String(), 'opening_float' => $s->opening_float,
            'expected_cash' => $s->expected_cash, 'counted_cash' => $s->counted_cash, 'difference' => $s->difference,
        ];
    }
}
