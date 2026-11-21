<?php

namespace App\Services\Cash;

use App\Models\ActivityLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Services\DocumentNumberService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Expenses need an approver. Someone with `expenses.approve` records an approved expense straight away; everybody
 * else records a pending one. Approval is what posts the journal and, for cash, takes money out of the approver's
 * till. Approved expenses are never edited: they are voided (which reverses the books).
 */
class ExpenseService
{
    public const METHODS = ['cash', 'bank', 'card', 'cheque', 'other'];

    public function __construct(private DocumentNumberService $numbers, private PostingService $posting, private CashRegisterService $cash) {}

    public function create(array $data, User $user): Expense
    {
        return DB::transaction(function () use ($data, $user) {
            $this->assertBranch($user, (int) $data['branch_id']);
            $category = $this->category((int) $data['category_id']);

            $expense = Expense::create([
                'branch_id' => (int) $data['branch_id'], 'category_id' => $category->id, 'reference_no' => $this->numbers->next('expense', 'EXP'),
                'expense_date' => $data['expense_date'], 'amount' => (string) BigDecimal::of((string) $data['amount'])->toScale(4),
                'method' => $data['method'], 'payee' => $data['payee'] ?? null, 'description' => $data['description'],
                'status' => 'pending', 'created_by' => $user->id,
            ]);
            ActivityLog::record('expense.created', $expense, ['reference_no' => $expense->reference_no, 'amount' => $expense->amount]);

            return $user->can('expenses.approve') ? $this->settle($expense, $user) : $expense;
        });
    }

    /** Only a pending expense can change, by its author or an approver. */
    public function update(Expense $expense, array $data, User $user): Expense
    {
        return DB::transaction(function () use ($expense, $data, $user) {
            $expense = $this->lock($expense, ['pending']);
            if ($expense->created_by !== $user->id && ! $user->can('expenses.approve')) {
                throw ValidationException::withMessages(['expense' => 'Only the person who entered an expense, or an approver, can change it.']);
            }
            $this->assertBranch($user, (int) $data['branch_id']);
            $category = $this->category((int) $data['category_id']);
            $expense->update([
                'branch_id' => (int) $data['branch_id'], 'category_id' => $category->id, 'expense_date' => $data['expense_date'],
                'amount' => (string) BigDecimal::of((string) $data['amount'])->toScale(4), 'method' => $data['method'],
                'payee' => $data['payee'] ?? null, 'description' => $data['description'],
            ]);

            return $expense;
        });
    }

    public function approve(Expense $expense, User $user): Expense
    {
        return DB::transaction(fn () => $this->settle($this->lock($expense, ['pending']), $user));
    }

    public function reject(Expense $expense, string $reason, User $user): Expense
    {
        return DB::transaction(function () use ($expense, $reason, $user) {
            $expense = $this->lock($expense, ['pending']);
            $expense->update(['status' => 'rejected', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $reason]);
            ActivityLog::record('expense.rejected', $expense, ['reference_no' => $expense->reference_no, 'reason' => $reason]);

            return $expense;
        });
    }

    /** Cancel an approved expense: the journal is reversed and a cash expense goes back into the voider's till. */
    public function void(Expense $expense, string $reason, User $user): Expense
    {
        return DB::transaction(function () use ($expense, $reason, $user) {
            $expense = $this->lock($expense, ['approved']);
            $this->posting->voidExpense($expense->reference_no, $expense->id, $this->posting->today());
            if ($expense->method === 'cash') {
                $this->cash->record($user, 'expense', (string) $expense->amount, 'expense', $expense->id, 'Void of '.$expense->reference_no);
            }
            $expense->update(['status' => 'voided', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $reason]);
            ActivityLog::record('expense.voided', $expense, ['reference_no' => $expense->reference_no, 'reason' => $reason]);

            return $expense;
        });
    }

    private function settle(Expense $expense, User $user): Expense
    {
        $category = ExpenseCategory::query()->findOrFail($expense->category_id);
        $branchId = $expense->branch_id;
        $entry = $this->posting->expense(
            $expense->reference_no, $expense->id, $category->account_id, $expense->method, (string) $expense->amount,
            $expense->expense_date->toDateString(), $branchId, $expense->description,
        );
        if ($expense->method === 'cash') {
            $this->cash->record($user, 'expense', (string) BigDecimal::of($expense->amount)->negated(), 'expense', $expense->id, $expense->reference_no);
        }
        $expense->update(['status' => 'approved', 'decided_by' => $user->id, 'decided_at' => now(), 'journal_entry_id' => $entry?->id]);
        ActivityLog::record('expense.approved', $expense, ['reference_no' => $expense->reference_no, 'amount' => (string) $expense->amount]);

        return $expense;
    }

    private function lock(Expense $expense, array $allowed): Expense
    {
        $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
        if (! in_array($locked->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => "This expense is {$locked->status}; it cannot be changed that way."]);
        }

        return $locked;
    }

    private function category(int $id): ExpenseCategory
    {
        $category = ExpenseCategory::query()->where('is_active', true)->find($id);
        if (! $category) {
            throw ValidationException::withMessages(['category_id' => 'Choose an active category.']);
        }

        return $category;
    }

    private function assertBranch(User $user, int $branchId): void
    {
        if (! $user->accessibleBranchIds(app(\App\Support\TenantContext::class)->businessId())->contains($branchId)) {
            throw ValidationException::withMessages(['branch_id' => 'Choose one of your branches.']);
        }
    }
}
