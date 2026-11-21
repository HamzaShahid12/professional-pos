<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountRequest;
use App\Models\Account;
use App\Services\Accounting\AccountingReportService;
use App\Services\Accounting\AccountService;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\JournalService;
use App\Services\Settings\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function __construct(
        private AccountService $accounts, private AccountingReportService $reports, private ChartOfAccounts $chart,
        private JournalService $journal, private SettingsService $settings,
    ) {}

    public function index(Request $request): Response
    {
        $this->chart->ensure(); // businesses created before accounting existed get their chart on first visit

        return Inertia::render('Accounting/Accounts', [
            'accounts' => $this->reports->accountsWithBalances(),
            'types' => Account::TYPES,
            'lock_date' => $this->journal->lockDate(),
            'can_manage' => $request->user()->can('accounting.manage'),
        ]);
    }

    public function store(AccountRequest $request): RedirectResponse
    {
        $account = $this->accounts->create($request->validated());

        return back()->with('success', "Account {$account->code} created.");
    }

    public function update(AccountRequest $request, Account $account): RedirectResponse
    {
        $this->accounts->update($account, $request->validated());

        return back()->with('success', 'Account updated.');
    }

    public function destroy(Request $request, Account $account): RedirectResponse
    {
        abort_unless($request->user()->can('accounting.manage'), 403);
        $this->accounts->delete($account);

        return back()->with('success', 'Account deleted.');
    }

    /** Close the books up to (and including) a date; clear it with an empty value. */
    public function lock(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('accounting.manage'), 403);
        $data = $request->validate(['lock_date' => ['nullable', 'date_format:Y-m-d']]);
        $current = $this->journal->lockDate();
        if ($current && ($data['lock_date'] ?? null) && $data['lock_date'] < $current) {
            return back()->withErrors(['lock_date' => "Closed periods stay closed: choose a date on or after $current, or clear it."]);
        }
        $this->settings->setMany(['accounting.lock_date' => $data['lock_date'] ?? '']);

        return back()->with('success', ($data['lock_date'] ?? null) ? "Books closed up to {$data['lock_date']}." : 'Period lock removed.');
    }
}
