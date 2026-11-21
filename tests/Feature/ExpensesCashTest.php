<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReportService;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Cash\CashRegisterService;
use App\Services\Cash\ExpenseService;
use App\Services\Inventory\AdjustmentService;
use App\Services\Sales\SaleReturnService;
use App\Services\Sales\SaleService;
use App\Services\Settings\SettingsService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ExpensesCashTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private $business;

    private $owner;

    private $cashier;

    private $manager;

    private $accountant;

    private Branch $main;

    private int $wh;

    private CashRegister $till;

    private int $rentCategory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
        [$this->business, $this->owner] = $this->makeBusiness();
        $this->main = $this->in(fn () => Branch::where('is_main', true)->first());
        $this->cashier = $this->makeStaff($this->business, 'Cashier', [$this->main], 'cashier-a@test.com');
        $this->manager = $this->makeStaff($this->business, 'Branch Manager', [$this->main]);
        $this->accountant = $this->makeStaff($this->business, 'Accountant', [$this->main]);
        $this->actingAs($this->owner);
        $this->wh = $this->in(fn () => Warehouse::where('is_default', true)->value('id'));
        $this->till = $this->in(fn () => CashRegister::create(['branch_id' => $this->main->id, 'name' => 'Till 1', 'is_active' => true]));
        $this->rentCategory = $this->in(function () {
            app(ChartOfAccounts::class)->ensure();

            return ExpenseCategory::create(['name' => 'Rent', 'account_id' => app(ChartOfAccounts::class)->id('expense_rent'), 'is_active' => true])->id;
        });
    }

    private function in(callable $fn, $business = null)
    {
        return app(TenantContext::class)->runAs(($business ?? $this->business)->id, $fn);
    }

    private function open($user = null, string $float = '1000', ?CashRegister $register = null): CashSession
    {
        return $this->in(fn () => app(CashRegisterService::class)->openSession($register ?? $this->till, $user ?? $this->cashier, $float));
    }

    private function product(): Product
    {
        static $n = 0;
        $n++;
        $p = $this->in(fn () => Product::create([
            'name' => "Item $n", 'sku' => "C-$n", 'unit_id' => Unit::where('short_name', 'pcs')->value('id'),
            'purchase_price' => '60', 'selling_price' => '100', 'track_inventory' => true,
        ]));
        $this->in(fn () => app(AdjustmentService::class)->create([
            'warehouse_id' => $this->wh, 'direction' => 'in', 'reason' => 'opening_stock', 'adjusted_at' => '2026-11-20',
            'items' => [['product_id' => $p->id, 'quantity' => '50', 'unit_cost' => '60']],
        ], $this->owner));

        return $p;
    }

    private function sell(Product $p, string $qty, array $payments, $user = null, array $o = []): Sale
    {
        return $this->in(fn () => app(SaleService::class)->complete(array_merge([
            'warehouse_id' => $this->wh, 'items' => [['product_id' => $p->id, 'quantity' => $qty]], 'payments' => $payments,
        ], $o), $user ?? $this->cashier));
    }

    private function expense(array $o = [], $user = null): Expense
    {
        return $this->in(fn () => app(ExpenseService::class)->create(array_merge([
            'branch_id' => $this->main->id, 'category_id' => $this->rentCategory, 'expense_date' => '2026-11-20',
            'amount' => '500', 'method' => 'cash', 'description' => 'November rent',
        ], $o), $user ?? $this->owner));
    }

    private function drawer(CashSession $s): string
    {
        return $this->in(fn () => (string) app(CashRegisterService::class)->expected($s->fresh()));
    }

    private function bal(string $key): string
    {
        return $this->in(function () use ($key) {
            $account = Account::find(app(ChartOfAccounts::class)->id($key));
            $reports = app(AccountingReportService::class);
            $t = $reports->totals(null, null)->get($account->id);

            return $t ? (string) $reports->natural($account, $t)->toScale(4) : '0.0000';
        });
    }

    private function rejected(callable $fn, ?string $key = null): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            if ($key) {
                $this->assertArrayHasKey($key, $e->errors());
            }
            $this->assertTrue(true);

            return;
        }
        $this->fail('Expected a validation error.');
    }

    public function test_a_session_opens_with_a_float_and_only_one_per_register_and_per_cashier(): void
    {
        $session = $this->open(null, '1000');
        $this->assertSame('SES-000001', $session->reference_no);
        $this->assertSame('open', $session->status);
        $this->assertSame('1000.0000', $this->drawer($session));

        $this->rejected(fn () => $this->open($this->manager), 'register');            // the till is taken
        $second = $this->in(fn () => CashRegister::create(['branch_id' => $this->main->id, 'name' => 'Till 2', 'is_active' => true]));
        $this->rejected(fn () => $this->open($this->cashier, '0', $second), 'register'); // the cashier already has a shift

        $off = $this->in(fn () => CashRegister::create(['branch_id' => $this->main->id, 'name' => 'Till 3', 'is_active' => false]));
        $this->rejected(fn () => $this->open($this->manager, '0', $off), 'register');

        $branchB = $this->makeBranch($this->business, 'BR2');
        $far = $this->in(fn () => CashRegister::create(['branch_id' => $branchB->id, 'name' => 'Far till', 'is_active' => true]));
        $this->rejected(fn () => $this->open($this->manager, '0', $far), 'register'); // not the manager's branch
    }

    public function test_cash_sales_land_on_the_cashiers_session_net_of_change(): void
    {
        $p = $this->product();
        $session = $this->open(null, '1000');
        $this->sell($p, '2', [['method' => 'cash', 'amount' => '300']]);                  // total 200, change 100
        $this->sell($p, '1', [['method' => 'card', 'amount' => '100']]);                  // card never touches the drawer
        $this->sell($p, '1', [['method' => 'card', 'amount' => '40'], ['method' => 'cash', 'amount' => '60']]);

        $this->assertSame('1260.0000', $this->drawer($session)); // 1000 + 200 + 60
        $this->assertSame(['sale', 'sale'], $this->in(fn () => CashMovement::orderBy('id')->pluck('type')->all()));
    }

    public function test_sales_without_a_session_still_work_unless_the_business_requires_one(): void
    {
        $p = $this->product();
        $this->sell($p, '1', [['method' => 'cash', 'amount' => '100']]);
        $this->assertSame(0, $this->in(fn () => CashMovement::count()));

        $this->in(fn () => app(SettingsService::class)->setMany(['sales.require_open_register' => true]));
        $before = $this->in(fn () => Sale::count());
        $this->rejected(fn () => $this->sell($p, '1', [['method' => 'cash', 'amount' => '100']]), 'payments');
        $this->assertSame($before, $this->in(fn () => Sale::count()));
        $this->sell($p, '1', [['method' => 'card', 'amount' => '100']]); // no cash, no session needed

        $this->open();
        $this->sell($p, '1', [['method' => 'cash', 'amount' => '100']]);
        $this->assertSame(1, $this->in(fn () => CashMovement::count()));
    }

    public function test_cash_refunds_and_voids_take_cash_out_of_the_drawer(): void
    {
        $p = $this->product();
        $session = $this->open(null, '0');
        $sale = $this->sell($p, '3', [['method' => 'cash', 'amount' => '300']]);
        $this->assertSame('300.0000', $this->drawer($session));

        $this->in(fn () => app(SaleReturnService::class)->create($sale, ['refund_method' => 'cash', 'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1']]], $this->cashier));
        $this->assertSame('200.0000', $this->drawer($session));

        $card = $this->sell($p, '1', [['method' => 'card', 'amount' => '100']]);
        $this->in(fn () => app(SaleService::class)->void($card, 'Wrong item', $this->cashier)); // a card sale gives no cash back
        $this->assertSame('200.0000', $this->drawer($session));

        $cash = $this->sell($p, '2', [['method' => 'cash', 'amount' => '200']]);
        $this->assertSame('400.0000', $this->drawer($session));
        $this->in(fn () => app(SaleService::class)->void($cash, 'Wrong item', $this->cashier));
        $this->assertSame('200.0000', $this->drawer($session));
    }

    public function test_an_approvers_expense_is_posted_at_once_and_paid_from_their_till(): void
    {
        $session = $this->open($this->accountant, '1000');
        $expense = $this->expense([], $this->accountant);

        $this->assertSame('EXP-000001', $expense->reference_no);
        $this->assertSame('approved', $expense->status);
        $this->assertSame('500.0000', $this->bal('expense_rent'));
        $this->assertSame('-500.0000', $this->bal('cash'));
        $this->assertSame('500.0000', $this->drawer($session));

        $entry = $this->in(fn () => JournalEntry::where('source_type', 'expense')->where('source_id', $expense->id)->first());
        $this->assertSame($entry->id, $this->in(fn () => Expense::find($expense->id)->journal_entry_id));
        $this->assertSame($this->main->id, $entry->branch_id);

        $this->expense(['method' => 'bank', 'amount' => '75', 'description' => 'Courier'], $this->accountant);
        $this->assertSame('-75.0000', $this->bal('bank')); // money out of the bank account
        $this->assertSame('500.0000', $this->drawer($session)); // bank payment leaves the drawer alone
    }

    public function test_other_staff_wait_for_approval_and_nothing_moves_until_then(): void
    {
        $session = $this->open($this->accountant, '1000');
        $pending = $this->expense([], $this->manager);

        $this->assertSame('pending', $pending->status);
        $this->assertSame('0.0000', $this->bal('expense_rent'));
        $this->assertSame('1000.0000', $this->drawer($session));
        $this->assertSame(0, $this->in(fn () => JournalEntry::where('source_type', 'expense')->count()));

        $this->in(fn () => app(ExpenseService::class)->update($pending, [
            'branch_id' => $this->main->id, 'category_id' => $this->rentCategory, 'expense_date' => '2026-11-20', 'amount' => '450', 'method' => 'cash', 'description' => 'Rent (corrected)',
        ], $this->manager));
        $this->in(fn () => app(ExpenseService::class)->approve($pending, $this->accountant));

        $this->assertSame('450.0000', $this->bal('expense_rent'));
        $this->assertSame('550.0000', $this->drawer($session));
        $this->rejected(fn () => $this->in(fn () => app(ExpenseService::class)->approve($pending, $this->accountant)), 'status');
        $this->rejected(fn () => $this->in(fn () => app(ExpenseService::class)->update($pending, [
            'branch_id' => $this->main->id, 'category_id' => $this->rentCategory, 'expense_date' => '2026-11-20', 'amount' => '1', 'method' => 'cash', 'description' => 'Late edit',
        ], $this->manager)), 'status'); // approved expenses are never edited
    }

    public function test_rejecting_and_voiding_expenses(): void
    {
        $rejected = $this->expense([], $this->manager);
        $this->in(fn () => app(ExpenseService::class)->reject($rejected, 'Not business related', $this->accountant));
        $this->assertSame('rejected', $this->in(fn () => Expense::find($rejected->id)->status));
        $this->assertSame('0.0000', $this->bal('expense_rent'));
        $this->rejected(fn () => $this->in(fn () => app(ExpenseService::class)->void($rejected, 'x', $this->accountant)), 'status');

        $session = $this->open($this->accountant, '1000');
        $approved = $this->expense([], $this->accountant);
        $this->assertSame('500.0000', $this->drawer($session));
        $this->in(fn () => app(ExpenseService::class)->void($approved, 'Duplicate', $this->accountant));

        $this->assertSame('voided', $this->in(fn () => Expense::find($approved->id)->status));
        $this->assertSame('0.0000', $this->bal('expense_rent'));
        $this->assertSame('0.0000', $this->bal('cash'));
        $this->assertSame('1000.0000', $this->drawer($session));
        $this->rejected(fn () => $this->in(fn () => app(ExpenseService::class)->void($approved, 'Again', $this->accountant)), 'status');
    }

    public function test_expense_input_rules(): void
    {
        $inactive = $this->in(fn () => ExpenseCategory::create(['name' => 'Old', 'account_id' => app(ChartOfAccounts::class)->id('expense_general'), 'is_active' => false])->id);
        $this->rejected(fn () => $this->expense(['category_id' => $inactive]), 'category_id');

        $branchB = $this->makeBranch($this->business, 'BR2');
        $this->rejected(fn () => $this->expense(['branch_id' => $branchB->id], $this->manager), 'branch_id'); // not the manager's branch
        $this->assertSame('approved', $this->expense(['branch_id' => $branchB->id])->status);                 // the owner works everywhere
    }

    public function test_pay_ins_and_pay_outs_move_cash_against_owner_or_bank(): void
    {
        $session = $this->open(null, '100');
        $svc = app(CashRegisterService::class);

        $this->in(fn () => $svc->payInOut($session, $this->cashier, 'in', 'owner', '400', 'Capital'));
        $this->assertSame('500.0000', $this->drawer($session));
        $this->assertSame('400.0000', $this->bal('cash'));
        $this->assertSame('400.0000', $this->bal('equity'));

        $this->in(fn () => $svc->payInOut($session, $this->cashier, 'out', 'bank', '300', 'Bank deposit'));
        $this->assertSame('200.0000', $this->drawer($session));
        $this->assertSame('300.0000', $this->bal('bank'));
        $this->assertSame('100.0000', $this->bal('cash'));

        $this->rejected(fn () => $this->in(fn () => $svc->payInOut($session, $this->cashier, 'out', 'owner', '201', 'Too much')), 'amount');
        $this->rejected(fn () => $this->in(fn () => $svc->payInOut($session, $this->cashier, 'in', 'owner', '0', 'Nothing')), 'amount');
        $this->rejected(fn () => $this->in(fn () => $svc->payInOut($session, $this->manager, 'in', 'owner', '5', 'Not mine')), 'session'); // a branch manager does not see every till
    }

    public function test_closing_books_a_shortage_an_overage_or_nothing(): void
    {
        $p = $this->product();
        $svc = app(CashRegisterService::class);

        $short = $this->open(null, '1000');
        $this->sell($p, '1', [['method' => 'cash', 'amount' => '100']]);
        $closed = $this->in(fn () => $svc->close($short, $this->cashier, '1090', 'End of day'));
        $this->assertSame('closed', $closed->status);
        $this->assertSame('1100.0000', $closed->expected_cash);
        $this->assertSame('1090.0000', $closed->counted_cash);
        $this->assertSame('-10.0000', $closed->difference);
        $this->assertSame('10.0000', $this->bal('cash_difference'));
        $this->assertSame('90.0000', $this->bal('cash'));   // 100 sale - 10 short

        $over = $this->open(null, '0');
        $this->in(fn () => $svc->close($over, $this->cashier, '25'));
        $this->assertSame('-15.0000', $this->bal('cash_difference')); // 10 short then 25 over: a net gain of 15
        $this->assertSame('115.0000', $this->bal('cash'));

        $exact = $this->open(null, '50');
        $before = $this->in(fn () => JournalEntry::count());
        $this->in(fn () => $svc->close($exact, $this->cashier, '50'));
        $this->assertSame($before, $this->in(fn () => JournalEntry::count()));

        $this->rejected(fn () => $this->in(fn () => $svc->close($exact, $this->cashier, '50')), 'session'); // already closed
        $this->rejected(fn () => $this->in(fn () => $svc->payInOut($exact, $this->cashier, 'in', 'owner', '5', 'Late')), 'session');
        $this->rejected(fn () => $this->in(fn () => $svc->close($this->open(), $this->cashier, '-1')), 'counted_cash');
    }

    public function test_a_closed_shift_stops_collecting_cash_and_a_full_access_user_can_close_anothers_session(): void
    {
        $p = $this->product();
        $session = $this->open(null, '100');
        $this->in(fn () => app(CashRegisterService::class)->close($session, $this->owner, '100')); // the owner sees every till
        $this->sell($p, '1', [['method' => 'cash', 'amount' => '100']]);
        $this->assertSame(0, $this->in(fn () => CashMovement::count())); // no open session, so nothing is attributed
    }

    public function test_cash_movements_are_immutable(): void
    {
        $session = $this->open();
        $movement = $this->in(fn () => app(CashRegisterService::class)->payInOut($session, $this->cashier, 'in', 'owner', '10', 'Float top-up'));
        foreach ([fn () => $movement->update(['amount' => 1]), fn () => $movement->delete()] as $try) {
            try {
                $this->in($try);
                $this->fail('Expected an immutability error.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_pages_and_permissions_over_http(): void
    {
        $keeper = $this->makeStaff($this->business, 'Storekeeper', [$this->main]);
        $session = $this->open();

        foreach (['/cash-register', "/cash-register/sessions/{$session->id}"] as $url) {
            $this->actingAs($this->cashier)->get($url)->assertOk();
            $this->actingAs($keeper)->get($url)->assertForbidden();
        }
        $this->actingAs($this->cashier)->get('/expenses')->assertForbidden();
        $this->actingAs($this->manager)->get('/expenses')->assertOk();
        $this->actingAs($this->accountant)->get('/expenses/categories')->assertOk();
        $this->actingAs($this->manager)->get('/expenses/categories')->assertForbidden();

        // only settings.manage creates registers
        $this->actingAs($this->cashier)->post('/cash-register/registers', ['name' => 'Till 9', 'branch_id' => $this->main->id])->assertForbidden();
        $this->actingAs($this->owner)->post('/cash-register/registers', ['name' => 'Till 9', 'branch_id' => $this->main->id])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post('/cash-register/registers', ['name' => 'Till 9', 'branch_id' => $this->main->id])->assertSessionHasErrors('name');

        // another cashier cannot open or read the first cashier's session
        $other = $this->makeStaff($this->business, 'Cashier', [$this->main], 'cashier-b@test.com');
        $this->actingAs($other)->get("/cash-register/sessions/{$session->id}")->assertNotFound();
        $this->actingAs($other)->post("/cash-register/sessions/{$session->id}/close", ['counted_cash' => '0'])->assertSessionHasErrors('session');
    }

    public function test_the_expense_workflow_over_http(): void
    {
        $payload = ['branch_id' => $this->main->id, 'category_id' => $this->rentCategory, 'expense_date' => '2026-11-20', 'amount' => '120.50', 'method' => 'bank', 'description' => 'Internet'];

        $this->actingAs($this->manager)->post('/expenses', $payload)->assertSessionHasNoErrors();
        $expense = $this->in(fn () => Expense::first());
        $this->assertSame('pending', $expense->status);
        $this->actingAs($this->manager)->post("/expenses/{$expense->id}/approve")->assertForbidden();
        $this->actingAs($this->manager)->post('/expenses', array_merge($payload, ['amount' => '12.12345']))->assertSessionHasErrors('amount');
        $this->actingAs($this->manager)->post('/expenses', array_merge($payload, ['amount' => '0']))->assertSessionHasErrors('amount');

        $this->actingAs($this->accountant)->post("/expenses/{$expense->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('approved', $this->in(fn () => Expense::find($expense->id)->status));
        $this->assertSame('120.5000', $this->bal('expense_rent'));
        $this->actingAs($this->accountant)->post("/expenses/{$expense->id}/void", [])->assertSessionHasErrors('reason');
        $this->actingAs($this->accountant)->post("/expenses/{$expense->id}/void", ['reason' => 'Billed twice'])->assertSessionHasNoErrors();
        $this->assertSame('0.0000', $this->bal('expense_rent'));

        // categories: unique names, expense accounts only, no delete once used
        $cashAccount = $this->in(fn () => app(ChartOfAccounts::class)->id('cash'));
        $this->actingAs($this->accountant)->post('/expenses/categories', ['name' => 'Rent', 'account_id' => $this->in(fn () => app(ChartOfAccounts::class)->id('expense_rent'))])->assertSessionHasErrors('name');
        $this->actingAs($this->accountant)->post('/expenses/categories', ['name' => 'Odd', 'account_id' => $cashAccount])->assertSessionHasErrors('account_id');
        $this->actingAs($this->accountant)->delete("/expenses/categories/{$this->rentCategory}")->assertSessionHasErrors('category');
    }

    public function test_isolation_between_businesses(): void
    {
        [$other, $otherOwner] = $this->makeBusiness('Shop B');
        $theirs = $this->in(function () use ($otherOwner) {
            $branch = Branch::where('is_main', true)->first();
            app(ChartOfAccounts::class)->ensure();
            $cat = ExpenseCategory::create(['name' => 'Rent', 'account_id' => app(ChartOfAccounts::class)->id('expense_rent'), 'is_active' => true]);

            return app(ExpenseService::class)->create(['branch_id' => $branch->id, 'category_id' => $cat->id, 'expense_date' => '2026-11-20', 'amount' => '9', 'method' => 'bank', 'description' => 'Theirs'], $otherOwner);
        }, $other);

        $this->actingAs($this->accountant)->post("/expenses/{$theirs->id}/void", ['reason' => 'x'])->assertNotFound();
        $this->assertSame(0, $this->in(fn () => Expense::count()));
    }
}
