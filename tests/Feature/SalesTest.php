<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use App\Services\Parties\PartyService;
use App\Services\Sales\SaleReturnService;
use App\Services\Sales\SaleService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private $business;

    private $owner;

    private $cashier;

    private int $wh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
        [$this->business, $this->owner] = $this->makeBusiness();
        $main = $this->in(fn () => \App\Models\Branch::where('is_main', true)->first());
        $this->cashier = $this->makeStaff($this->business, 'Cashier', [$main]); // branch staff only sell from their own branch
        $this->actingAs($this->owner);
        $this->wh = $this->in(fn () => Warehouse::where('is_default', true)->value('id'));
    }

    private function in(callable $fn, $business = null)
    {
        return app(TenantContext::class)->runAs(($business ?? $this->business)->id, $fn);
    }

    /** A stocked product: $qty on hand bought at $cost, sold at $price. */
    private function product(string $price = '100', string $qty = '50', string $cost = '60', array $o = [], array $taxIds = []): Product
    {
        static $n = 0;
        $n++;
        $p = $this->in(fn () => Product::create(array_merge([
            'name' => "Item $n", 'sku' => "S-$n", 'unit_id' => Unit::where('short_name', 'pcs')->value('id'),
            'purchase_price' => $cost, 'selling_price' => $price, 'track_inventory' => true,
        ], $o)));
        if ($taxIds) {
            $this->in(fn () => $p->taxes()->sync($taxIds));
        }
        if ($qty !== '0' && ($o['track_inventory'] ?? true)) {
            $this->in(fn () => app(StockService::class)->receive($this->wh, $p->id, null, $qty, $cost, 'purchase_received'));
        }

        return $p;
    }

    private function tax(string $rate, bool $inclusive = false): int
    {
        return $this->in(fn () => Tax::create(['name' => "T$rate", 'rate' => $rate, 'is_inclusive' => $inclusive])->id);
    }

    private function customer(array $o = []): Customer
    {
        static $n = 0;
        $n++;

        return $this->in(fn () => app(PartyService::class)->create('customer', array_merge(['name' => "Cust $n"], $o)));
    }

    private function sell(array $items, array $payments = [], array $o = [], $user = null, $held = null): Sale
    {
        return $this->in(fn () => app(SaleService::class)->complete(array_merge(['warehouse_id' => $this->wh, 'items' => $items, 'payments' => $payments], $o), $user ?? $this->owner, $held));
    }

    private function line(Product $p, string $qty = '1', array $o = []): array
    {
        return array_merge(['product_id' => $p->id, 'quantity' => $qty], $o);
    }

    private function onHand(Product $p): string
    {
        return $this->in(fn () => Stock::where('warehouse_id', $this->wh)->where('product_id', $p->id)->value('quantity') ?? '0');
    }

    private function owed(Customer $c): string
    {
        return $this->in(fn () => Customer::find($c->id)->balance);
    }

    private function assertRejected(callable $fn, ?string $key = null): void
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

    public function test_cash_sale_is_priced_by_the_server_costed_and_takes_stock(): void
    {
        $p = $this->product('100', '50', '60', [], [$this->tax('18')]);
        $sale = $this->sell([$this->line($p, '2')], [['method' => 'cash', 'amount' => '300']]);

        $this->assertSame('INV-000001', $sale->reference_no);
        $this->assertSame('200.0000', $sale->subtotal);
        $this->assertSame('36.0000', $sale->tax_total);
        $this->assertSame('236.0000', $sale->total);
        $this->assertSame('236.0000', $sale->paid_total);
        $this->assertSame('64.0000', $sale->change_due);
        $this->assertSame('0.0000', $sale->balance_due);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('48.0000', $this->onHand($p));
        $this->assertSame('120.0000', $sale->fresh()->cogs_total ?? $this->in(fn () => Sale::find($sale->id)->cogs_total));
        $this->assertSame('236.0000', $this->in(fn () => SalePayment::where('sale_id', $sale->id)->value('amount'))); // change is not kept
        $this->assertSame('sale', $this->in(fn () => StockMovement::where('reference_id', $sale->id)->value('type')));
    }

    public function test_inclusive_tax_prices_are_extracted(): void
    {
        $p = $this->product('118', '10', '50', [], [$this->tax('18', true)]);
        $sale = $this->sell([$this->line($p)], [['method' => 'card', 'amount' => '118']]);

        $this->assertSame('100.0000', $sale->subtotal);
        $this->assertSame('18.0000', $sale->tax_total);
        $this->assertSame('118.0000', $sale->total);
    }

    public function test_quote_matches_what_complete_charges(): void
    {
        $p = $this->product('49.99', '10', '20', [], [$this->tax('10')]);
        $items = [$this->line($p, '3')];
        $quote = $this->in(fn () => app(SaleService::class)->quote($items, $this->owner));
        $sale = $this->sell($items, [['method' => 'cash', 'amount' => '500']]);

        $this->assertSame($quote['total'], $sale->total);
        $this->assertSame($quote['tax_total'], $sale->tax_total);
    }

    public function test_cashiers_cannot_change_prices_or_give_discounts_but_owners_can(): void
    {
        $p = $this->product('100');
        $pay = [['method' => 'cash', 'amount' => '1000']];

        $this->assertRejected(fn () => $this->sell([$this->line($p, '1', ['unit_price' => '50'])], $pay, [], $this->cashier), 'items.0.unit_price');
        $this->assertRejected(fn () => $this->sell([$this->line($p, '1', ['discount' => '10'])], $pay, [], $this->cashier), 'items.0.discount');
        // sending the list price itself is not an override
        $ok = $this->sell([$this->line($p, '1', ['unit_price' => '100'])], $pay, [], $this->cashier);
        $this->assertSame('100.0000', $ok->total);

        $sale = $this->sell([$this->line($p, '2', ['unit_price' => '90', 'discount' => '20'])], $pay, [], $this->owner);
        $this->assertSame('160.0000', $sale->total);
        $this->assertSame('20.0000', $sale->discount_total);
    }

    public function test_minimum_selling_price_is_enforced_even_for_owners(): void
    {
        $p = $this->product('100', '10', '60', ['min_selling_price' => '80']);
        $this->assertRejected(fn () => $this->sell([$this->line($p, '1', ['discount' => '30'])], [['method' => 'cash', 'amount' => '100']]), 'items.0.unit_price');
        $ok = $this->sell([$this->line($p, '1', ['discount' => '20'])], [['method' => 'cash', 'amount' => '80']]);
        $this->assertSame('80.0000', $ok->total);
    }

    public function test_not_enough_stock_rejects_the_whole_sale_and_burns_no_invoice_number(): void
    {
        $a = $this->product('10', '5');
        $b = $this->product('10', '1');

        $this->assertRejected(fn () => $this->sell([$this->line($a, '2'), $this->line($b, '3')], [['method' => 'cash', 'amount' => '50']]), 'items');
        $this->assertSame('5.0000', $this->onHand($a));
        $this->assertSame(0, $this->in(fn () => Sale::count()));

        $this->assertSame('INV-000001', $this->sell([$this->line($a, '1')], [['method' => 'cash', 'amount' => '10']])->reference_no);
    }

    public function test_non_stock_products_sell_without_inventory(): void
    {
        $service = $this->product('500', '0', '200', ['track_inventory' => false]);
        $sale = $this->sell([$this->line($service, '2')], [['method' => 'cash', 'amount' => '1000']]);

        $this->assertSame('1000.0000', $sale->total);
        $this->assertSame('400.0000', $this->in(fn () => Sale::find($sale->id)->cogs_total));
        $this->assertSame(0, $this->in(fn () => StockMovement::count()));
    }

    public function test_variants_decimals_and_inactive_products(): void
    {
        $varied = $this->product('10', '0', '5', ['has_variants' => true]);
        $variant = $this->in(fn () => ProductVariant::create(['product_id' => $varied->id, 'name' => 'XL', 'sku' => 'S-XL', 'selling_price' => '12']));
        $this->in(fn () => app(StockService::class)->receive($this->wh, $varied->id, $variant->id, '5', '5', 'purchase_received'));
        $whole = $this->product('10', '10');
        $kg = $this->product('10', '10', '5', ['unit_id' => $this->in(fn () => Unit::where('short_name', 'kg')->value('id'))]);
        $off = $this->product('10', '10', '5', ['is_active' => false]);
        $pay = [['method' => 'cash', 'amount' => '1000']];

        $this->assertRejected(fn () => $this->sell([$this->line($varied)], $pay), 'items.0.variant_id');
        $this->assertRejected(fn () => $this->sell([$this->line($whole, '1.5')], $pay), 'items.0.quantity');
        $this->assertRejected(fn () => $this->sell([$this->line($off)], $pay), 'items.0.product_id');

        $sale = $this->sell([$this->line($varied, '2', ['variant_id' => $variant->id]), $this->line($kg, '0.5')], $pay);
        $this->assertSame('29.0000', $sale->total); // 2 * 12 (variant price) + 0.5 * 10
    }

    public function test_only_cash_gives_change_and_split_tenders_work(): void
    {
        $p = $this->product('100');
        $this->assertRejected(fn () => $this->sell([$this->line($p)], [['method' => 'card', 'amount' => '150']]), 'payments');

        $sale = $this->sell([$this->line($p)], [['method' => 'card', 'amount' => '40'], ['method' => 'cash', 'amount' => '100']]);
        $this->assertSame('10.0000', $sale->change_due);
        $methods = $this->in(fn () => SalePayment::where('sale_id', $sale->id)->orderBy('id')->pluck('amount', 'method')->all());
        $this->assertSame(['card' => '40.0000', 'cash' => '60.0000'], $methods);
    }

    public function test_credit_sale_posts_the_customer_ledger_and_respects_the_credit_limit(): void
    {
        $p = $this->product('100');
        $c = $this->customer(['credit_limit' => '250']);

        $this->assertRejected(fn () => $this->sell([$this->line($p)], [], []), 'payments'); // walk-in cannot owe
        $sale = $this->sell([$this->line($p, '2')], [['method' => 'cash', 'amount' => '50']], ['customer_id' => $c->id]);

        $this->assertSame('150.0000', $sale->balance_due);
        $this->assertSame('partial', $sale->payment_status);
        $this->assertSame('150.0000', $this->owed($c));
        $this->assertSame(['invoice', 'payment'], $this->in(fn () => PartyLedgerEntry::where('party_id', $c->id)->where('party_type', 'customer')->orderBy('id')->pluck('entry_type')->all()));

        $this->assertRejected(fn () => $this->sell([$this->line($p, '2')], [], ['customer_id' => $c->id]), 'payments'); // 150 + 200 > 250
        $this->assertSame('150.0000', $this->owed($c));
        $this->assertSame('48.0000', $this->onHand($p)); // the refused sale took nothing

        $cashOnly = $this->customer(['credit_limit' => '0']);
        $this->assertRejected(fn () => $this->sell([$this->line($p)], [], ['customer_id' => $cashOnly->id]), 'payments');
    }

    public function test_hold_resume_and_discard(): void
    {
        $p = $this->product('100');
        $svc = app(SaleService::class);
        $held = $this->in(fn () => $svc->hold(['warehouse_id' => $this->wh, 'items' => [$this->line($p, '3')]], $this->owner));

        $this->assertSame('HLD-000001', $held->reference_no);
        $this->assertSame('held', $held->status);
        $this->assertSame('50.0000', $this->onHand($p)); // nothing moved
        $this->assertSame(0, $this->in(fn () => SalePayment::count()));

        $sale = $this->sell([$this->line($p, '2')], [['method' => 'cash', 'amount' => '200']], [], null, $held);
        $this->assertSame($held->id, $sale->id);
        $this->assertSame('INV-000001', $sale->reference_no);
        $this->assertSame('completed', $sale->status);
        $this->assertSame('48.0000', $this->onHand($p));

        $again = $this->in(fn () => $svc->hold(['warehouse_id' => $this->wh, 'items' => [$this->line($p)]], $this->owner));
        $this->in(fn () => $svc->discardHeld($again));
        $this->assertSame(0, $this->in(fn () => Sale::where('status', 'held')->count()));
        $this->assertRejected(fn () => $this->in(fn () => $svc->discardHeld($sale)));
    }

    public function test_voiding_restores_stock_at_cost_and_reverses_the_customer_account(): void
    {
        $p = $this->product('100', '50', '60');
        $c = $this->customer(['credit_limit' => '1000']);
        $sale = $this->sell([$this->line($p, '4')], [['method' => 'cash', 'amount' => '100']], ['customer_id' => $c->id]);
        $this->assertSame('300.0000', $this->owed($c));

        $void = $this->in(fn () => app(SaleService::class)->void($sale, 'Customer cancelled', $this->owner));
        $this->assertSame('voided', $void->status);
        $this->assertSame('50.0000', $this->onHand($p));
        $this->assertSame('3000.0000', $this->in(fn () => Stock::where('product_id', $p->id)->value('total_value'))); // back to 50 x 60
        $this->assertSame('0.0000', $this->owed($c));

        $this->assertRejected(fn () => $this->in(fn () => app(SaleService::class)->void($sale, 'Again', $this->owner)), 'status');
    }

    public function test_returns_restock_at_original_cost_and_refund_the_price_paid(): void
    {
        $p = $this->product('100', '50', '60', [], [$this->tax('10')]);
        $sale = $this->sell([$this->line($p, '3')], [['method' => 'cash', 'amount' => '330']]); // total 330
        $item = $sale->items->first();
        $svc = app(SaleReturnService::class);

        $r1 = $this->in(fn () => $svc->create($sale, ['refund_method' => 'cash', 'items' => [['sale_item_id' => $item->id, 'quantity' => '1']]], $this->owner));
        $this->assertSame('SRT-000001', $r1->reference_no);
        $this->assertSame('110.0000', $r1->refund_total);
        $this->assertSame('48.0000', $this->onHand($p));
        $this->assertSame('2880.0000', $this->in(fn () => Stock::where('product_id', $p->id)->value('total_value'))); // 48 x 60

        $this->assertRejected(fn () => $this->in(fn () => $svc->create($sale, ['refund_method' => 'cash', 'items' => [['sale_item_id' => $item->id, 'quantity' => '3']]], $this->owner)), 'items.0.quantity');

        $r2 = $this->in(fn () => $svc->create($sale, ['refund_method' => 'cash', 'items' => [['sale_item_id' => $item->id, 'quantity' => '2']]], $this->owner));
        $this->assertSame('220.0000', $r2->refund_total); // the remainder, exactly
        $this->assertSame('50.0000', $this->onHand($p));
        $this->assertSame('3000.0000', $this->in(fn () => Stock::where('product_id', $p->id)->value('total_value')));

        $this->assertRejected(fn () => $this->in(fn () => app(SaleService::class)->void($sale, 'late', $this->owner)), 'status');
    }

    public function test_refund_rules_for_walk_in_and_credit_sales(): void
    {
        $p = $this->product('100');
        $svc = app(SaleReturnService::class);

        $walkIn = $this->sell([$this->line($p)], [['method' => 'cash', 'amount' => '100']]);
        $this->assertRejected(fn () => $this->in(fn () => $svc->create($walkIn, ['refund_method' => 'account', 'items' => [['sale_item_id' => $walkIn->items->first()->id, 'quantity' => '1']]], $this->owner)), 'refund_method');

        // credit sale: 40 paid, 160 on account. Paying back cash is capped at what was really paid.
        $c = $this->customer(['credit_limit' => '1000']);
        $sale = $this->sell([$this->line($p, '2')], [['method' => 'cash', 'amount' => '40']], ['customer_id' => $c->id]);
        $item = $sale->items->first();
        $this->assertRejected(fn () => $this->in(fn () => $svc->create($sale, ['refund_method' => 'cash', 'items' => [['sale_item_id' => $item->id, 'quantity' => '1']]], $this->owner)), 'refund_method');

        $this->in(fn () => $svc->create($sale, ['refund_method' => 'account', 'items' => [['sale_item_id' => $item->id, 'quantity' => '1']]], $this->owner));
        $this->assertSame('60.0000', $this->owed($c)); // owed 160, minus the returned 100
    }

    public function test_return_documents_and_payments_are_immutable(): void
    {
        $p = $this->product('100');
        $sale = $this->sell([$this->line($p)], [['method' => 'cash', 'amount' => '100']]);
        $return = $this->in(fn () => app(SaleReturnService::class)->create($sale, ['refund_method' => 'cash', 'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1']]], $this->owner));
        $payment = $this->in(fn () => SalePayment::where('sale_id', $sale->id)->first());

        foreach ([fn () => $return->update(['notes' => 'x']), fn () => $return->delete(), fn () => $payment->update(['amount' => 1]), fn () => $payment->delete()] as $try) {
            try {
                $this->in($try);
                $this->fail('Expected an immutability error.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(1, $this->in(fn () => SaleReturn::count()));
    }

    public function test_branch_staff_sell_only_from_their_branch_warehouses(): void
    {
        $branchB = $this->makeBranch($this->business, 'BR2');
        $whB = $this->in(fn () => Warehouse::create(['name' => 'B', 'code' => 'WH-B', 'branch_id' => $branchB->id, 'is_active' => true])->id);
        $seller = $this->makeStaff($this->business, 'Cashier', [$branchB]);
        $p = $this->product('10', '5');

        $this->assertRejected(fn () => $this->sell([$this->line($p)], [['method' => 'cash', 'amount' => '10']], [], $seller));

        $this->in(fn () => app(StockService::class)->receive($whB, $p->id, null, '5', '5', 'purchase_received'));
        $sale = $this->sell([$this->line($p)], [['method' => 'cash', 'amount' => '10']], ['warehouse_id' => $whB], $seller);
        $this->assertSame($branchB->id, $sale->branch_id);
    }

    public function test_pos_endpoints_over_http(): void
    {
        $p = $this->product('100');
        $payload = ['warehouse_id' => $this->wh, 'items' => [['product_id' => $p->id, 'quantity' => '2']]];

        $this->actingAs($this->cashier)->get('/pos')->assertOk();
        $this->actingAs($this->cashier)->postJson('/pos/quote', $payload)->assertOk()->assertJsonPath('total', '200.0000');
        $this->actingAs($this->cashier)->postJson('/pos/hold', $payload)->assertCreated()->assertJsonPath('reference_no', 'HLD-000001');
        $this->actingAs($this->cashier)->getJson('/pos/held')->assertOk()->assertJsonCount(1, 'data');

        $res = $this->actingAs($this->cashier)->postJson('/pos/complete', $payload + ['payments' => [['method' => 'cash', 'amount' => '250']]])
            ->assertCreated()->assertJsonPath('change_due', '50.0000')->assertJsonPath('reference_no', 'INV-000001');
        $this->actingAs($this->cashier)->get($res->json('receipt_url'))->assertOk();
        $this->actingAs($this->cashier)->get('/sales/'.$res->json('id'))->assertOk();

        $this->actingAs($this->cashier)->postJson('/pos/complete', ['warehouse_id' => $this->wh, 'items' => []])->assertStatus(422);
        $this->actingAs($this->cashier)->postJson('/pos/quote', ['warehouse_id' => $this->wh, 'items' => [['product_id' => $p->id, 'quantity' => '1', 'discount' => '5']]])->assertStatus(422);
    }

    public function test_permissions_and_isolation_over_http(): void
    {
        $p = $this->product('100');
        $sale = $this->sell([$this->line($p)], [['method' => 'cash', 'amount' => '100']]);
        $keeper = $this->makeStaff($this->business, 'Storekeeper');

        $this->actingAs($this->cashier)->post("/sales/{$sale->id}/void", ['reason' => 'because'])->assertForbidden();
        $this->actingAs($this->cashier)->post("/sales/{$sale->id}/returns", ['refund_method' => 'cash', 'items' => []])->assertForbidden();
        $this->actingAs($keeper)->get('/pos')->assertForbidden();
        $this->actingAs($keeper)->get('/sales')->assertForbidden();

        [$other, $otherOwner] = $this->makeBusiness('Shop B');
        $theirs = $this->in(fn () => Sale::create(['reference_no' => 'INV-000001', 'warehouse_id' => Warehouse::where('is_default', true)->value('id'), 'status' => 'completed']), $other);
        $this->actingAs($this->owner)->get('/sales/'.$theirs->id)->assertNotFound();
        $theirProduct = $this->in(fn () => Product::create(['name' => 'X', 'sku' => 'X', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'purchase_price' => 1, 'selling_price' => 2, 'track_inventory' => true]), $other);
        $this->actingAs($this->owner)->postJson('/pos/quote', ['warehouse_id' => $this->wh, 'items' => [['product_id' => $theirProduct->id, 'quantity' => '1']]])->assertStatus(422);

        $this->actingAs($this->owner)->post("/sales/{$sale->id}/void", ['reason' => 'test'])->assertSessionHasNoErrors();
        $this->assertSame('voided', $this->in(fn () => Sale::find($sale->id)->status));
    }

    public function test_api_sales_and_dashboard_total(): void
    {
        $p = $this->product('100');
        $this->actingAs($this->owner, 'sanctum');

        $id = $this->postJson('/api/v1/sales', ['warehouse_id' => $this->wh, 'items' => [['product_id' => $p->id, 'quantity' => '3']], 'payments' => [['method' => 'card', 'amount' => '300']]])
            ->assertCreated()->assertJsonPath('data.total', '300.0000')->assertJsonPath('data.payment_status', 'paid')->json('data.id');

        $this->getJson("/api/v1/sales/$id")->assertOk()->assertJsonPath('data.items.0.quantity', '3.0000');
        $this->getJson('/api/v1/sales?payment_status=paid')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/sales', ['warehouse_id' => $this->wh])->assertStatus(422);

        $today = $this->in(fn () => app(\App\Services\Dashboard\DashboardService::class)->overview($this->owner))['sales_today'];
        $this->assertSame(['count' => 1, 'total' => '300.0000'], $today);
    }
}
