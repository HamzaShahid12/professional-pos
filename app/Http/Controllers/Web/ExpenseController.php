<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExpenseCategoryRequest;
use App\Http\Requests\ExpenseRequest;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\PostingService;
use App\Services\Cash\ExpenseService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function __construct(private ExpenseService $expenses, private PostingService $posting, private ChartOfAccounts $chart) {}

    public function index(Request $request): Response
    {
        $f = $request->only('status', 'category_id', 'branch_id', 'from', 'to', 'search');
        $user = $request->user();
        $branchIds = $user->accessibleBranchIds(app(TenantContext::class)->businessId());

        $rows = Expense::query()->with(['category:id,name', 'branch:id,name', 'author:id,name'])
            ->whereIn('branch_id', $branchIds)
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['category_id'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
            ->when($f['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('expense_date', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('expense_date', '<=', $v))
            ->when($f['search'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('reference_no', 'like', '%'.addcslashes($t, '%_\\').'%')->orWhere('description', 'like', '%'.addcslashes($t, '%_\\').'%')->orWhere('payee', 'like', '%'.addcslashes($t, '%_\\').'%')))
            ->orderByDesc('expense_date')->orderByDesc('id')->paginate(20)->withQueryString()
            ->through(fn ($e) => [
                'id' => $e->id, 'reference_no' => $e->reference_no, 'expense_date' => $e->expense_date?->toDateString(), 'category_id' => $e->category_id,
                'category' => $e->category?->name, 'branch_id' => $e->branch_id, 'branch' => $e->branch?->name, 'amount' => $e->amount, 'method' => $e->method,
                'payee' => $e->payee, 'description' => $e->description, 'status' => $e->status, 'user' => $e->author?->name,
                'decision_note' => $e->decision_note, 'mine' => $e->created_by === $user->id,
            ]);

        return Inertia::render('Expenses/Index', [
            'expenses' => $rows, 'filters' => $f, 'methods' => ExpenseService::METHODS, 'today' => $this->posting->today(),
            'categories' => ExpenseCategory::query()->orderBy('name')->get(['id', 'name', 'is_active']),
            'branches' => Branch::query()->whereIn('id', $branchIds)->orderBy('name')->get(['id', 'name']),
            'can_approve' => $user->can('expenses.approve'),
        ]);
    }

    public function store(ExpenseRequest $request): RedirectResponse
    {
        $expense = $this->expenses->create($request->validated(), $request->user());

        return back()->with('success', $expense->status === 'approved' ? "Expense {$expense->reference_no} recorded." : "Expense {$expense->reference_no} sent for approval.");
    }

    public function update(ExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $this->expenses->update($expense, $request->validated(), $request->user());

        return back()->with('success', 'Expense updated.');
    }

    public function approve(Request $request, Expense $expense): RedirectResponse
    {
        $this->expenses->approve($expense, $request->user());

        return back()->with('success', "Expense {$expense->reference_no} approved.");
    }

    public function reject(Request $request, Expense $expense): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->expenses->reject($expense, $data['reason'], $request->user());

        return back()->with('success', "Expense {$expense->reference_no} rejected.");
    }

    public function void(Request $request, Expense $expense): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->expenses->void($expense, $data['reason'], $request->user());

        return back()->with('success', "Expense {$expense->reference_no} voided.");
    }

    // ---- categories ------------------------------------------------------------------------------

    public function categories(): Response
    {
        $this->chart->ensure();

        return Inertia::render('Expenses/Categories', [
            'categories' => ExpenseCategory::query()->with('account:id,code,name')->orderBy('name')->get()->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'account_id' => $c->account_id, 'account' => $c->account ? $c->account->code.' · '.$c->account->name : null, 'is_active' => $c->is_active,
            ]),
            'accounts' => Account::query()->where('type', 'expense')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function storeCategory(ExpenseCategoryRequest $request): RedirectResponse
    {
        ExpenseCategory::create($request->validated() + ['is_active' => true]);

        return back()->with('success', 'Category created.');
    }

    public function updateCategory(ExpenseCategoryRequest $request, ExpenseCategory $category): RedirectResponse
    {
        $category->update($request->validated());

        return back()->with('success', 'Category updated.');
    }

    public function destroyCategory(Request $request, ExpenseCategory $category): RedirectResponse
    {
        abort_unless($request->user()->can('expenses.approve'), 403);
        if (Expense::query()->where('category_id', $category->id)->exists()) {
            throw ValidationException::withMessages(['category' => 'This category has expenses. Deactivate it instead.']);
        }
        $category->delete();

        return back()->with('success', 'Category deleted.');
    }
}
