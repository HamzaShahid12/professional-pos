<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Catalog\MasterDataService;
use App\Services\Catalog\ProductService;
use App\Services\Inventory\AdjustmentService;
use App\Services\Inventory\InventoryQueryService;
use App\Services\Inventory\StockCountService;
use App\Services\Inventory\StockService;
use App\Services\Inventory\TransferService;
use App\Services\Settings\SettingsService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class InventoryTest extends TestCase
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
        $this->wh = $this->in(fn () => Warehouse::where('is_default', true)->value('id'));
        $this->actingAs($this->owner);
    }

    private function in(callable $fn, $business = null)
    {
        return app(TenantContext::class)->runAs(($business ?? $this->business)->id, $fn);
    }

    private function product(array $o = [], string $unit = 'pcs'): Product
    {
        static $n = 0;
        $n++;

        return $this->in(fn () => Product::create(array_merge([
            'name' => "Item $n", 'sku' => "SKU-$n", 'unit_id' => Unit::where('short_name', $unit)->value('id'),
            'purchase_price' => '10', 'selling_price' => '20', 'track_inventory' => true,
        ], $o)));
    }

    private function stock(): StockService
    {
        return app(StockService::class);
    }

    private function receive(Product $p, string $qty, string $cost, array $extra = [], ?int $wh = null)
    {
        return $this->in(fn () => $this->stock()->receive($wh ?? $this->wh, $p->id, null, $qty, $cost, $extra['type'] ?? 'purchase_received',
            null, null, $extra['batch'] ?? null, $extra['expiry'] ?? null));
    }

    private function issue(Product $p, string $qty, ?int $wh = null): array
    {
        return $this->in(fn () => $this->stock()->issue($wh ?? $this->wh, $p->id, null, $qty, 'sale'));
    }

    private function row(Product $p, ?int $wh = null): Stock
    {
        return $this->in(fn () => Stock::where('warehouse_id', $wh ?? $this->wh)->where('product_id', $p->id)->firstOrFail());
    }

    private function assertLedgerMatches(Product $p, ?int $wh = null): void
    {
        $row = $this->row($p, $wh);
        $sum = $this->in(fn () => StockMovement::where('warehouse_id', $row->warehouse_id)->where('product_id', $p->id)->selectRaw('SUM(quantity) q, SUM(total_cost) v')->first());
        $this->assertSame(0, bccomp((string) $sum->q, $row->quantity, 4), 'ledger quantity must equal stock quantity');
        $this->assertSame(0, bccomp((string) $sum->v, $row->total_value, 4), 'ledger value must equal stock value');
    }

    // ---- costing -----------------------------------------------------------------------------

    public function test_weighted_average_cost_and_exact_zero_value_when_emptied(): void
    {
        $p = $this->product();
        $this->receive($p, '10', '10');
        $this->receive($p, '10', '20'); // avg 15
        $out = $this->issue($p, '5');
        $this->assertSame('15.0000', $out['unit_cost']);
        $this->assertSame('75.0000', $out['total_cost']);
        $this->assertSame('225.0000', $this->row($p)->total_value);

        $this->issue($p, '15');
        $this->assertSame('0.0000', $this->row($p)->quantity);
        $this->assertSame('0.0000', $this->row($p)->total_value);
        $this->assertLedgerMatches($p);
    }

    public function test_average_rounding_residue_never_leaves_value_behind(): void
    {
        $p = $this->product();
        $this->receive($p, '3', '10.3333');
        $this->issue($p, '1');
        $this->issue($p, '1');
        $this->issue($p, '1');
        $this->assertSame('0.0000', $this->row($p)->total_value);
        $this->assertLedgerMatches($p);
    }

    public function test_fifo_consumes_oldest_layer_first(): void
    {
        $this->in(fn () => app(SettingsService::class)->setMany(['inventory.costing_method' => 'fifo']));
        $p = $this->product();
        $this->receive($p, '10', '10');
        $this->receive($p, '10', '20');
        $out = $this->issue($p, '15');

        $this->assertSame('200.0000', $out['total_cost']); // 10*10 + 5*20
        $this->assertCount(2, $out['lines']);
        $this->assertSame('100.0000', $this->row($p)->total_value);
        $this->assertLedgerMatches($p);
    }

    public function test_expiry_products_use_first_expired_first_out_and_require_batch_data(): void
    {
        $p = $this->product(['track_expiry' => true]);
        $this->receive($p, '5', '10', ['batch' => 'LATE', 'expiry' => '2027-12-31']);
        $this->receive($p, '5', '12', ['batch' => 'SOON', 'expiry' => '2027-01-31']);

        $out = $this->issue($p, '6');
        $this->assertSame('SOON', $out['lines'][0]['batch_number']);
        $this->assertSame('5.0000', $out['lines'][0]['quantity']);
        $this->assertSame('LATE', $out['lines'][1]['batch_number']);

        try {
            $this->receive($p, '1', '10');
            $this->fail('A batch number and expiry date must be required.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }
    }

    // ---- guards ------------------------------------------------------------------------------

    public function test_insufficient_stock_is_rejected_and_nothing_changes(): void
    {
        $p = $this->product();
        $this->receive($p, '5', '10');

        try {
            $this->issue($p, '6');
            $this->fail('Should have been rejected.');
        } catch (ValidationException) {
        }

        $this->assertSame('5.0000', $this->row($p)->quantity);
        $this->assertSame(1, $this->in(fn () => StockMovement::where('product_id', $p->id)->count()));
    }

    public function test_negative_stock_only_when_the_setting_allows_it(): void
    {
        $this->in(fn () => app(SettingsService::class)->setMany(['inventory.allow_negative_stock' => true]));
        $p = $this->product(['purchase_price' => '8']);
        $this->receive($p, '2', '10');
        $out = $this->issue($p, '5');

        $this->assertSame('-3.0000', $this->row($p)->quantity);
        $this->assertSame('44.0000', $out['total_cost']); // 2*10 + 3*8 (purchase price)
        $this->assertLedgerMatches($p);
    }

    public function test_decimal_quantities_follow_the_unit_and_untracked_products_are_rejected(): void
    {
        $pcs = $this->product();
        $kg = $this->product([], 'kg');
        $untracked = $this->product(['track_inventory' => false]);

        $this->receive($kg, '2.5', '100');
        $this->assertSame('2.5000', $this->row($kg)->quantity);

        foreach ([[$pcs, '1.5'], [$untracked, '1'], [$pcs, '0'], [$pcs, '-2']] as [$p, $q]) {
            try {
                $this->receive($p, $q, '10');
                $this->fail("Quantity $q should be rejected for {$p->name}.");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_variant_products_need_a_variant_and_simple_products_reject_one(): void
    {
        $simple = $this->product();
        $varied = $this->product(['has_variants' => true]);
        $variant = $this->in(fn () => ProductVariant::create(['product_id' => $varied->id, 'name' => 'Red', 'sku' => 'V-RED']));

        $this->assertRejected(fn () => $this->stock()->receive($this->wh, $varied->id, null, '1', '1', 'purchase_received'));
        $this->assertRejected(fn () => $this->stock()->receive($this->wh, $simple->id, $variant->id, '1', '1', 'purchase_received'));

        $this->in(fn () => $this->stock()->receive($this->wh, $varied->id, $variant->id, '3', '5', 'purchase_received'));
        $this->assertSame('3.0000', $this->in(fn () => Stock::where('variant_id', $variant->id)->value('quantity')));
    }

    /** Runs the callback in the business context and asserts it is refused with a validation error. */
    private function assertRejected(callable $fn): void
    {
        try {
            $this->in($fn);
        } catch (ValidationException) {
            $this->assertTrue(true);

            return;
        }
        $this->fail('Expected the operation to be rejected.');
    }

    public function test_ledger_rows_are_immutable(): void
    {
        $p = $this->product();
        $m = $this->receive($p, '1', '1');

        $this->expectException(LogicException::class);
        $m->update(['quantity' => '99']);
    }

    public function test_wrong_direction_types_are_programming_errors(): void
    {
        $p = $this->product();
        $this->expectException(LogicException::class);
        $this->in(fn () => $this->stock()->issue($this->wh, $p->id, null, '1', 'purchase_received'));
    }

    // ---- adjustments -------------------------------------------------------------------------

    private function adjust(array $o = [], $user = null)
    {
        return $this->in(fn () => app(AdjustmentService::class)->create(array_merge([
            'warehouse_id' => $this->wh, 'direction' => 'in', 'reason' => 'opening_stock', 'adjusted_at' => '2026-11-19', 'items' => [],
        ], $o), $user ?? $this->owner));
    }

    public function test_adjustments_get_sequential_numbers_and_post_opening_movements(): void
    {
        $p = $this->product();
        $a1 = $this->adjust(['items' => [['product_id' => $p->id, 'quantity' => '10', 'unit_cost' => '7']]]);
        $a2 = $this->adjust(['direction' => 'out', 'reason' => 'damaged', 'items' => [['product_id' => $p->id, 'quantity' => '4']]]);

        $this->assertSame('ADJ-000001', $a1->reference_no);
        $this->assertSame('ADJ-000002', $a2->reference_no);
        $this->assertSame('opening', $this->in(fn () => StockMovement::where('reference_id', $a1->id)->value('type')));
        $this->assertSame('28.0000', $a2->items->first()->total_cost);
        $this->assertSame('42.0000', $this->row($p)->total_value);
        $this->assertLedgerMatches($p);
    }

    public function test_adjustment_reason_must_match_direction_and_failed_adjustment_burns_no_number(): void
    {
        $p = $this->product();

        foreach ([['in', 'damaged'], ['out', 'opening_stock']] as [$dir, $reason]) {
            try {
                $this->adjust(['direction' => $dir, 'reason' => $reason, 'items' => [['product_id' => $p->id, 'quantity' => '1', 'unit_cost' => '1']]]);
                $this->fail('Reason/direction mismatch must be rejected.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        try {
            $this->adjust(['direction' => 'out', 'reason' => 'damaged', 'items' => [['product_id' => $p->id, 'quantity' => '1']]]); // no stock
        } catch (ValidationException) {
        }
        $this->assertSame(0, $this->in(fn () => StockAdjustment::count()));

        $ok = $this->adjust(['items' => [['product_id' => $p->id, 'quantity' => '1', 'unit_cost' => '1']]]);
        $this->assertSame('ADJ-000001', $ok->reference_no);
    }

    public function test_document_numbers_are_sequential_per_business(): void
    {
        [$other] = $this->makeBusiness('Shop B');
        $numbers = app(\App\Services\DocumentNumberService::class);

        $a = $this->in(fn () => \DB::transaction(fn () => [$numbers->next('adjustment', 'ADJ'), $numbers->next('adjustment', 'ADJ')]));
        $b = $this->in(fn () => \DB::transaction(fn () => $numbers->next('adjustment', 'ADJ')), $other);

        $this->assertSame(['ADJ-000001', 'ADJ-000002'], $a);
        $this->assertSame('ADJ-000001', $b);
    }

    // ---- transfers ---------------------------------------------------------------------------

    private function secondWarehouse(): int
    {
        return $this->in(fn () => Warehouse::create(['name' => 'Back store', 'code' => 'WH-2', 'is_active' => true])->id);
    }

    public function test_transfer_moves_stock_cost_and_batch_atomically(): void
    {
        $p = $this->product(['track_expiry' => true]);
        $this->receive($p, '10', '10', ['batch' => 'B1', 'expiry' => '2027-05-01']);
        $to = $this->secondWarehouse();

        $t = $this->in(fn () => app(TransferService::class)->create([
            'from_warehouse_id' => $this->wh, 'to_warehouse_id' => $to, 'transferred_at' => '2026-11-19',
            'items' => [['product_id' => $p->id, 'quantity' => '4']],
        ], $this->owner));

        $this->assertSame('TRF-000001', $t->reference_no);
        $this->assertSame('6.0000', $this->row($p)->quantity);
        $this->assertSame('4.0000', $this->row($p, $to)->quantity);
        $this->assertSame('40.0000', $this->row($p, $to)->total_value);
        $batch = $this->in(fn () => ProductBatch::where('warehouse_id', $to)->first());
        $this->assertSame('B1', $batch->batch_number);
        $this->assertSame('2027-05-01', $batch->expiry_date->toDateString());
        $this->assertLedgerMatches($p);
        $this->assertLedgerMatches($p, $to);
    }

    public function test_failed_transfer_rolls_back_every_line(): void
    {
        $a = $this->product();
        $b = $this->product();
        $this->receive($a, '5', '10');
        $this->receive($b, '1', '10');
        $to = $this->secondWarehouse();

        try {
            $this->in(fn () => app(TransferService::class)->create([
                'from_warehouse_id' => $this->wh, 'to_warehouse_id' => $to, 'transferred_at' => '2026-11-19',
                'items' => [['product_id' => $a->id, 'quantity' => '2'], ['product_id' => $b->id, 'quantity' => '9']],
            ], $this->owner));
            $this->fail('Should fail.');
        } catch (ValidationException) {
        }

        $this->assertSame('5.0000', $this->row($a)->quantity);
        $this->assertSame(0, $this->in(fn () => Stock::where('warehouse_id', $to)->count()));
    }

    public function test_transfer_to_same_warehouse_is_rejected(): void
    {
        $p = $this->product();
        $this->receive($p, '5', '10');
        $this->expectException(ValidationException::class);
        $this->in(fn () => app(TransferService::class)->create([
            'from_warehouse_id' => $this->wh, 'to_warehouse_id' => $this->wh, 'transferred_at' => '2026-11-19',
            'items' => [['product_id' => $p->id, 'quantity' => '1']],
        ], $this->owner));
    }

    // ---- stock counts ------------------------------------------------------------------------

    public function test_count_posts_differences_and_completed_count_is_final(): void
    {
        $a = $this->product();
        $b = $this->product();
        $c = $this->product();
        foreach ([$a, $b, $c] as $p) {
            $this->receive($p, '10', '10');
        }

        $svc = app(StockCountService::class);
        $count = $this->in(fn () => $svc->create($this->wh, null, $this->owner));
        $this->assertSame('CNT-000001', $count->reference_no);
        $items = $count->items->keyBy('product_id');

        $this->in(fn () => $svc->saveCounts($count, [
            ['id' => $items[$a->id]->id, 'counted_qty' => '8'],
            ['id' => $items[$b->id]->id, 'counted_qty' => '12'],
        ], $this->owner));

        $this->in(fn () => $svc->complete($count, $this->owner));

        $this->assertSame('8.0000', $this->row($a)->quantity);
        $this->assertSame('12.0000', $this->row($b)->quantity);
        $this->assertSame('10.0000', $this->row($c)->quantity); // not counted, untouched
        $this->assertSame('-2.0000', $this->in(fn () => $items[$a->id]->fresh())->difference);
        $this->assertLedgerMatches($a);
        $this->assertLedgerMatches($b);

        $this->expectException(ValidationException::class);
        $this->in(fn () => $svc->complete($count, $this->owner));
    }

    public function test_cancelled_count_changes_no_stock_and_cannot_be_completed(): void
    {
        $p = $this->product();
        $this->receive($p, '10', '10');
        $svc = app(StockCountService::class);
        $count = $this->in(fn () => $svc->create($this->wh, null, $this->owner));
        $this->in(fn () => $svc->cancel($count, $this->owner));

        $this->assertSame('10.0000', $this->row($p)->quantity);
        $this->expectException(ValidationException::class);
        $this->in(fn () => $svc->complete($count, $this->owner));
    }

    // ---- overview, alerts, valuation ---------------------------------------------------------

    public function test_overview_alerts_and_valuation(): void
    {
        $ok = $this->product(['min_stock' => '2']);
        $low = $this->product(['min_stock' => '5']);
        $out = $this->product(['min_stock' => '1']);
        $this->receive($ok, '10', '10');
        $this->receive($low, '3', '10');
        $this->receive($out, '1', '10');
        $this->issue($out, '1');

        $q = app(InventoryQueryService::class);
        $this->assertSame(['low' => 1, 'out' => 1], $this->in(fn () => $q->alertCounts($this->owner)));
        $this->assertSame('130.0000', $this->in(fn () => $q->valuation($this->owner))['value']);

        $rows = $this->in(fn () => collect($q->overview($this->owner, ['status' => 'low'])->items()));
        $this->assertSame([$low->id], $rows->pluck('product_id')->all());
        $this->assertCount(2, $this->in(fn () => $q->alerts($this->owner)));
    }

    public function test_expiry_report_lists_expired_and_expiring_batches_only(): void
    {
        $p = $this->product(['track_expiry' => true]);
        $this->receive($p, '1', '1', ['batch' => 'OLD', 'expiry' => now()->subDays(3)->toDateString()]);
        $this->receive($p, '1', '1', ['batch' => 'SOON', 'expiry' => now()->addDays(10)->toDateString()]);
        $this->receive($p, '1', '1', ['batch' => 'FAR', 'expiry' => now()->addDays(200)->toDateString()]);

        $rows = collect($this->in(fn () => app(InventoryQueryService::class)->expiry($this->owner, 30)))->keyBy('batch_number');
        $this->assertSame(['OLD', 'SOON'], $rows->keys()->sort()->values()->all());
        $this->assertSame('expired', $rows['OLD']['status']);
    }

    // ---- isolation & permissions -------------------------------------------------------------

    public function test_branch_users_only_see_and_use_their_branch_warehouses(): void
    {
        $branchB = $this->makeBranch($this->business, 'BR2');
        $whB = $this->in(fn () => Warehouse::create(['name' => 'B store', 'code' => 'WH-B', 'branch_id' => $branchB->id, 'is_active' => true])->id);
        $storekeeper = $this->makeStaff($this->business, 'Storekeeper', [$branchB]);

        $p = $this->product();
        $this->receive($p, '5', '10', [], $whB);
        $this->receive($p, '5', '10');

        $q = app(InventoryQueryService::class);
        $rows = $this->in(fn () => $q->overview($storekeeper)->items());
        $this->assertSame('5.0000', $rows[0]['quantity']);
        $this->assertSame('5.0000', $this->in(fn () => $q->valuation($storekeeper))['quantity']);
        $this->assertSame(1, $this->in(fn () => $q->movements($storekeeper)->total()));

        $this->expectException(ValidationException::class);
        $this->in(fn () => app(AdjustmentService::class)->create([
            'warehouse_id' => $this->wh, 'direction' => 'in', 'reason' => 'found', 'adjusted_at' => '2026-11-19',
            'items' => [['product_id' => $p->id, 'quantity' => '1', 'unit_cost' => '1']],
        ], $storekeeper));
    }

    public function test_other_business_data_is_invisible_and_unusable(): void
    {
        [$other] = $this->makeBusiness('Shop B');
        $theirs = $this->in(fn () => Product::create(['name' => 'X', 'sku' => 'X1', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'purchase_price' => 1, 'selling_price' => 2, 'track_inventory' => true]), $other);
        $theirWarehouse = $this->in(fn () => Warehouse::where('is_default', true)->value('id'), $other);

        $this->assertRejected(fn () => $this->stock()->receive($this->wh, $theirs->id, null, '1', '1', 'purchase_received'));
        $mine = $this->product();
        $this->assertRejected(fn () => $this->stock()->receive($theirWarehouse, $mine->id, null, '1', '1', 'purchase_received'));
    }

    public function test_inventory_pages_respect_permissions(): void
    {
        $cashier = $this->makeStaff($this->business, 'Cashier');
        $keeper = $this->makeStaff($this->business, 'Storekeeper');

        $this->actingAs($cashier)->get('/inventory')->assertForbidden();
        $this->actingAs($cashier)->post('/inventory/adjustments', [])->assertForbidden();

        $this->actingAs($keeper)->get('/inventory')->assertOk();
        $this->actingAs($keeper)->get('/inventory/adjustments/create')->assertOk();
        $this->actingAs($keeper)->get('/inventory/movements')->assertOk();
    }

    public function test_api_adjustment_and_listing_endpoints(): void
    {
        $p = $this->product();
        $this->actingAs($this->owner, 'sanctum');

        $res = $this->postJson('/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->wh, 'direction' => 'in', 'reason' => 'opening_stock', 'adjusted_at' => '2026-11-19',
            'items' => [['product_id' => $p->id, 'quantity' => '12', 'unit_cost' => '5']],
        ])->assertCreated();
        $this->assertSame('ADJ-000001', $res->json('data.reference_no'));

        $this->getJson('/api/v1/inventory')->assertOk()->assertJsonPath('data.0.quantity', '12.0000')->assertJsonPath('valuation.value', '60.0000');
        $this->getJson('/api/v1/inventory/movements')->assertOk()->assertJsonPath('data.0.type', 'opening');

        $this->postJson('/api/v1/inventory/adjustments', ['warehouse_id' => $this->wh])->assertStatus(422);
        $this->postJson('/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->wh, 'direction' => 'out', 'reason' => 'damaged', 'adjusted_at' => '2026-11-19',
            'items' => [['product_id' => $p->id, 'quantity' => '99']],
        ])->assertStatus(422);
    }

    public function test_product_with_stock_cannot_be_archived_or_lose_tracking_and_warehouse_with_history_cannot_be_deleted(): void
    {
        $p = $this->product();
        $this->receive($p, '3', '10');
        $to = $this->secondWarehouse();

        $this->in(function () use ($p) {
            try {
                app(ProductService::class)->archive($p);
                $this->fail('Archive must be blocked.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
            try {
                app(ProductService::class)->update($this->in(fn () => $p->fresh()), ['track_inventory' => false], [], $this->owner);
                $this->fail('Untracking must be blocked.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        });

        $this->issue($p, '3');
        $wh = $this->in(fn () => Warehouse::find($this->wh));
        $wh->is_default = false;
        $this->in(function () use ($wh) {
            try {
                app(MasterDataService::class)->deleteWarehouse($wh);
                $this->fail('Warehouse with history must not be deleted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        });

        $this->in(fn () => app(MasterDataService::class)->deleteWarehouse(Warehouse::find($to)));
        $this->in(fn () => app(ProductService::class)->archive($p));
        $this->assertSoftDeleted('products', ['id' => $p->id]);
    }
}
