<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Services\Accounting\AccountingReportService;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\PostingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountingReportController extends Controller
{
    public function __construct(private AccountingReportService $reports, private ChartOfAccounts $chart, private PostingService $posting) {}

    private function branches()
    {
        return Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    private function dates(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'as_of' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return [$request->input('branch_id') ? (int) $request->input('branch_id') : null, $this->posting->today()];
    }

    public function trialBalance(Request $request): Response
    {
        [$branch, $today] = $this->dates($request);
        $this->chart->ensure();
        $asOf = $request->input('as_of', $today);

        return Inertia::render('Accounting/TrialBalance', [
            'report' => $this->reports->trialBalance($asOf, $branch),
            'reconciliation' => $branch ? null : $this->reports->reconciliation($this->chart), // control accounts are business-wide
            'filters' => ['as_of' => $asOf, 'branch_id' => $branch], 'branches' => $this->branches(),
        ]);
    }

    public function ledger(Request $request, Account $account): Response
    {
        [$branch] = $this->dates($request);
        $data = $this->reports->ledger($account, $request->input('from'), $request->input('to'), $branch, (int) $request->input('page', 1));

        return Inertia::render('Accounting/Ledger', [
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type],
            'opening' => $data['opening'], 'closing' => $data['closing'], 'lines' => $data['lines'],
            'filters' => ['from' => $request->input('from'), 'to' => $request->input('to'), 'branch_id' => $branch], 'branches' => $this->branches(),
        ]);
    }

    public function incomeStatement(Request $request): Response
    {
        [$branch, $today] = $this->dates($request);
        $from = $request->input('from', substr($today, 0, 8).'01');
        $to = $request->input('to', $today);

        return Inertia::render('Accounting/IncomeStatement', [
            'report' => $this->reports->incomeStatement($from, $to, $branch),
            'filters' => ['from' => $from, 'to' => $to, 'branch_id' => $branch], 'branches' => $this->branches(),
        ]);
    }

    public function balanceSheet(Request $request): Response
    {
        [, $today] = $this->dates($request);
        $asOf = $request->input('as_of', $today);

        return Inertia::render('Accounting/BalanceSheet', ['report' => $this->reports->balanceSheet($asOf), 'filters' => ['as_of' => $asOf]]);
    }
}
