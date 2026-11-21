<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReportService;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\JournalService;
use App\Services\Inventory\AdjustmentService;
use App\Services\Inventory\StockCountService;
use App\Services\Inventory\StockService;
use App\Services\Parties\PartyService;
use App\Services\Parties\PaymentService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\SaleReturnService;
use App\Services\Sales\SaleService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private $business;

    private $owner;

    private int $wh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
        [$this->business, $this->owner] = $this->makeBusiness();
        $this->actingAs($this->owner);
        $this->wh = $this->in(fn () => Warehouse::where('is_default', true)->value('id'));
    }

    private function in(callable $fn, $business = null)
    {
        return app(TenantContext::class)->runAs(($business ?? $this->business)->id, $fn);
    }

    private function product(string $price = '100', string $qty = '50', string $cost = '60', array $taxIds = []): Product
    {
        static $n = 0;
        $n++;
        $p = $this->in(fn () => Product::create([
            'name' => "Item $n", 'sku' => "A-$n", 'unit_id' => Unit::where('short_name', 'pcs')->value('id'),
            'purchase_price' => $cost, 'selling_price' => $price, 'track_inventory' => true,
        ]));
        if ($taxIds) {
            $this->in(fn () => $p->taxes()->sync($taxIds));
        }
        if ($qty !== '0') {
            $this->in(fn () => app(AdjustmentService::class)->create([
                'warehouse_id' => $this->wh, 'direction' => 'in', 'reason' => 'opening_stock', 'adjusted_at' => '2026-11-20',
                'items' => [['product_id' => $p->id, 'quantity' => $qty, 'unit_cost' => $cost]],
            ], $this->owner));
        }

        return $p;
    }

    private function tax(string $rate): int
    {
        return $this->in(fn () => Tax::create(['name' => "T$rate", 'rate' => $rate, 'is_inclusive' => false])->id);
    }

    private function party(string $type, array $o = [])
    {
        static $n = 0;
        $n++;

        return $this->in(fn () => app(PartyService::class)->create($type, array_merge(['name' => ucfirst($type)." $n"], $o)));
    }

    private function sell(Product $p, string $qty, array $payments, array $o = []): Sale
    {
        return $this->in(fn () => app(SaleService::class)->complete(array_merge([
            'warehouse_id' => $this->wh, 'items' => [['product_id' => $p->id, 'quantity' => $qty]], 'payments' => $payments,
        ], $o), $this->owner));
    }

    /** Balance of a system account in its natural direction. */
    private function bal(string $key): string
    {
        return $this->in(function () use ($key) {
            $chart = app(ChartOfAccounts::class);
            $account = Account::find($chart->id($key));
            $t = app(AccountingReportService::class)->totals(null, null)->get($account->id);

            return $t ? (string) app(AccountingReportService::class)->natural($account, $t)->toScale(4) : '0.0000';
        });
    }

    private function assertBooksAgree(): void
    {
        $report = $this->in(fn () => app(AccountingReportService::class));
        $tb = $this->in(fn () => $report->trialBalance(null));
        $this->assertTrue($tb['balanced'], 'Trial balance must balance: '.$tb['total_debit'].' vs '.$tb['total_credit']);
        foreach ($this->in(fn () => $report->reconciliation(app(ChartOfAccounts::class))) as $check) {
            $this->assertTrue($check['ok'], $check['label'].' differs by '.$check['difference']);
        }
        $this->assertTrue($this->in(fn () => $report->balanceSheet('2999-12-31'))['balanced']);
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

    public function test_every_business_gets_the_standard_chart_and_renumbering_does_not_break_postings(): void
    {
        $this->in(fn () => app(ChartOfAccounts::class)->ensure());
        $codes = $this->in(fn () => Account::orderBy('code')->pluck('name', 'code')->all());
        $this->assertSame('Cash in Hand', $codes['1000']);
        $this->assertSame(count(ChartOfAccounts::DEFAULTS), $this->in(fn () => Account::count()));

        $this->in(fn () => app(ChartOfAccounts::class)->ensure());
        $this->assertSame(count(ChartOfAccounts::DEFAULTS), $this->in(fn () => Account::count())); // idempotent

        $this->in(fn () => Account::where('system_key', 'cash')->update(['code' => '1001', 'name' => 'Till']));
        $this->sell($this->product(), '1', [['method' => 'cash', 'amount' => '100']]);
        $this->assertSame('100.0000', $this->bal('cash'));
    }

    public function test_a_cash_sale_posts_revenue_tax_cash_and_cost_of_sales(): void
    {
        $p = $this->product('100', '50', '60', [$this->tax('18')]);
        $sale = $this->sell($p, '2', [['method' => 'cash', 'amount' => '300']]); // total 236, change 64

        $entry = $this->in(fn () => JournalEntry::where('source_type', 'sale')->where('source_id', $sale->id)->first());
        $this->assertSame('auto', $entry->kind);
        $this->assertSame('JE-000002', $entry->entry_no); // JE-000001 is the opening stock of the product

        $this->assertSame('236.0000', $this->bal('cash'));
        $this->assertSame('200.0000', $this->bal('sales'));
        $this->assertSame('36.0000', $this->bal('tax_output'));
        $this->assertSame('120.0000', $this->bal('cogs'));
        $this->assertSame('2880.0000', $this->bal('inventory')); // 48 x 60 on the shelf, the opening 3000 was received outside accounting
        $this->assertSame('0.0000', $this->bal('receivable'));
    }

    public function test_card_and_cheque_payments_go_to_the_bank_account(): void
    {
        $p = $this->product();
        $this->sell($p, '1', [['method' => 'card', 'amount' => '40'], ['method' => 'cash', 'amount' => '60']]);
        $this->assertSame('40.0000', $this->bal('bank'));
        $this->assertSame('60.0000', $this->bal('cash'));
    }

    public function test_credit_sales_raise_receivable_and_receipts_clear_it(): void
    {
        $p = $this->product();
        $c = $this->party('customer', ['credit_limit' => '1000']);
        $this->sell($p, '3', [['method' => 'cash', 'amount' => '100']], ['customer_id' => $c->id]);

        $this->assertSame('200.0000', $this->bal('receivable'));
        $this->assertSame('200.0000', $this->in(fn () => Customer::find($c->id)->balance));

        $this->in(fn () => app(PaymentService::class)->record('customer', $c->id, ['amount' => '150', 'method' => 'bank', 'paid_at' => '2026-11-20'], $this->owner->id));
        $this->assertSame('50.0000', $this->bal('receivable'));
        $this->assertSame('150.0000', $this->bal('bank'));
        $this->assertBooksAgree();
    }

    public function test_voiding_a_sale_reverses_its_entry_exactly(): void
    {
        $p = $this->product('100', '50', '60', [$this->tax('10')]);
        $c = $this->party('customer', ['credit_limit' => '1000']);
        $sale = $this->sell($p, '4', [['method' => 'cash', 'amount' => '100']], ['customer_id' => $c->id]);
        $this->in(fn () => app(SaleService::class)->void($sale, 'Mistake', $this->owner));

        foreach (['cash', 'sales', 'tax_output', 'cogs', 'receivable'] as $key) {
            $this->assertSame('0.0000', $this->bal($key), $key);
        }
        $this->assertSame('3000.0000', $this->bal('inventory'));
        $reversal = $this->in(fn () => JournalEntry::where('source_id', $sale->id)->where('kind', 'reversal')->first());
        $this->assertNotNull($reversal);
        $this->assertBooksAgree();
    }

    public function test_sale_returns_post_returns_tax_cash_and_stock_back_at_cost(): void
    {
        $p = $this->product('100', '50', '60', [$this->tax('10')]);
        $sale = $this->sell($p, '3', [['method' => 'cash', 'amount' => '330']]);
        $item = $sale->items->first();

        $this->in(fn () => app(SaleReturnService::class)->create($sale, ['refund_method' => 'cash', 'items' => [['sale_item_id' => $item->id, 'quantity' => '1']]], $this->owner));

        $this->assertSame('100.0000', $this->bal('sales_returns'));
        $this->assertSame('220.0000', $this->bal('cash'));        // 330 in, 110 out
        $this->assertSame('20.0000', $this->bal('tax_output'));   // 30 collected, 10 handed back
        $this->assertSame('120.0000', $this->bal('cogs'));        // 180 sold, 60 back
        $this->assertSame('2880.0000', $this->bal('inventory'));
        $this->assertBooksAgree();

        $this->in(fn () => app(SaleReturnService::class)->create($sale, ['refund_method' => 'cash', 'items' => [['sale_item_id' => $item->id, 'quantity' => '2']]], $this->owner));
        $this->assertSame('0.0000', $this->bal('cash'));
        $this->assertSame('0.0000', $this->bal('tax_output'));
        $this->assertSame('0.0000', $this->bal('cogs'));
        $this->assertBooksAgree();
    }

    public function test_account_refunds_reduce_receivable_instead_of_cash(): void
    {
        $p = $this->product();
        $c = $this->party('customer', ['credit_limit' => '1000']);
        $sale = $this->sell($p, '2', [], ['customer_id' => $c->id]);
        $this->in(fn () => app(SaleReturnService::class)->create($sale, ['refund_method' => 'account', 'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1']]], $this->owner));

        $this->assertSame('100.0000', $this->bal('receivable'));
        $this->assertSame('0.0000', $this->bal('cash'));
        $this->assertBooksAgree();
    }

    public function test_purchases_post_inventory_input_tax_and_payable_and_returns_reverse_them(): void
    {
        $supplier = $this->party('supplier');
        $p = $this->product('20', '0', '10', []);
        $gst = $this->tax('18');
        $this->in(fn () => $p->taxes()->sync([$gst]));

        $purchase = $this->in(fn () => app(PurchaseService::class)->create([
            'supplier_id' => $supplier->id, 'warehouse_id' => $this->wh, 'order_date' => '2026-11-20',
            'items' => [['product_id' => $p->id, 'quantity' => '10', 'unit_cost' => '50']],
        ], $this->owner, true));

        $this->assertSame('500.0000', $this->bal('inventory'));
        $this->assertSame('90.0000', $this->bal('tax_input'));
        $this->assertSame('590.0000', $this->bal('payable'));
        $this->assertSame('590.0000', $this->in(fn () => Supplier::find($supplier->id)->balance));

        $this->in(fn () => app(PaymentService::class)->record('supplier', $supplier->id, ['amount' => '200', 'method' => 'cash', 'paid_at' => '2026-11-20'], $this->owner->id));
        $this->assertSame('390.0000', $this->bal('payable'));
        $this->assertSame('-200.0000', $this->bal('cash'));
        $this->assertBooksAgree();

        $this->in(function () use ($purchase) {
            $item = $purchase->items()->first();
            app(PurchaseReturnService::class)->create($purchase->refresh(), ['return_date' => '2026-11-21', 'items' => [['purchase_item_id' => $item->id, 'quantity' => '4']]], $this->owner);
        });
        $this->assertSame('300.0000', $this->bal('inventory'));
        $this->assertSame('54.0000', $this->bal('tax_input'));
        $this->assertSame('154.0000', $this->bal('payable')); // 590 owed - 200 paid - 236 credited
        $this->assertSame('0.0000', $this->bal('inventory_adjustment')); // credit matched carrying cost, no variance
        $this->assertBooksAgree();
    }

    public function test_opening_balances_and_party_adjustments_post_against_equity_and_adjustment_expense(): void
    {
        $c = $this->party('customer', ['opening_balance' => '500']);
        $s = $this->party('supplier', ['opening_balance' => '300']);
        $this->assertSame('500.0000', $this->bal('receivable'));
        $this->assertSame('300.0000', $this->bal('payable'));
        $this->assertSame('200.0000', $this->bal('opening_equity')); // credited 500 for the customer, debited 300 for the supplier

        $this->in(fn () => app(PaymentService::class)->adjust('customer', $c->id, '-100', '2026-11-20', 'Bad debt'));
        $this->in(fn () => app(PaymentService::class)->adjust('supplier', $s->id, '40', '2026-11-20', 'Fee'));
        $this->assertSame('400.0000', $this->bal('receivable'));
        $this->assertSame('340.0000', $this->bal('payable'));
        $this->assertSame('140.0000', $this->bal('ledger_adjustment')); // 100 written off + 40 extra owed
        $this->assertBooksAgree();
    }

    public function test_stock_adjustments_and_counts_post_inventory_value_changes(): void
    {
        $p = $this->product('100', '10', '60'); // opening stock 600
        $adj = app(AdjustmentService::class);
        $this->in(fn () => $adj->create(['warehouse_id' => $this->wh, 'direction' => 'out', 'reason' => 'damaged', 'adjusted_at' => '2026-11-20', 'notes' => null, 'items' => [['product_id' => $p->id, 'quantity' => '2']]], $this->owner));
        $this->assertSame('120.0000', $this->bal('inventory_adjustment'));

        $this->in(fn () => $adj->create(['warehouse_id' => $this->wh, 'direction' => 'in', 'reason' => 'opening_stock', 'adjusted_at' => '2026-11-20', 'notes' => null, 'items' => [['product_id' => $p->id, 'quantity' => '5', 'unit_cost' => '60']]], $this->owner));
        $this->assertSame('900.0000', $this->bal('opening_equity')); // 600 + 300

        $counts = app(StockCountService::class);
        $count = $this->in(fn () => $counts->create($this->wh, null, $this->owner));
        $line = $this->in(fn () => $count->items()->where('product_id', $p->id)->first());
        $this->in(fn () => $counts->saveCounts($count, [['id' => $line->id, 'counted_qty' => '10']], $this->owner)); // 13 on hand, 10 counted
        $this->in(fn () => $counts->complete($count, $this->owner));

        $this->assertSame('300.0000', $this->bal('inventory_adjustment')); // 120 damaged + 3 x 60 missing
        $this->assertSame('600.0000', $this->bal('inventory'));            // 10 units at 60
        $this->assertBooksAgree();
    }

    public function test_journal_entries_must_balance_and_are_immutable(): void
    {
        $journal = app(JournalService::class);
        $cash = $this->in(fn () => app(ChartOfAccounts::class)->id('cash'));
        $rent = $this->in(fn () => app(ChartOfAccounts::class)->id('expense_rent'));

        $this->rejected(fn () => $this->in(fn () => $journal->post('2026-11-20', 'Off', [['account' => $rent, 'debit' => '100'], ['account' => $cash, 'credit' => '90']])), 'lines');
        $this->rejected(fn () => $this->in(fn () => $journal->post('2026-11-20', 'Both', [['account' => $rent, 'debit' => '100', 'credit' => '100'], ['account' => $cash, 'credit' => '0']])), 'lines.0');
        $this->assertSame(0, $this->in(fn () => JournalEntry::count()));
        $this->assertNull($this->in(fn () => $journal->post('2026-11-20', 'Nothing', [['account' => $rent, 'debit' => '0'], ['account' => $cash, 'credit' => '0']])));

        $entry = $this->in(fn () => $journal->post('2026-11-20', 'Rent', [['account' => $rent, 'debit' => '100'], ['account' => $cash, 'credit' => '100']]));
        $line = $this->in(fn () => $entry->lines()->first());
        foreach ([fn () => $entry->update(['description' => 'x']), fn () => $entry->delete(), fn () => $line->update(['debit' => 1]), fn () => $line->delete()] as $try) {
            try {
                $this->in($try);
                $this->fail('Expected an immutability error.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_an_entry_is_reversed_once_and_a_reversal_cannot_be_reversed(): void
    {
        $journal = app(JournalService::class);
        $entry = $this->in(fn () => $journal->manual('2026-11-20', 'Rent', [
            ['account' => $this->in(fn () => app(ChartOfAccounts::class)->id('expense_rent')), 'debit' => '100'],
            ['account' => $this->in(fn () => app(ChartOfAccounts::class)->id('cash')), 'credit' => '100'],
        ]));
        $this->assertSame('100.0000', $this->bal('expense_rent'));

        $reversal = $this->in(fn () => $journal->reverse($entry, '2026-11-21'));
        $this->assertSame('reversal', $reversal->kind);
        $this->assertSame('0.0000', $this->bal('expense_rent'));
        $this->assertSame('0.0000', $this->bal('cash'));

        $this->rejected(fn () => $this->in(fn () => $journal->reverse($entry, '2026-11-21')), 'entry');
        $this->rejected(fn () => $this->in(fn () => $journal->reverse($reversal, '2026-11-21')), 'entry');
    }

    public function test_manual_journals_cannot_touch_control_accounts(): void
    {
        $chart = app(ChartOfAccounts::class);
        foreach (['receivable', 'payable', 'inventory'] as $key) {
            $this->rejected(fn () => $this->in(fn () => app(JournalService::class)->manual('2026-11-20', 'Sneaky', [
                ['account' => $chart->id($key), 'debit' => '10'], ['account' => $chart->id('equity'), 'credit' => '10'],
            ])), 'lines.0.account');
        }
    }

    public function test_the_closing_date_blocks_new_postings(): void
    {
        $p = $this->product();
        $this->in(fn () => app(\App\Services\Settings\SettingsService::class)->setMany(['accounting.lock_date' => '2026-11-20']));
        $this->in(fn () => $this->assertSame('2026-11-20', app(JournalService::class)->lockDate()));

        $rent = $this->in(fn () => app(ChartOfAccounts::class)->id('expense_rent'));
        $cash = $this->in(fn () => app(ChartOfAccounts::class)->id('cash'));
        $this->rejected(fn () => $this->in(fn () => app(JournalService::class)->manual('2026-11-20', 'Late', [['account' => $rent, 'debit' => '5'], ['account' => $cash, 'credit' => '5']])), 'entry_date');
        $this->in(fn () => app(JournalService::class)->manual('2026-11-21', 'Open', [['account' => $rent, 'debit' => '5'], ['account' => $cash, 'credit' => '5']]));

        // a sale dated today (inside the closed period in this test) is refused whole: no stock moves, no invoice number is burned
        $this->in(fn () => app(\App\Services\Settings\SettingsService::class)->setMany(['accounting.lock_date' => '2999-01-01']));
        $this->rejected(fn () => $this->sell($p, '1', [['method' => 'cash', 'amount' => '100']]), 'entry_date');
        $this->assertSame(0, $this->in(fn () => Sale::count()));
        $this->assertSame('50.0000', $this->in(fn () => \App\Models\Stock::where('product_id', $p->id)->value('quantity')));
    }

    public function test_statements_agree_with_each_other(): void
    {
        $p = $this->product('100', '50', '60', [$this->tax('10')]);
        $c = $this->party('customer', ['credit_limit' => '1000']);
        $this->sell($p, '5', [['method' => 'cash', 'amount' => '200']], ['customer_id' => $c->id]); // total 550
        $sale = $this->sell($p, '2', [['method' => 'card', 'amount' => '220']]);
        $this->in(fn () => app(SaleReturnService::class)->create($sale, ['refund_method' => 'bank', 'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1']]], $this->owner));

        $reports = $this->in(fn () => app(AccountingReportService::class));
        $pl = $this->in(fn () => $reports->incomeStatement('2000-01-01', '2999-12-31'));
        $this->assertSame('600.0000', $pl['total_revenue']);          // 700 sales - 100 returns
        $this->assertSame('360.0000', $pl['total_cost_of_sales']);    // 6 units net of 1 returned: 7 x 60 - 60
        $this->assertSame('240.0000', $pl['gross_profit']);
        $this->assertSame('240.0000', $pl['net_profit']);

        $bs = $this->in(fn () => $reports->balanceSheet('2999-12-31'));
        $this->assertSame('240.0000', $bs['profit_to_date']);
        $this->assertTrue($bs['balanced']);
        $this->assertBooksAgree();
    }

    public function test_the_ledger_shows_a_running_balance_across_pages(): void
    {
        $p = $this->product('10', '500', '5');
        foreach (range(1, 5) as $i) {
            $this->sell($p, '1', [['method' => 'cash', 'amount' => '10']]);
        }
        $cash = $this->in(fn () => Account::where('system_key', 'cash')->first());
        $reports = $this->in(fn () => app(AccountingReportService::class));

        $all = $this->in(fn () => $reports->ledger($cash, null, null, null, 1, 50));
        $this->assertSame('0.0000', $all['opening']);
        $this->assertSame('50.0000', $all['closing']);
        $this->assertSame(['10.0000', '20.0000', '30.0000', '40.0000', '50.0000'], array_column($all['lines']->items(), 'balance'));

        $second = $this->in(fn () => $reports->ledger($cash, null, null, null, 2, 2));
        $this->assertSame(['30.0000', '40.0000'], array_column($second['lines']->items(), 'balance')); // carried 20 over from page one

        $later = $this->in(fn () => $reports->ledger($cash, '2999-01-01', null, null, 1, 50));
        $this->assertSame('50.0000', $later['opening']);
        $this->assertSame([], $later['lines']->items());
    }

    public function test_branches_filter_the_reports(): void
    {
        $branchB = $this->makeBranch($this->business, 'BR2');
        $whB = $this->in(fn () => Warehouse::create(['name' => 'B', 'code' => 'WH-B', 'branch_id' => $branchB->id, 'is_active' => true])->id);
        $p = $this->product('100', '10', '60');
        $this->in(fn () => app(StockService::class)->receive($whB, $p->id, null, '10', '60', 'purchase_received'));

        $this->sell($p, '1', [['method' => 'cash', 'amount' => '100']]);
        $this->in(fn () => app(SaleService::class)->complete(['warehouse_id' => $whB, 'items' => [['product_id' => $p->id, 'quantity' => '3']], 'payments' => [['method' => 'cash', 'amount' => '300']]], $this->owner));

        $reports = $this->in(fn () => app(AccountingReportService::class));
        $this->assertSame('400.0000', $this->in(fn () => $reports->incomeStatement('2000-01-01', '2999-12-31'))['total_revenue']);
        $this->assertSame('300.0000', $this->in(fn () => $reports->incomeStatement('2000-01-01', '2999-12-31', $branchB->id))['total_revenue']);
        $this->assertTrue($this->in(fn () => $reports->trialBalance(null, $branchB->id))['balanced']);
    }

    public function test_chart_management_rules(): void
    {
        $this->actingAs($this->owner);
        $this->post('/accounting/accounts', ['code' => '6500', 'name' => 'Insurance', 'type' => 'expense'])->assertSessionHasNoErrors();
        $this->post('/accounting/accounts', ['code' => '6500', 'name' => 'Dup', 'type' => 'expense'])->assertSessionHasErrors('code');
        $this->post('/accounting/accounts', ['code' => '6501', 'name' => 'Bad', 'type' => 'banana'])->assertSessionHasErrors('type');

        $cash = $this->in(fn () => app(ChartOfAccounts::class)->id('cash'));
        $this->delete("/accounting/accounts/$cash")->assertSessionHasErrors('account');
        $this->put("/accounting/accounts/$cash", ['code' => '1000', 'name' => 'Cash', 'type' => 'expense'])->assertSessionHasErrors('type');
        $this->put("/accounting/accounts/$cash", ['code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'is_active' => false])->assertSessionHasErrors('is_active');

        $insurance = $this->in(fn () => Account::where('code', '6500')->value('id'));
        $this->put("/accounting/accounts/$insurance", ['code' => '6500', 'name' => 'Insurance cover', 'type' => 'expense', 'is_active' => true])->assertSessionHasNoErrors();

        $this->post('/accounting/journal', ['entry_date' => '2026-11-20', 'description' => 'Premium', 'lines' => [
            ['account_id' => $insurance, 'debit' => '80'], ['account_id' => $cash, 'credit' => '80'],
        ]])->assertSessionHasNoErrors();
        $this->delete("/accounting/accounts/$insurance")->assertSessionHasErrors('account'); // has postings now
    }

    public function test_pages_permissions_and_isolation_over_http(): void
    {
        $accountant = $this->makeStaff($this->business, 'Accountant');
        $cashier = $this->makeStaff($this->business, 'Cashier');
        $p = $this->product();
        $this->sell($p, '1', [['method' => 'cash', 'amount' => '100']]);
        $entry = $this->in(fn () => JournalEntry::first());
        $cash = $this->in(fn () => app(ChartOfAccounts::class)->id('cash'));

        foreach (['/accounting', '/accounting/journal', "/accounting/journal/{$entry->id}", '/accounting/trial-balance', '/accounting/income-statement', '/accounting/balance-sheet', "/accounting/accounts/$cash/ledger"] as $url) {
            $this->actingAs($accountant)->get($url)->assertOk();
            $this->actingAs($cashier)->get($url)->assertForbidden();
        }

        // posting needs accounting.manage; an automatic entry cannot be reversed by hand
        $this->actingAs($cashier)->post('/accounting/journal', [])->assertForbidden();
        $this->actingAs($this->owner)->post("/accounting/journal/{$entry->id}/reverse", ['entry_date' => '2026-11-21'])->assertSessionHasErrors('entry');

        [$other, $otherOwner] = $this->makeBusiness('Shop B');
        $theirs = $this->in(fn () => app(JournalService::class)->manual('2026-11-20', 'Theirs', [
            ['account' => app(ChartOfAccounts::class)->id('expense_rent'), 'debit' => '1'], ['account' => app(ChartOfAccounts::class)->id('cash'), 'credit' => '1'],
        ]), $other);
        $this->actingAs($this->owner)->get("/accounting/journal/{$theirs->id}")->assertNotFound();
        $theirCash = $this->in(fn () => app(ChartOfAccounts::class)->id('cash'), $other);
        $this->actingAs($this->owner)->get("/accounting/accounts/$theirCash/ledger")->assertNotFound();
        $this->assertSame(1, $this->in(fn () => JournalEntry::count(), $other));
    }

    public function test_the_lock_date_endpoint_only_moves_forward(): void
    {
        $this->actingAs($this->owner)->post('/accounting/lock', ['lock_date' => '2026-10-31'])->assertSessionHasNoErrors();
        $this->post('/accounting/lock', ['lock_date' => '2026-09-30'])->assertSessionHasErrors('lock_date');
        $this->post('/accounting/lock', ['lock_date' => '2026-11-30'])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-30', $this->in(fn () => app(JournalService::class)->lockDate()));
        $this->post('/accounting/lock', ['lock_date' => null])->assertSessionHasNoErrors();
        $this->assertNull($this->in(fn () => app(JournalService::class)->lockDate()));
    }
}
