<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Parties\PartyService;
use App\Services\Purchasing\PurchaseReturnService;
use App\Services\Purchasing\PurchaseService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PurchasesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private $business;

    private $owner;

    private int $wh;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
        [$this->business, $this->owner] = $this->makeBusiness();
        $this->actingAs($this->owner);
        $this->wh = $this->in(fn () => Warehouse::where('is_default', true)->value('id'));
        $this->supplier = $this->in(fn () => app(PartyService::class)->create('supplier', ['name' => 'Acme Wholesale']));
    }

    private function in(callable $fn, $business = null)
    {
        return app(TenantContext::class)->runAs(($business ?? $this->business)->id, $fn);
    }

    private function product(array $o = [], array $taxIds = []): Product
    {
        static $n = 0;
        $n++;
        $p = $this->in(fn () => Product::create(array_merge([
            'name' => "Goods $n", 'sku' => "G-$n", 'unit_id' => Unit::where('short_name', 'pcs')->value('id'),
            'purchase_price' => '10', 'selling_price' => '20', 'track_inventory' => true,
        ], $o)));
        if ($taxIds) {
            $this->in(fn () => $p->taxes()->sync($taxIds));
        }

        return $p;
    }

    private function tax(string $rate, bool $inclusive): int
    {
        return $this->in(fn () => Tax::create(['name' => "T$rate", 'rate' => $rate, 'is_inclusive' => $inclusive])->id);
    }

    private function usd(string $rate = '280'): Currency
    {
        return $this->in(fn () => Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'exchange_rate' => $rate, 'is_base' => false, 'is_active' => true]));
    }

    private function data(array $items, array $o = []): array
    {
        return array_merge(['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->wh, 'order_date' => '2026-11-20', 'items' => $items], $o);
    }

    private function make(array $items, array $o = [], bool $receive = false, $user = null): Purchase
    {
        return $this->in(fn () => app(PurchaseService::class)->create($this->data($items, $o), $user ?? $this->owner, $receive));
    }

    private function stockQty(Product $p): string
    {
        return $this->in(fn () => Stock::where('warehouse_id', $this->wh)->where('product_id', $p->id)->value('quantity') ?? '0');
    }

    private function balance(): string
    {
        return $this->in(fn () => Supplier::find($this->supplier->id)->balance);
    }

    public function test_an_order_computes_totals_but_moves_no_stock_or_money(): void
    {
        $gst = $this->tax('18', false);
        $p = $this->product([], [$gst]);
        $purchase = $this->make([['product_id' => $p->id, 'quantity' => '10', 'unit_cost' => '50', 'discount' => '100']]);

        $this->assertSame('PUR-000001', $purchase->reference_no);
        $this->assertSame('ordered', $purchase->status);
        $this->assertSame('400.0000', $purchase->subtotal);
        $this->assertSame('72.0000', $purchase->tax_total);
        $this->assertSame('472.0000', $purchase->total);
        $this->assertSame('0', $this->stockQty($p));
        $this->assertSame('0.0000', $this->balance());
    }

    public function test_inclusive_tax_is_extracted_from_the_entered_cost(): void
    {
        $p = $this->product([], [$this->tax('18', true)]);
        $purchase = $this->make([['product_id' => $p->id, 'quantity' => '1', 'unit_cost' => '118']]);

        $this->assertSame('100.0000', $purchase->subtotal);
        $this->assertSame('18.0000', $purchase->tax_total);
        $this->assertSame('118.0000', $purchase->total);
    }

    public function test_receiving_adds_stock_at_net_cost_and_makes_the_supplier_owed(): void
    {
        $p = $this->product([], [$this->tax('18', false)]);
        $purchase = $this->make([['product_id' => $p->id, 'quantity' => '10', 'unit_cost' => '50']]);
        $received = $this->in(fn () => app(PurchaseService::class)->receive($purchase, $this->owner));

        $this->assertSame('received', $received->status);
        $this->assertSame('10.0000', $this->stockQty($p));
        $this->assertSame('500.0000', $this->in(fn () => Stock::where('product_id', $p->id)->value('total_value'))); // tax is not stock cost
        $this->assertSame('590.0000', $this->balance()); // 500 + 18%
        $this->assertSame('purchase_received', $this->in(fn () => StockMovement::where('product_id', $p->id)->value('type')));
        $this->assertSame('590.0000', $received->total_base);
    }

    public function test_received_purchases_are_final_and_cannot_be_received_twice_or_cancelled(): void
    {
        $p = $this->product();
        $purchase = $this->make([['product_id' => $p->id, 'quantity' => '2', 'unit_cost' => '5']], [], true);

        foreach ([
            fn () => app(PurchaseService::class)->receive($purchase, $this->owner),
            fn () => app(PurchaseService::class)->cancel($purchase),
            fn () => app(PurchaseService::class)->update($purchase, $this->data([['product_id' => $p->id, 'quantity' => '9', 'unit_cost' => '1']]), $this->owner),
        ] as $try) {
            try {
                $this->in($try);
                $this->fail('A received purchase must not change.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('2.0000', $this->stockQty($p));
        $this->assertSame('10.0000', $this->balance());
    }

    public function test_payment_at_receiving_reduces_what_is_owed(): void
    {
        $p = $this->product();
        $purchase = $this->make([['product_id' => $p->id, 'quantity' => '10', 'unit_cost' => '10']], ['paid_amount' => '30', 'payment_method' => 'bank'], true);

        $this->assertSame('70.0000', $this->balance());
        $this->assertSame('30.0000', $purchase->paid_base);
        $this->assertSame('PAY-000001', $this->in(fn () => \App\Models\PartyPayment::value('reference_no')));

        $second = $this->make([['product_id' => $p->id, 'quantity' => '1', 'unit_cost' => '10']]);
        try {
            $this->in(fn () => app(PurchaseService::class)->receive($second, $this->owner, '11'));
            $this->fail('Overpaying must be rejected.');
        } catch (ValidationException) {
            $this->assertSame('ordered', $this->in(fn () => $second->fresh())->status);
            $this->assertSame('70.0000', $this->balance()); // nothing was posted
            $this->assertSame('10.0000', $this->stockQty($p));
        }
    }

    public function test_foreign_currency_is_converted_at_the_rate_of_the_receiving_day_and_then_frozen(): void
    {
        $usd = $this->usd('280');
        $p = $this->product();
        $purchase = $this->make([['product_id' => $p->id, 'quantity' => '5', 'unit_cost' => '10']], ['currency_id' => $usd->id]);

        $this->assertSame('50.0000', $purchase->total);
        $this->in(fn () => app(\App\Services\CurrencyService::class)->setRate($usd->fresh(), '300', null, $this->owner));

        $received = $this->in(fn () => app(PurchaseService::class)->receive($purchase, $this->owner));
        $this->assertSame(0, bccomp('300', (string) $received->exchange_rate, 8));
        $this->assertSame('15000.0000', $received->total_base);
        $this->assertSame('15000.0000', $this->in(fn () => Stock::where('product_id', $p->id)->value('total_value')));

        $this->in(fn () => app(\App\Services\CurrencyService::class)->setRate($usd->fresh(), '400', null, $this->owner));
        $this->assertSame(0, bccomp('300', (string) $this->in(fn () => $received->fresh())->exchange_rate, 8)); // a received purchase keeps its rate
        $this->assertSame('15000.0000', $this->balance());
    }

    public function test_an_ordered_purchase_can_be_edited_or_cancelled(): void
    {
        $a = $this->product();
        $b = $this->product();
        $purchase = $this->make([['product_id' => $a->id, 'quantity' => '1', 'unit_cost' => '10']]);

        $updated = $this->in(fn () => app(PurchaseService::class)->update($purchase, $this->data([['product_id' => $b->id, 'quantity' => '4', 'unit_cost' => '25']]), $this->owner));
        $this->assertSame('100.0000', $updated->total);
        $this->assertCount(1, $updated->items);
        $this->assertSame($b->id, $updated->items->first()->product_id);

        $this->in(fn () => app(PurchaseService::class)->cancel($purchase));
        $this->assertSame('cancelled', $this->in(fn () => $purchase->fresh())->status);

        $this->expectException(ValidationException::class);
        $this->in(fn () => app(PurchaseService::class)->receive($purchase, $this->owner));
    }

    public function test_a_failed_receipt_rolls_back_stock_and_supplier_balance(): void
    {
        $ok = $this->product();
        $expiring = $this->product(['track_expiry' => true]);
        $purchase = $this->make([
            ['product_id' => $ok->id, 'quantity' => '3', 'unit_cost' => '10'],
            ['product_id' => $expiring->id, 'quantity' => '3', 'unit_cost' => '10'], // no batch / expiry
        ]);

        try {
            $this->in(fn () => app(PurchaseService::class)->receive($purchase, $this->owner));
            $this->fail('Expiry data is required.');
        } catch (ValidationException) {
            $this->assertSame('ordered', $this->in(fn () => $purchase->fresh())->status);
            $this->assertSame('0', $this->stockQty($ok));
            $this->assertSame('0.0000', $this->balance());
        }
    }

    public function test_returns_remove_stock_credit_the_supplier_and_cannot_exceed_what_was_bought(): void
    {
        $p = $this->product([], [$this->tax('10', false)]);
        $purchase = $this->make([['product_id' => $p->id, 'quantity' => '10', 'unit_cost' => '30']], [], true); // total 330
        $item = $purchase->items->first();
        $svc = app(PurchaseReturnService::class);

        $r1 = $this->in(fn () => $svc->create($purchase, ['return_date' => '2026-11-21', 'items' => [['purchase_item_id' => $item->id, 'quantity' => '3']]], $this->owner));
        $this->assertSame('PRT-000001', $r1->reference_no);
        $this->assertSame('99.0000', $r1->total_base); // 3 * 33
        $this->assertSame('7.0000', $this->stockQty($p));
        $this->assertSame('231.0000', $this->balance());

        try {
            $this->in(fn () => $svc->create($purchase, ['return_date' => '2026-11-21', 'items' => [['purchase_item_id' => $item->id, 'quantity' => '8']]], $this->owner));
            $this->fail('Over-return must be rejected.');
        } catch (ValidationException) {
            $this->assertSame('7.0000', $this->stockQty($p));
        }

        $r2 = $this->in(fn () => $svc->create($purchase, ['return_date' => '2026-11-22', 'items' => [['purchase_item_id' => $item->id, 'quantity' => '7']]], $this->owner));
        $this->assertSame('231.0000', $r2->total_base); // the remainder, exactly
        $this->assertSame('0.0000', $this->balance());
        $this->assertSame('0.0000', $this->stockQty($p));
    }

    public function test_a_return_is_refused_when_the_goods_were_already_sold(): void
    {
        $p = $this->product();
        $purchase = $this->make([['product_id' => $p->id, 'quantity' => '5', 'unit_cost' => '10']], [], true);
        $this->in(fn () => app(\App\Services\Inventory\StockService::class)->issue($this->wh, $p->id, null, '4', 'sale'));

        try {
            $this->in(fn () => app(PurchaseReturnService::class)->create($purchase, ['return_date' => '2026-11-21', 'items' => [['purchase_item_id' => $purchase->items->first()->id, 'quantity' => '3']]], $this->owner));
            $this->fail('Not enough stock to send back.');
        } catch (ValidationException) {
            $this->assertSame('1.0000', $this->stockQty($p));
            $this->assertSame('50.0000', $this->balance());
            $this->assertSame(0, $this->in(fn () => \App\Models\PurchaseReturn::count()));
        }
    }

    public function test_return_documents_are_immutable_and_only_received_purchases_can_be_returned(): void
    {
        $p = $this->product();
        $ordered = $this->make([['product_id' => $p->id, 'quantity' => '1', 'unit_cost' => '1']]);

        try {
            $this->in(fn () => app(PurchaseReturnService::class)->create($ordered, ['return_date' => '2026-11-21', 'items' => [['purchase_item_id' => $ordered->items->first()->id, 'quantity' => '1']]], $this->owner));
            $this->fail('Ordered purchases cannot be returned.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $received = $this->make([['product_id' => $p->id, 'quantity' => '2', 'unit_cost' => '1']], [], true);
        $return = $this->in(fn () => app(PurchaseReturnService::class)->create($received, ['return_date' => '2026-11-21', 'items' => [['purchase_item_id' => $received->items->first()->id, 'quantity' => '1']]], $this->owner));

        $this->expectException(LogicException::class);
        $this->in(fn () => $return->update(['notes' => 'edit']));
    }

    public function test_business_isolation_and_permissions_over_http(): void
    {
        [$other] = $this->makeBusiness('Shop B');
        $theirWh = $this->in(fn () => Warehouse::where('is_default', true)->value('id'), $other);
        $theirSupplier = $this->in(fn () => app(PartyService::class)->create('supplier', ['name' => 'Theirs']), $other);
        $theirProduct = $this->in(fn () => Product::create(['name' => 'X', 'sku' => 'X', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'purchase_price' => 1, 'selling_price' => 2, 'track_inventory' => true]), $other);
        $theirPurchase = $this->in(fn () => Purchase::create(['reference_no' => 'PUR-000001', 'supplier_id' => $theirSupplier->id, 'warehouse_id' => $theirWh, 'currency_id' => Currency::where('is_base', true)->value('id'), 'order_date' => '2026-11-20']), $other);

        $this->get('/purchases/'.$theirPurchase->id)->assertNotFound();
        $this->post('/purchases', $this->data([['product_id' => $theirProduct->id, 'quantity' => '1', 'unit_cost' => '1']]))->assertSessionHasErrors('items.0.product_id');

        $cashier = $this->makeStaff($this->business, 'Cashier');
        $keeper = $this->makeStaff($this->business, 'Storekeeper');
        $this->actingAs($cashier)->get('/purchases')->assertForbidden();
        $this->actingAs($keeper)->get('/purchases')->assertOk();
        $this->actingAs($keeper)->get('/purchases/create')->assertForbidden(); // storekeepers receive, they do not raise orders

        $p = $this->product();
        $order = $this->make([['product_id' => $p->id, 'quantity' => '1', 'unit_cost' => '1']]);
        $this->actingAs($keeper)->post("/purchases/{$order->id}/receive", [])->assertSessionHasNoErrors();
        $this->assertSame('received', $this->in(fn () => $order->fresh())->status);
        $this->actingAs($keeper)->post("/purchases/{$order->id}/returns", ['return_date' => '2026-11-21', 'items' => [['purchase_item_id' => $order->items->first()->id, 'quantity' => '1']]])->assertForbidden();
    }

    public function test_branch_staff_cannot_buy_into_another_branchs_warehouse(): void
    {
        $branchB = $this->makeBranch($this->business, 'BR2');
        $whB = $this->in(fn () => Warehouse::create(['name' => 'B', 'code' => 'WH-B', 'branch_id' => $branchB->id, 'is_active' => true])->id);
        $manager = $this->makeStaff($this->business, 'Branch Manager', [$branchB]);
        $p = $this->product();

        try {
            $this->make([['product_id' => $p->id, 'quantity' => '1', 'unit_cost' => '1']], [], false, $manager);
            $this->fail('Warehouse of another branch must be refused.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $ok = $this->make([['product_id' => $p->id, 'quantity' => '1', 'unit_cost' => '1']], ['warehouse_id' => $whB], false, $manager);
        $this->assertSame($branchB->id, $ok->branch_id);
    }

    public function test_web_and_api_flows(): void
    {
        $p = $this->product();
        $payload = $this->data([['product_id' => $p->id, 'quantity' => '4', 'unit_cost' => '25']], ['receive_now' => true, 'paid_amount' => '40']);

        $this->post('/purchases', $payload)->assertRedirect();
        $this->assertSame('4.0000', $this->stockQty($p));
        $this->assertSame('60.0000', $this->balance());
        $this->get('/purchases')->assertOk();
        $this->get('/purchases/create')->assertOk();

        $this->actingAs($this->owner, 'sanctum');
        $id = $this->postJson('/api/v1/purchases', $this->data([['product_id' => $p->id, 'quantity' => '2', 'unit_cost' => '10']]))
            ->assertCreated()->assertJsonPath('data.status', 'ordered')->assertJsonPath('data.total', '20.0000')->json('data.id');
        $this->postJson("/api/v1/purchases/$id/receive", [])->assertOk()->assertJsonPath('data.status', 'received');
        $this->getJson('/api/v1/purchases?status=received')->assertOk()->assertJsonPath('meta.total', 2);
        $this->postJson('/api/v1/purchases', ['supplier_id' => $this->supplier->id])->assertStatus(422);
    }
}
