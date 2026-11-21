<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReportService;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Cash\ExpenseService;
use App\Services\Inventory\AdjustmentService;
use App\Services\Parties\PartyService;
use App\Services\Parties\PaymentService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Reports\ReportService;
use App\Services\Sales\SaleReturnService;
use App\Services\Sales\SaleService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private $business;

    private $owner;

    private $cashier;

    private Branch $main;

    private int $wh;

    private int $tax10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
        [$this->business, $this->owner] = $this->makeBusiness();
        $this->main = $this->in(fn () => Branch::where('is_main', true)->first());
        $this->cashier = $this->makeStaff($this->business, 'Cashier', [$this->main]);
        $this->actingAs($this->owner);
        $this->wh = $this->in(fn () => Warehouse::where('is_default', true)->value('id'));
        $this->tax10 = $this->in(fn () => Tax::create(['name' => 'T10', 'rate' => '10', 'is_inclusive' => false])->id);
        $this->travelTo('2026-11-10 12:00:00');
    }

    private function in(callable $fn, $business = null)
    {
        return app(TenantContext::class)->runAs(($business ?? $this->business)->id, $fn);
    }

    private function report(string $type, array $filters = [], $user = null): array
    {
        return $this->in(fn () => app(ReportService::class)->run($type, $user ?? $this->owner, $filters + ['from' => '2026-11-01', 'to' => '2026-11-30']));
    }

    private function product(string $name = null, string $price = '100', string $qty = '50', string $cost = '60', array $o = []): Product
    {
        static $n = 0;
        $n++;
        $p = $this->in(fn () => Product::create(array_merge([
            'name' => $name ?? "Item $n", 'sku' => "R-$n", 'unit_id' => Unit::where('short_name', 'pcs')->value('id'),
            'purchase_price' => $cost, 'selling_price' => $price, 'track_inventory' => true,
        ], $o)));
        $this->in(fn () => $p->taxes()->sync([$this->tax10]));
        if ($qty !== '0') {
            $this->in(fn () => app(AdjustmentService::class)->create([
                'warehouse_id' => $this->wh, 'direction' => 'in', 'reason' => 'opening_stock', 'adjusted_at' => '2026-11-01',
                'items' => [['product_id' => $p->id, 'quantity' => $qty, 'unit_cost' => $cost]],
            ], $this->owner));
        }

        return $p;
    }

    private function sell(Product $p, string $qty, array $payments, $user = null, array $o = [])
    {
        return $this->in(fn () => app(SaleService::class)->complete(array_merge([
            'warehouse_id' => $this->wh, 'items' => [['product_id' => $p->id, 'quantity' => $qty]], 'payments' => $payments,
        ], $o), $user ?? $this->owner));
    }

    private function rowFor(array $report, string $key, string $value): ?array
    {
        foreach ($report['rows'] as $row) {
            if (($row[$key] ?? null) === $value) {
                return $row;
            }
        }

        return null;
    }

    public function test_daily_sales_add_up_per_day_and_agree_with_the_profit_and_loss(): void
    {
        $p = $this->product();
        $this->sell($p, '2', [['method' => 'cash', 'amount' => '220']]);   // net 200, tax 20, cost 120
        $this->sell($p, '1', [['method' => 'card', 'amount' => '110']]);   // net 100, tax 10, cost 60
        $this->travelTo('2026-11-12 12:00:00');
        $this->sell($p, '3', [['method' => 'cash', 'amount' => '330']]);   // net 300, tax 30, cost 180

        $r = $this->report('sales_daily');
        $d10 = $this->rowFor($r, 'date', '2026-11-10');
        $this->assertSame(2, $d10['invoices']);
        $this->assertSame('300.0000', $d10['net']);
        $this->assertSame('30.0000', $d10['tax']);
        $this->assertSame('330.0000', $d10['total']);
        $this->assertSame('180.0000', $d10['cogs']);
        $this->assertSame('120.0000', $d10['profit']);
        $this->assertNull($this->rowFor($r, 'date', '2026-11-11')); // a day without sales has no row...
        $this->assertCount(30, $r['chart']['labels']);              // ...but is on the chart as zero
        $this->assertSame(0.0, $r['chart']['datasets'][0]['data'][10]);

        $this->assertSame(3, $r['totals']['invoices']);
        $this->assertSame('600.0000', $r['totals']['net']);
        $this->assertSame('360.0000', $r['totals']['profit']);

        // the same figures the books show
        $pl = $this->in(fn () => app(AccountingReportService::class)->incomeStatement('2026-11-01', '2026-11-30'));
        $this->assertSame($r['totals']['net'], $pl['total_revenue']);
        $this->assertSame($r['totals']['cogs'], $pl['total_cost_of_sales']);
        $this->assertSame($r['totals']['profit'], $pl['gross_profit']);
    }

    public function test_a_late_evening_sale_belongs_to_the_next_day_in_the_business_timezone(): void
    {
        $this->in(fn () => $this->business->update(['timezone' => 'Asia/Karachi']));
        $this->business = $this->business->fresh();
        $p = $this->product();
        $this->travelTo('2026-11-10 22:30:00'); // 03:30 on the 11th in Karachi
        $this->sell($p, '1', [['method' => 'cash', 'amount' => '110']]);

        $r = $this->report('sales_daily');
        $this->assertNull($this->rowFor($r, 'date', '2026-11-10'));
        $this->assertSame(1, $this->rowFor($r, 'date', '2026-11-11')['invoices']);
    }

    public function test_refunds_are_shown_on_the_day_they_were_paid(): void
    {
        $p = $this->product();
        $sale = $this->sell($p, '2', [['method' => 'cash', 'amount' => '220']]);
        $this->travelTo('2026-11-12 12:00:00');
        $this->in(fn () => app(SaleReturnService::class)->create($sale, ['refund_method' => 'cash', 'return_date' => '2026-11-12', 'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1']]], $this->owner));

        $r = $this->report('sales_daily');
        $this->assertSame('110.0000', $this->rowFor($r, 'date', '2026-11-12')['returns']);
        $this->assertSame('0.0000', $this->rowFor($r, 'date', '2026-11-10')['returns']);
        $this->assertSame('110.0000', $r['totals']['returns']);
    }

    public function test_voided_and_held_sales_are_left_out(): void
    {
        $p = $this->product();
        $keep = $this->sell($p, '1', [['method' => 'cash', 'amount' => '110']]);
        $void = $this->sell($p, '5', [['method' => 'cash', 'amount' => '550']]);
        $this->in(fn () => app(SaleService::class)->void($void, 'Mistake', $this->owner));
        $this->in(fn () => app(SaleService::class)->hold(['warehouse_id' => $this->wh, 'items' => [['product_id' => $p->id, 'quantity' => '2']]], $this->owner));

        $r = $this->report('sales_daily');
        $this->assertSame(1, $r['totals']['invoices']);
        $this->assertSame('100.0000', $r['totals']['net']);
        $this->assertNotNull($keep);
    }

    public function test_products_payments_and_cashiers(): void
    {
        $a = $this->product('Alpha', '100', '50', '60');
        $b = $this->product('Beta', '40', '50', '10');
        $c = $this->in(fn () => app(PartyService::class)->create('customer', ['name' => 'Credit Co', 'credit_limit' => '1000']));
        $this->sell($a, '3', [['method' => 'cash', 'amount' => '330']]);
        $this->sell($b, '5', [['method' => 'card', 'amount' => '220']], $this->cashier);                        // total 220
        $this->sell($b, '5', [['method' => 'card', 'amount' => '100']], $this->cashier, ['customer_id' => $c->id]); // total 220, 120 on credit

        $products = $this->report('sales_products');
        $alpha = $this->rowFor($products, 'product', 'Alpha');
        $this->assertSame('300.0000', $alpha['revenue']);
        $this->assertSame('180.0000', $alpha['cost']);
        $this->assertSame('120.0000', $alpha['profit']);
        $this->assertSame('40.00', $alpha['margin']);
        $this->assertSame('Beta', $products['rows'][0]['product']); // 400 of revenue beats Alpha's 300
        $this->assertSame('700.0000', $products['totals']['revenue']);
        $this->assertSame('420.0000', $products['totals']['profit']);

        $pay = $this->report('sales_payments');
        $this->assertSame('330.0000', $this->rowFor($pay, 'method', 'Cash')['amount']);
        $this->assertSame('320.0000', $this->rowFor($pay, 'method', 'Card')['amount']);
        $this->assertSame('120.0000', $this->rowFor($pay, 'method', 'On credit (not yet paid)')['amount']);
        $this->assertSame('770.0000', $pay['totals']['amount']); // everything sold: 330 + 220 + 220

        $who = $this->report('sales_cashiers');
        $this->assertSame(['invoices' => 2, 'net' => '400.0000', 'total' => '440.0000'], array_intersect_key($this->rowFor($who, 'cashier', 'Cashier'), array_flip(['invoices', 'total', 'net'])));
        $this->assertSame(1, $this->rowFor($who, 'cashier', $this->owner->name)['invoices']);
        $this->assertSame(3, $who['totals']['invoices']);
    }

    public function test_purchases_by_supplier(): void
    {
        $s1 = $this->in(fn () => app(PartyService::class)->create('supplier', ['name' => 'Acme']));
        $s2 = $this->in(fn () => app(PartyService::class)->create('supplier', ['name' => 'Bolt']));
        $p = $this->product(null, '20', '0', '10');
        $buy = fn ($supplier, $qty) => $this->in(fn () => app(PurchaseService::class)->create([
            'supplier_id' => $supplier->id, 'warehouse_id' => $this->wh, 'order_date' => '2026-11-10',
            'items' => [['product_id' => $p->id, 'quantity' => $qty, 'unit_cost' => '50']],
        ], $this->owner, true));
        $buy($s1, '10');
        $buy($s1, '2');
        $buy($s2, '1');

        $r = $this->report('purchases_suppliers');
        $acme = $this->rowFor($r, 'supplier', 'Acme');
        $this->assertSame(2, $acme['orders']);
        $this->assertSame('600.0000', $acme['net']);   // 12 x 50
        $this->assertSame('60.0000', $acme['tax']);    // 10%
        $this->assertSame('660.0000', $acme['total']);
        $this->assertSame('Acme', $r['rows'][0]['supplier']);
        $this->assertSame('715.0000', $r['totals']['total']);
        $this->assertSame(3, $r['totals']['orders']);
    }

    public function test_stock_valuation_matches_the_books_and_low_stock_lists_what_needs_buying(): void
    {
        $this->product('Plenty', '10', '100', '5', ['min_stock' => '10']);
        $this->product('Running low', '10', '8', '5', ['min_stock' => '10']);
        $out = $this->product('Gone', '10', '3', '5', ['min_stock' => '2']);
        $this->sell($out, '3', [['method' => 'cash', 'amount' => '33']]);
        $this->product('Service', '10', '0', '0', ['track_inventory' => false]);
        $this->product('No minimum', '10', '4', '5'); // min_stock 0: never flagged until empty

        $v = $this->report('stock_valuation');
        $this->assertSame('500.0000', $this->rowFor($v, 'product', 'Plenty')['value']);
        $this->assertNull($this->rowFor($v, 'product', 'Gone'));        // empty rows are not stock
        $this->assertSame('560.0000', $v['totals']['value']);           // 500 + 40 + 20
        $this->assertSame($v['totals']['value'], $this->in(function () {
            $account = \App\Models\Account::find(app(ChartOfAccounts::class)->id('inventory'));
            $t = app(AccountingReportService::class)->totals(null, null)->get($account->id);

            return (string) $t['debit']->minus($t['credit'])->toScale(4);
        }));

        $l = $this->report('stock_low');
        $names = array_column($l['rows'], 'product');
        $this->assertContains('Running low', $names);
        $this->assertContains('Gone', $names);
        $this->assertNotContains('Plenty', $names);
        $this->assertNotContains('Service', $names);
        $this->assertNotContains('No minimum', $names);
        $this->assertSame('Out of stock', $this->rowFor($l, 'product', 'Gone')['status']);
        $this->assertSame('Low', $this->rowFor($l, 'product', 'Running low')['status']);
        $this->assertSame('Gone', $l['rows'][0]['product']); // emptiest first
    }

    public function test_balances_and_expenses(): void
    {
        $this->in(fn () => app(PartyService::class)->create('customer', ['name' => 'Owes A', 'opening_balance' => '300']));
        $advance = $this->in(fn () => app(PartyService::class)->create('customer', ['name' => 'Advance B']));
        $this->in(fn () => app(PaymentService::class)->record('customer', $advance->id, ['amount' => '50', 'method' => 'cash', 'paid_at' => '2026-11-10'], $this->owner->id)); // paid in advance
        $this->in(fn () => app(PartyService::class)->create('customer', ['name' => 'Settled']));
        $this->in(fn () => app(PartyService::class)->create('supplier', ['name' => 'We owe C', 'opening_balance' => '700']));

        $c = $this->report('customer_balances');
        $this->assertSame(['Owes A', 'Advance B'], array_column($c['rows'], 'name')); // highest balance first, settled left out
        $this->assertSame('250.0000', $c['totals']['balance']);
        $s = $this->report('supplier_balances');
        $this->assertSame('700.0000', $s['totals']['balance']);

        $category = $this->in(function () {
            app(ChartOfAccounts::class)->ensure();

            return ExpenseCategory::create(['name' => 'Rent', 'account_id' => app(ChartOfAccounts::class)->id('expense_rent'), 'is_active' => true]);
        });
        $spend = fn ($amount, $date, $user) => $this->in(fn () => app(ExpenseService::class)->create([
            'branch_id' => $this->main->id, 'category_id' => $category->id, 'expense_date' => $date, 'amount' => $amount, 'method' => 'bank', 'description' => 'x',
        ], $user));
        $spend('500', '2026-11-05', $this->owner);
        $spend('250', '2026-11-20', $this->owner);
        $spend('999', '2026-10-31', $this->owner);                       // outside the period
        $pending = $this->makeStaff($this->business, 'Branch Manager', [$this->main]);
        $spend('111', '2026-11-06', $pending);                           // waiting for approval: not an expense yet

        $e = $this->report('expenses_categories');
        $this->assertSame(2, $e['rows'][0]['expenses']);
        $this->assertSame('750.0000', $e['totals']['amount']);
    }

    public function test_users_only_report_on_their_own_branches_and_never_on_other_businesses(): void
    {
        $branchB = $this->makeBranch($this->business, 'BR2');
        $whB = $this->in(fn () => Warehouse::create(['name' => 'B', 'code' => 'WH-B', 'branch_id' => $branchB->id, 'is_active' => true])->id);
        $p = $this->product();
        $this->in(fn () => app(AdjustmentService::class)->create([
            'warehouse_id' => $whB, 'direction' => 'in', 'reason' => 'opening_stock', 'adjusted_at' => '2026-11-01',
            'items' => [['product_id' => $p->id, 'quantity' => '10', 'unit_cost' => '60']],
        ], $this->owner));

        $this->sell($p, '1', [['method' => 'cash', 'amount' => '110']]);
        $this->in(fn () => app(SaleService::class)->complete(['warehouse_id' => $whB, 'items' => [['product_id' => $p->id, 'quantity' => '4']], 'payments' => [['method' => 'cash', 'amount' => '440']]], $this->owner));

        $this->assertSame('500.0000', $this->report('sales_daily')['totals']['net']);
        $this->assertSame('400.0000', $this->report('sales_daily', ['branch_id' => $branchB->id])['totals']['net']);
        $this->assertSame('100.0000', $this->report('sales_daily', ['branch_id' => $this->main->id])['totals']['net']);

        // a cashier of the main branch sees only the main branch, even when asking for the other one
        $this->assertSame('100.0000', $this->report('sales_daily', [], $this->cashier)['totals']['net']);
        $this->assertSame(0, $this->report('sales_daily', ['branch_id' => $branchB->id], $this->cashier)['totals']['invoices']);
        $valuation = $this->report('stock_valuation', [], $this->cashier);
        $this->assertSame(['Main Warehouse'], array_values(array_unique(array_column($valuation['rows'], 'warehouse'))));

        [$other, $otherOwner] = $this->makeBusiness('Shop B');
        $this->in(function () use ($otherOwner) {
            $theirs = Product::create(['name' => 'Theirs', 'sku' => 'T', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'purchase_price' => 1, 'selling_price' => 2, 'track_inventory' => true]);
            $wh = Warehouse::where('is_default', true)->value('id');
            app(AdjustmentService::class)->create(['warehouse_id' => $wh, 'direction' => 'in', 'reason' => 'opening_stock', 'adjusted_at' => '2026-11-01', 'items' => [['product_id' => $theirs->id, 'quantity' => '9', 'unit_cost' => '1']]], $otherOwner);
            app(SaleService::class)->complete(['warehouse_id' => $wh, 'items' => [['product_id' => $theirs->id, 'quantity' => '9']], 'payments' => [['method' => 'cash', 'amount' => '18']]], $otherOwner);
        }, $other);
        $this->assertSame('500.0000', $this->report('sales_daily')['totals']['net']);
        $this->assertNull($this->rowFor($this->report('sales_products'), 'product', 'Theirs'));
    }

    public function test_pages_permissions_and_exports_over_http(): void
    {
        $p = $this->product('=SUM(A1)', '100', '10');
        $this->sell($p, '2', [['method' => 'cash', 'amount' => '220']]);
        $inventory = $this->makeStaff($this->business, 'Inventory Manager', [$this->main]); // may view reports, not export them
        $accountant = $this->makeStaff($this->business, 'Accountant', [$this->main]);

        $this->actingAs($this->cashier)->get('/reports')->assertForbidden();
        $this->actingAs($inventory)->get('/reports')->assertOk();
        foreach (array_keys(ReportService::TYPES) as $type) {
            $this->actingAs($accountant)->get("/reports/$type?from=2026-11-01&to=2026-11-30")->assertOk();
        }
        $this->actingAs($accountant)->get('/reports/nonsense')->assertNotFound();

        $this->actingAs($inventory)->get('/reports/sales_products/export')->assertForbidden();
        $res = $this->actingAs($accountant)->get('/reports/sales_products/export?from=2026-11-01&to=2026-11-30');
        $res->assertOk();
        $this->assertStringStartsWith('text/csv', $res->headers->get('Content-Type'));
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));
        $this->assertSame('Product,SKU,Sold,Returned,"Net revenue",Cost,Profit,"Margin %"', $lines[0]);
        $this->assertStringStartsWith("'=SUM(A1),", $lines[1]); // a product named like a formula cannot run as one
        $this->assertStringContainsString('200.0000', $lines[1]);
        $this->assertStringStartsWith('Total,', end($lines));

        $this->actingAs($accountant)->get('/reports/sales_daily?from=2026-11-30&to=2026-11-01')->assertSessionHasErrors('to');
        $this->actingAs($accountant)->get('/reports/sales_daily?from=nonsense')->assertSessionHasErrors('from');
        $this->actingAs($accountant)->get('/reports/sales_daily?from=2020-01-01&to=2026-11-30')->assertStatus(422);
    }

    public function test_the_screen_shows_500_rows_and_the_export_shows_all_of_them(): void
    {
        foreach (range(1, 3) as $i) {
            $this->product("Bulk $i", '10', '5', '1');
        }
        $report = $this->in(fn () => app(ReportService::class)->run('stock_valuation', $this->owner, [], 2));
        $this->assertTrue($report['truncated']);
        $this->assertCount(2, $report['rows']);
        $this->assertSame(3, $report['total_rows']);
        $this->assertSame('15.0000', $report['totals']['value']); // totals cover every row, not just the visible ones
    }
}
