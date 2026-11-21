<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Customer;
use App\Models\JournalLine;
use App\Models\Stock;
use App\Models\Supplier;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only financial statements. Everything is summed from the immutable journal lines, never from stored balances. */
class AccountingReportService
{
    /**
     * Debit and credit totals per account between two dates (either may be null).
     *
     * @return Collection<int, array{debit:BigDecimal, credit:BigDecimal}> keyed by account id
     */
    public function totals(?string $from, ?string $to, ?int $branchId = null): Collection
    {
        $rows = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->when($from, fn ($q) => $q->where('journal_entries.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('journal_entries.entry_date', '<=', $to))
            ->when($branchId, fn ($q) => $q->where('journal_entries.branch_id', $branchId))
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id AS account_id, SUM(journal_lines.debit) AS debit, SUM(journal_lines.credit) AS credit')
            ->get();

        return $rows->mapWithKeys(fn ($r) => [(int) $r->account_id => [
            'debit' => BigDecimal::of((string) $r->debit)->toScale(4), 'credit' => BigDecimal::of((string) $r->credit)->toScale(4),
        ]]);
    }

    /** Balance of an account in its natural direction (assets/expenses: debit - credit; the rest: credit - debit). */
    public function natural(Account $account, array $total): BigDecimal
    {
        return $account->isDebitNormal() ? $total['debit']->minus($total['credit']) : $total['credit']->minus($total['debit']);
    }

    public function accountsWithBalances(?string $asOf = null): array
    {
        $totals = $this->totals(null, $asOf);
        $zero = ['debit' => BigDecimal::zero(), 'credit' => BigDecimal::zero()];

        return Account::query()->orderBy('code')->get()->map(fn (Account $a) => [
            'id' => $a->id, 'parent_id' => $a->parent_id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'system_key' => $a->system_key,
            'description' => $a->description, 'is_system' => $a->is_system, 'is_active' => $a->is_active,
            'balance' => (string) $this->natural($a, $totals->get($a->id, $zero))->toScale(4),
        ])->all();
    }

    /** @return array{rows:array, total_debit:string, total_credit:string, balanced:bool, as_of:?string} */
    public function trialBalance(?string $asOf, ?int $branchId = null): array
    {
        $totals = $this->totals(null, $asOf, $branchId);
        $debit = BigDecimal::zero();
        $credit = BigDecimal::zero();
        $rows = [];

        foreach (Account::query()->orderBy('code')->get() as $a) {
            $t = $totals->get($a->id);
            if (! $t) {
                continue;
            }
            $net = $t['debit']->minus($t['credit']);
            if ($net->isZero()) {
                continue;
            }
            $d = $net->isPositive() ? $net : BigDecimal::zero();
            $c = $net->isNegative() ? $net->negated() : BigDecimal::zero();
            $debit = $debit->plus($d);
            $credit = $credit->plus($c);
            $rows[] = ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'debit' => (string) $d->toScale(4), 'credit' => (string) $c->toScale(4)];
        }

        return [
            'rows' => $rows, 'total_debit' => (string) $debit->toScale(4), 'total_credit' => (string) $credit->toScale(4),
            'balanced' => $debit->isEqualTo($credit), 'as_of' => $asOf,
        ];
    }

    /**
     * General ledger of one account: balance brought forward, then the lines in date order with a running balance
     * (natural direction of the account).
     */
    public function ledger(Account $account, ?string $from, ?string $to, ?int $branchId, int $page, int $perPage = 50): array
    {
        $base = fn (): Builder => JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->when($branchId, fn ($q) => $q->where('journal_entries.branch_id', $branchId));

        $sign = fn (BigDecimal $d, BigDecimal $c) => $account->isDebitNormal() ? $d->minus($c) : $c->minus($d);
        $sum = function (Builder $q) {
            $r = $q->selectRaw('COALESCE(SUM(journal_lines.debit), 0) AS d, COALESCE(SUM(journal_lines.credit), 0) AS c')->first();

            return [BigDecimal::of((string) $r->d), BigDecimal::of((string) $r->c)];
        };

        $opening = $from ? $sign(...$sum($base()->where('journal_entries.entry_date', '<', $from))) : BigDecimal::zero();

        $ordered = fn (): Builder => $base()
            ->when($from, fn ($q) => $q->where('journal_entries.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('journal_entries.entry_date', '<=', $to))
            ->orderBy('journal_entries.entry_date')->orderBy('journal_entries.id')->orderBy('journal_lines.id');

        $total = $ordered()->count();
        $offset = max(0, $page - 1) * $perPage;

        $carried = $opening;
        if ($offset > 0) {
            $before = DB::query()->fromSub($ordered()->select('journal_lines.debit', 'journal_lines.credit')->limit($offset), 't')
                ->selectRaw('COALESCE(SUM(debit), 0) AS d, COALESCE(SUM(credit), 0) AS c')->first();
            $carried = $opening->plus($sign(BigDecimal::of((string) $before->d), BigDecimal::of((string) $before->c)));
        }

        $running = $carried;
        $lines = $ordered()->select([
            'journal_lines.id', 'journal_lines.debit', 'journal_lines.credit', 'journal_lines.memo',
            'journal_entries.id AS entry_id', 'journal_entries.entry_no', 'journal_entries.entry_date', 'journal_entries.description', 'journal_entries.kind',
        ])->offset($offset)->limit($perPage)->get()->map(function ($l) use (&$running, $sign) {
            $running = $running->plus($sign(BigDecimal::of((string) $l->debit), BigDecimal::of((string) $l->credit)));

            return [
                'id' => $l->id, 'entry_id' => $l->entry_id, 'entry_no' => $l->entry_no, 'entry_date' => substr((string) $l->entry_date, 0, 10),
                'description' => $l->description, 'memo' => $l->memo, 'kind' => $l->kind,
                'debit' => (string) BigDecimal::of((string) $l->debit)->toScale(4), 'credit' => (string) BigDecimal::of((string) $l->credit)->toScale(4),
                'balance' => (string) $running->toScale(4),
            ];
        })->all();

        [$d, $c] = $sum($ordered()->reorder());

        return [
            'opening' => (string) $opening->toScale(4), 'closing' => (string) $opening->plus($sign($d, $c))->toScale(4),
            'lines' => new LengthAwarePaginator($lines, $total, $perPage, max(1, $page), ['path' => request()->url(), 'query' => request()->query()]),
        ];
    }

    /** @return array{revenue:array, cost_of_sales:array, expenses:array, total_revenue:string, total_cost_of_sales:string, gross_profit:string, total_expenses:string, net_profit:string} */
    public function incomeStatement(string $from, string $to, ?int $branchId = null): array
    {
        $totals = $this->totals($from, $to, $branchId);
        $zero = ['debit' => BigDecimal::zero(), 'credit' => BigDecimal::zero()];
        $groups = ['revenue' => [], 'cost_of_sales' => [], 'expenses' => []];
        $sums = ['revenue' => BigDecimal::zero(), 'cost_of_sales' => BigDecimal::zero(), 'expenses' => BigDecimal::zero()];

        foreach (Account::query()->whereIn('type', ['revenue', 'expense'])->orderBy('code')->get() as $a) {
            $t = $totals->get($a->id);
            if (! $t) {
                continue;
            }
            $amount = $this->natural($a, $t ?? $zero);
            if ($amount->isZero()) {
                continue;
            }
            $group = $a->type === 'revenue' ? 'revenue' : ($a->system_key === 'cogs' ? 'cost_of_sales' : 'expenses');
            $groups[$group][] = ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => (string) $amount->toScale(4)];
            $sums[$group] = $sums[$group]->plus($amount);
        }

        $gross = $sums['revenue']->minus($sums['cost_of_sales']);

        return [
            'revenue' => $groups['revenue'], 'cost_of_sales' => $groups['cost_of_sales'], 'expenses' => $groups['expenses'],
            'total_revenue' => (string) $sums['revenue']->toScale(4), 'total_cost_of_sales' => (string) $sums['cost_of_sales']->toScale(4),
            'gross_profit' => (string) $gross->toScale(4), 'total_expenses' => (string) $sums['expenses']->toScale(4),
            'net_profit' => (string) $gross->minus($sums['expenses'])->toScale(4),
        ];
    }

    /** Position at the end of a day. Profit to date is shown as its own equity line (there is no year-end close). */
    public function balanceSheet(string $asOf): array
    {
        $totals = $this->totals(null, $asOf);
        $sections = ['asset' => [], 'liability' => [], 'equity' => []];
        $sums = ['asset' => BigDecimal::zero(), 'liability' => BigDecimal::zero(), 'equity' => BigDecimal::zero()];
        $profit = BigDecimal::zero();

        foreach (Account::query()->orderBy('code')->get() as $a) {
            $t = $totals->get($a->id);
            if (! $t) {
                continue;
            }
            $amount = $this->natural($a, $t);
            if ($a->type === 'revenue') {
                $profit = $profit->plus($amount);
            } elseif ($a->type === 'expense') {
                $profit = $profit->minus($amount);
            } elseif (! $amount->isZero()) {
                $sections[$a->type][] = ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => (string) $amount->toScale(4)];
                $sums[$a->type] = $sums[$a->type]->plus($amount);
            }
        }

        $equity = $sums['equity']->plus($profit);

        return [
            'as_of' => $asOf, 'assets' => $sections['asset'], 'liabilities' => $sections['liability'], 'equity' => $sections['equity'],
            'profit_to_date' => (string) $profit->toScale(4), 'total_assets' => (string) $sums['asset']->toScale(4),
            'total_liabilities' => (string) $sums['liability']->toScale(4), 'total_equity' => (string) $equity->toScale(4),
            'balanced' => $sums['asset']->isEqualTo($sums['liability']->plus($equity)),
        ];
    }

    /**
     * Do the books agree with the subsidiary records? Receivable vs customer balances, payable vs supplier
     * balances, inventory vs stock valuation. Any difference points at something posted outside the services.
     *
     * @return array<int, array{label:string, books:string, records:string, difference:string, ok:bool}>
     */
    public function reconciliation(ChartOfAccounts $chart): array
    {
        $totals = $this->totals(null, null);
        $book = function (string $key, bool $debitNormal) use ($chart, $totals) {
            $t = $totals->get($chart->id($key));

            return $t ? ($debitNormal ? $t['debit']->minus($t['credit']) : $t['credit']->minus($t['debit'])) : BigDecimal::zero();
        };

        $checks = [
            ['Accounts receivable vs customer balances', $book('receivable', true), BigDecimal::of((string) Customer::query()->withTrashed()->sum('balance'))],
            ['Accounts payable vs supplier balances', $book('payable', false), BigDecimal::of((string) Supplier::query()->withTrashed()->sum('balance'))],
            ['Inventory vs stock valuation', $book('inventory', true), BigDecimal::of((string) Stock::query()->sum('total_value'))],
        ];

        return array_map(fn ($c) => [
            'label' => $c[0], 'books' => (string) $c[1]->toScale(4), 'records' => (string) $c[2]->toScale(4),
            'difference' => (string) $c[1]->minus($c[2])->toScale(4), 'ok' => $c[1]->isEqualTo($c[2]),
        ], $checks);
    }
}
