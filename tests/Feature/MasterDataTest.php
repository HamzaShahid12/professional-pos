<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private $business;
    private $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
        [$this->business, $this->owner] = $this->makeBusiness();
    }

    private function in($business, callable $fn)
    {
        return app(TenantContext::class)->runAs($business->id, $fn);
    }

    private function makeProduct(array $attrs = []): Product
    {
        return $this->in($this->business, fn () => Product::create($attrs + [
            'name' => 'P', 'sku' => 'P-'.uniqid(), 'unit_id' => Unit::where('short_name', 'pcs')->value('id'),
            'purchase_price' => 1, 'selling_price' => 2,
        ]));
    }

    // ---- new business defaults -------------------------------------------------------------------

    public function test_new_business_gets_default_units_and_a_default_warehouse(): void
    {
        $this->in($this->business, function () {
            $this->assertSame(8, Unit::count());
            $this->assertTrue(Unit::where('short_name', 'kg')->value('allow_decimal'));
            $this->assertFalse(Unit::where('short_name', 'pcs')->value('allow_decimal'));
            $w = Warehouse::sole();
            $this->assertTrue($w->is_default);
            $this->assertSame('WH-MAIN', $w->code);
            $this->assertSame('MAIN', $w->branch->code);
        });
    }

    // ---- categories ------------------------------------------------------------------------------

    public function test_category_names_are_unique_among_siblings_only(): void
    {
        $this->actingAs($this->owner);
        $this->post('/categories', ['name' => 'Drinks'])->assertSessionHasNoErrors();
        $parent = $this->in($this->business, fn () => Category::where('name', 'Drinks')->first());

        $this->post('/categories', ['name' => 'Drinks'])->assertSessionHasErrors('name');                          // same level
        $this->post('/categories', ['name' => 'Drinks', 'parent_id' => $parent->id])->assertSessionHasNoErrors(); // different level
        $this->post('/categories', ['name' => 'Drinks', 'parent_id' => $parent->id])->assertSessionHasErrors('name');
    }

    public function test_category_cannot_be_moved_under_itself_or_its_descendants(): void
    {
        [$a, $b, $c] = $this->in($this->business, function () {
            $a = Category::create(['name' => 'A']);
            $b = Category::create(['name' => 'B', 'parent_id' => $a->id]);
            $c = Category::create(['name' => 'C', 'parent_id' => $b->id]);

            return [$a, $b, $c];
        });
        $this->actingAs($this->owner);

        $this->put("/categories/{$a->id}", ['name' => 'A', 'parent_id' => $a->id])->assertSessionHasErrors('parent_id');
        $this->put("/categories/{$a->id}", ['name' => 'A', 'parent_id' => $c->id])->assertSessionHasErrors('parent_id');
        $this->put("/categories/{$c->id}", ['name' => 'C', 'parent_id' => $a->id])->assertSessionHasNoErrors();
    }

    public function test_category_parent_must_belong_to_the_same_business(): void
    {
        [$b] = $this->makeBusiness('Shop B');
        $foreign = $this->in($b, fn () => Category::create(['name' => 'Theirs']));

        $this->actingAs($this->owner)->post('/categories', ['name' => 'Mine', 'parent_id' => $foreign->id])->assertSessionHasErrors('parent_id');
    }

    public function test_category_in_use_or_with_children_cannot_be_deleted(): void
    {
        $parent = $this->in($this->business, fn () => Category::create(['name' => 'Parent']));
        $child = $this->in($this->business, fn () => Category::create(['name' => 'Child', 'parent_id' => $parent->id]));
        $this->makeProduct(['category_id' => $child->id]);
        $this->actingAs($this->owner);

        $this->delete("/categories/{$parent->id}")->assertSessionHasErrors('category'); // has children
        $this->delete("/categories/{$child->id}")->assertSessionHasErrors('category');  // has products
        $this->assertNotSoftDeleted('categories', ['id' => $child->id]);

        $empty = $this->in($this->business, fn () => Category::create(['name' => 'Empty']));
        $this->delete("/categories/{$empty->id}")->assertSessionHasNoErrors();
        $this->assertSoftDeleted('categories', ['id' => $empty->id]);
    }

    public function test_cannot_touch_another_business_category(): void
    {
        [$b] = $this->makeBusiness('Shop B');
        $theirs = $this->in($b, fn () => Category::create(['name' => 'Theirs']));
        $this->actingAs($this->owner);

        $this->put("/categories/{$theirs->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->delete("/categories/{$theirs->id}")->assertNotFound();
        $this->assertSame('Theirs', $this->in($b, fn () => Category::find($theirs->id)->name));
    }

    // ---- brands ----------------------------------------------------------------------------------

    public function test_brand_crud_uniqueness_and_delete_rules(): void
    {
        $this->actingAs($this->owner);
        $this->post('/brands', ['name' => 'Acme'])->assertSessionHasNoErrors();
        $this->post('/brands', ['name' => 'Acme'])->assertSessionHasErrors('name');

        $brand = $this->in($this->business, fn () => Brand::where('name', 'Acme')->first());
        $this->put("/brands/{$brand->id}", ['name' => 'Acme Ltd', 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($this->in($this->business, fn () => Brand::find($brand->id)->is_active));

        $this->makeProduct(['brand_id' => $brand->id]);
        $this->delete("/brands/{$brand->id}")->assertSessionHasErrors('brand');
    }

    public function test_same_brand_name_is_allowed_in_another_business(): void
    {
        [$b, $ownerB] = $this->makeBusiness('Shop B');
        $this->actingAs($this->owner)->post('/brands', ['name' => 'Acme'])->assertSessionHasNoErrors();
        $this->actingAs($ownerB)->post('/brands', ['name' => 'Acme'])->assertSessionHasNoErrors();
    }

    // ---- units -----------------------------------------------------------------------------------

    public function test_unit_short_name_unique_and_in_use_units_cannot_be_deleted(): void
    {
        $this->actingAs($this->owner);
        $this->post('/units', ['name' => 'Piece 2', 'short_name' => 'pcs'])->assertSessionHasErrors('short_name');
        $this->post('/units', ['name' => 'Carton', 'short_name' => 'ctn', 'allow_decimal' => false])->assertSessionHasNoErrors();

        $pcs = $this->in($this->business, fn () => Unit::where('short_name', 'pcs')->first());
        $this->makeProduct();
        $this->delete("/units/{$pcs->id}")->assertSessionHasErrors('unit');
    }

    // ---- taxes -----------------------------------------------------------------------------------

    public function test_tax_validation_and_usage_protection(): void
    {
        $this->actingAs($this->owner);
        $this->post('/settings/taxes', ['name' => 'Bad', 'rate' => '150'])->assertSessionHasErrors('rate');
        $this->post('/settings/taxes', ['name' => 'Bad', 'rate' => '-1'])->assertSessionHasErrors('rate');
        $this->post('/settings/taxes', ['name' => 'GST', 'rate' => '17.5', 'is_inclusive' => true])->assertSessionHasNoErrors();
        $this->post('/settings/taxes', ['name' => 'GST', 'rate' => '5'])->assertSessionHasErrors('name');

        $tax = $this->in($this->business, fn () => Tax::where('name', 'GST')->first());
        $this->assertEqualsWithDelta(17.5, (float) $tax->rate, 0.0001);
        $this->assertTrue($tax->is_inclusive);

        $product = $this->makeProduct();
        $product->taxes()->attach($tax->id);
        $this->delete("/settings/taxes/{$tax->id}")->assertSessionHasErrors('tax');

        $this->put("/settings/taxes/{$tax->id}", ['name' => 'GST', 'rate' => '18', 'is_inclusive' => false])->assertSessionHasNoErrors();
    }

    // ---- warehouses ------------------------------------------------------------------------------

    public function test_warehouse_create_default_switching_and_protection(): void
    {
        $this->actingAs($this->owner);
        $this->post('/warehouses', ['name' => 'Cold Store', 'code' => 'cold', 'is_default' => true])->assertSessionHasNoErrors();

        $this->in($this->business, function () {
            $this->assertSame('COLD', Warehouse::where('name', 'Cold Store')->value('code'));          // upper-cased
            $this->assertSame(1, Warehouse::where('is_default', true)->count());                        // only one default
            $this->assertTrue(Warehouse::where('code', 'COLD')->value('is_default'));
        });

        $cold = $this->in($this->business, fn () => Warehouse::where('code', 'COLD')->first());
        $this->put("/warehouses/{$cold->id}", ['name' => 'Cold Store', 'code' => 'COLD', 'is_default' => false])->assertSessionHasErrors('is_default');
        $this->put("/warehouses/{$cold->id}", ['name' => 'Cold Store', 'code' => 'COLD', 'is_default' => true, 'is_active' => false])->assertSessionHasErrors('is_active');
        $this->delete("/warehouses/{$cold->id}")->assertSessionHasErrors('warehouse');

        $main = $this->in($this->business, fn () => Warehouse::where('code', 'WH-MAIN')->first());
        $this->delete("/warehouses/{$main->id}")->assertSessionHasNoErrors();
        $this->assertSoftDeleted('warehouses', ['id' => $main->id]);
    }

    public function test_warehouse_code_unique_per_business_and_branch_must_belong_to_business(): void
    {
        [$b] = $this->makeBusiness('Shop B');
        $foreignBranch = $this->makeBranch($b, 'FB');
        $this->actingAs($this->owner);

        $this->post('/warehouses', ['name' => 'Dup', 'code' => 'wh-main'])->assertSessionHasErrors('code');
        $this->post('/warehouses', ['name' => 'X', 'code' => 'X1', 'branch_id' => $foreignBranch->id])->assertSessionHasErrors('branch_id');
    }

    // ---- permissions -----------------------------------------------------------------------------

    public function test_master_data_pages_require_their_permission(): void
    {
        $cashier = $this->makeStaff($this->business, 'Cashier');
        $this->actingAs($cashier);
        foreach (['/categories', '/brands', '/units', '/warehouses', '/settings/taxes'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/brands', ['name' => 'Nope'])->assertForbidden();
    }

    public function test_inventory_manager_manages_catalog_data_but_not_taxes(): void
    {
        $im = $this->makeStaff($this->business, 'Inventory Manager');
        $this->actingAs($im);

        foreach (['/categories', '/brands', '/units', '/warehouses', '/products'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/settings/taxes')->assertForbidden();
        $this->post('/brands', ['name' => 'Allowed'])->assertSessionHasNoErrors();
    }

    public function test_master_pages_render_with_expected_props(): void
    {
        $this->actingAs($this->owner);

        $this->get('/units')->assertOk()->assertInertia(fn ($p) => $p->component('Units/Index')->has('units', 8));
        $this->get('/warehouses')->assertOk()->assertInertia(fn ($p) => $p->component('Warehouses/Index')->has('warehouses', 1)->has('branches'));
        $this->get('/categories')->assertOk()->assertInertia(fn ($p) => $p->component('Categories/Index'));
        $this->get('/brands')->assertOk()->assertInertia(fn ($p) => $p->component('Brands/Index'));
        $this->get('/settings/taxes')->assertOk()->assertInertia(fn ($p) => $p->component('Settings/Taxes'));
    }
}
