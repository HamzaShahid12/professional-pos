<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\Tax;
use App\Models\Unit;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
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

    /** Run a closure inside a business context (to create/inspect scoped records in tests). */
    private function inBusiness($business, callable $fn)
    {
        return app(TenantContext::class)->runAs($business->id, $fn);
    }

    private function unitId($business = null): int
    {
        return $this->inBusiness($business ?? $this->business, fn () => Unit::where('short_name', 'pcs')->value('id'));
    }

    private function payload(array $override = [], $business = null): array
    {
        return array_merge([
            'name' => 'Cola 500ml', 'sku' => 'COLA-500', 'barcode' => '111222333',
            'unit_id' => $this->unitId($business), 'purchase_price' => '60', 'selling_price' => '100',
        ], $override);
    }

    private function create(array $override = [], $user = null, $business = null)
    {
        return $this->actingAs($user ?? $this->owner, 'sanctum')->postJson('/api/v1/products', $this->payload($override, $business));
    }

    public function test_create_product_with_taxes_variants_and_initial_price_history(): void
    {
        $tax = $this->inBusiness($this->business, fn () => Tax::create(['name' => 'GST', 'rate' => 18, 'is_inclusive' => false]));

        $id = $this->create([
            'wholesale_price' => '90', 'min_selling_price' => '85', 'tax_ids' => [$tax->id],
            'variants' => [['name' => 'Large', 'sku' => 'COLA-500-L', 'selling_price' => '120']],
        ])->assertCreated()
            ->assertJsonPath('data.sku', 'COLA-500')
            ->assertJsonPath('data.has_variants', true)
            ->assertJsonPath('data.taxes.0.name', 'GST')
            ->assertJsonPath('data.variants.0.sku', 'COLA-500-L')
            ->json('data.id');

        $this->inBusiness($this->business, function () use ($id) {
            $types = ProductPrice::where('product_id', $id)->whereNull('variant_id')->pluck('price_type')->sort()->values()->all();
            $this->assertSame(['min_selling_price', 'purchase_price', 'selling_price', 'wholesale_price'], $types);
            $this->assertSame(1, ProductPrice::where('product_id', $id)->whereNotNull('variant_id')->count()); // variant selling price
        });
    }

    public function test_sku_and_barcode_are_unique_per_business_across_products_and_variants(): void
    {
        $this->create(['variants' => [['name' => 'V', 'sku' => 'VAR-1', 'barcode' => 'VBAR-1']]])->assertCreated();

        $this->create(['name' => 'Other', 'barcode' => '999'])->assertStatus(422)->assertJsonValidationErrors('sku');          // same product SKU
        $this->create(['name' => 'Other', 'sku' => 'NEW-1'])->assertStatus(422)->assertJsonValidationErrors('barcode');        // same product barcode
        $this->create(['name' => 'Other', 'sku' => 'VAR-1', 'barcode' => '5'])->assertStatus(422)->assertJsonValidationErrors('sku');       // clashes with a variant SKU
        $this->create(['name' => 'Other', 'sku' => 'NEW-2', 'barcode' => 'VBAR-1'])->assertStatus(422)->assertJsonValidationErrors('barcode'); // clashes with a variant barcode
    }

    public function test_duplicate_codes_inside_one_form_are_rejected(): void
    {
        $this->create(['variants' => [['name' => 'A', 'sku' => 'COLA-500']]])->assertStatus(422)->assertJsonValidationErrors('variants.0.sku');
        $this->create(['sku' => 'S-2', 'barcode' => 'B1', 'variants' => [['name' => 'A', 'sku' => 'S-3'], ['name' => 'B', 'sku' => 'S-3']]])
            ->assertStatus(422)->assertJsonValidationErrors('variants.1.sku');
    }

    public function test_same_sku_is_allowed_in_a_different_business(): void
    {
        [$b, $ownerB] = $this->makeBusiness('Shop B');
        $this->create()->assertCreated();

        $this->create([], $ownerB, $b)->assertCreated();
    }

    public function test_foreign_business_references_are_rejected(): void
    {
        [$b] = $this->makeBusiness('Shop B');
        $foreignCat = $this->inBusiness($b, fn () => Category::create(['name' => 'Theirs']));
        $foreignBrand = $this->inBusiness($b, fn () => Brand::create(['name' => 'Theirs']));
        $foreignTax = $this->inBusiness($b, fn () => Tax::create(['name' => 'T', 'rate' => 5, 'is_inclusive' => false]));

        $this->create(['category_id' => $foreignCat->id])->assertStatus(422)->assertJsonValidationErrors('category_id');
        $this->create(['brand_id' => $foreignBrand->id])->assertStatus(422)->assertJsonValidationErrors('brand_id');
        $this->create(['tax_ids' => [$foreignTax->id]])->assertStatus(422)->assertJsonValidationErrors('tax_ids.0');
        $this->create(['unit_id' => $this->unitId($b)])->assertStatus(422)->assertJsonValidationErrors('unit_id');
    }

    public function test_price_rules_are_validated(): void
    {
        $this->create(['selling_price' => '100', 'min_selling_price' => '120'])->assertStatus(422)->assertJsonValidationErrors('min_selling_price');
        $this->create(['purchase_price' => '-1'])->assertStatus(422)->assertJsonValidationErrors('purchase_price');
        $this->create(['selling_price' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('selling_price');
    }

    public function test_changing_a_price_appends_history_and_keeps_old_entries(): void
    {
        $id = $this->create()->json('data.id');

        $this->putJson("/api/v1/products/$id", $this->payload(['selling_price' => '120']))->assertOk()->assertJsonPath('data.selling_price', '120.0000');

        $this->inBusiness($this->business, function () use ($id) {
            $rows = ProductPrice::where('product_id', $id)->where('price_type', 'selling_price')->orderBy('id')->get();
            $this->assertCount(2, $rows);
            $this->assertNull($rows[0]->old_price);
            $this->assertEqualsWithDelta(100, (float) $rows[0]->new_price, 0.0001);
            $this->assertEqualsWithDelta(100, (float) $rows[1]->old_price, 0.0001);
            $this->assertEqualsWithDelta(120, (float) $rows[1]->new_price, 0.0001);
            // Unchanged prices produce no new history rows.
            $this->assertSame(1, ProductPrice::where('product_id', $id)->where('price_type', 'purchase_price')->count());
        });
    }

    public function test_price_history_is_immutable(): void
    {
        $id = $this->create()->json('data.id');

        $this->inBusiness($this->business, function () use ($id) {
            $row = ProductPrice::where('product_id', $id)->firstOrFail();
            $this->expectException(\LogicException::class);
            $row->update(['new_price' => '1']);
        });
    }

    public function test_archiving_keeps_the_record_and_reserves_the_sku(): void
    {
        $id = $this->create()->json('data.id');

        $this->deleteJson("/api/v1/products/$id")->assertOk();
        $this->getJson("/api/v1/products/$id")->assertNotFound();

        $this->inBusiness($this->business, function () use ($id) {
            $this->assertSame(0, Product::count());
            $this->assertNotNull(Product::withTrashed()->find($id)); // history still resolvable
        });
        $this->create()->assertStatus(422)->assertJsonValidationErrors('sku');
    }

    public function test_variants_can_be_updated_and_dropped_variants_are_archived(): void
    {
        $id = $this->create(['variants' => [['name' => 'A', 'sku' => 'V-A'], ['name' => 'B', 'sku' => 'V-B']]])->json('data.id');
        $variants = $this->getJson("/api/v1/products/$id")->json('data.variants');
        $keep = collect($variants)->firstWhere('sku', 'V-A');

        $this->putJson("/api/v1/products/$id", $this->payload(['variants' => [['id' => $keep['id'], 'name' => 'A renamed', 'sku' => 'V-A']]]))
            ->assertOk()->assertJsonCount(1, 'data.variants')->assertJsonPath('data.variants.0.name', 'A renamed');

        $this->inBusiness($this->business, fn () => $this->assertSame(1, \App\Models\ProductVariant::onlyTrashed()->where('product_id', $id)->count()));
    }

    public function test_variant_id_from_another_product_is_rejected(): void
    {
        $other = $this->create(['sku' => 'OTHER', 'barcode' => null, 'variants' => [['name' => 'X', 'sku' => 'OTHER-X']]])->json('data');
        $mine = $this->create(['sku' => 'MINE', 'barcode' => null])->json('data.id');

        $this->putJson("/api/v1/products/$mine", $this->payload(['sku' => 'MINE', 'barcode' => null, 'variants' => [['id' => $other['variants'][0]['id'], 'name' => 'Stolen', 'sku' => 'OTHER-X']]]))
            ->assertStatus(422);
    }

    public function test_search_by_name_and_exact_barcode(): void
    {
        $this->create(['name' => 'Alpha Soda', 'sku' => 'A-1', 'barcode' => '5551'])->assertCreated();
        $this->create(['name' => 'Beta Chips', 'sku' => 'B-1', 'barcode' => '5552'])->assertCreated();

        $this->getJson('/api/v1/products?search=alpha')->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'A-1');
        $this->getJson('/api/v1/products?search=5552')->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'B-1');
        $this->getJson('/api/v1/products?search=B-1')->assertJsonCount(1, 'data');
        $this->actingAs($this->owner)->getJson('/products/lookup?q=5551')->assertOk()->assertJsonPath('data.0.name', 'Alpha Soda');
    }

    public function test_business_isolation_for_products(): void
    {
        [$b, $ownerB] = $this->makeBusiness('Shop B');
        $theirs = $this->create(['sku' => 'THEIRS'], $ownerB, $b)->json('data.id');
        $this->create(['sku' => 'MINE'])->assertCreated();

        $this->actingAs($this->owner, 'sanctum');
        $this->getJson("/api/v1/products/$theirs")->assertNotFound();
        $this->putJson("/api/v1/products/$theirs", $this->payload(['sku' => 'THEIRS']))->assertNotFound();
        $this->deleteJson("/api/v1/products/$theirs")->assertNotFound();
        $this->assertEquals(['MINE'], collect($this->getJson('/api/v1/products')->json('data'))->pluck('sku')->all());
    }

    public function test_permissions_cashier_reads_but_cannot_change_catalog(): void
    {
        $id = $this->create()->json('data.id');
        $cashier = $this->makeStaff($this->business, 'Cashier');
        $this->actingAs($cashier, 'sanctum');

        $this->getJson('/api/v1/products')->assertOk();
        $this->getJson("/api/v1/products/$id")->assertOk();
        $this->postJson('/api/v1/products', $this->payload(['sku' => 'NEW']))->assertForbidden();
        $this->putJson("/api/v1/products/$id", $this->payload())->assertForbidden();
        $this->deleteJson("/api/v1/products/$id")->assertForbidden();
        $this->postJson('/api/v1/categories', ['name' => 'Nope'])->assertForbidden();
    }

    public function test_user_without_product_view_cannot_list_products(): void
    {
        $accountant = $this->makeStaff($this->business, 'Accountant'); // no products.view
        $this->actingAs($accountant, 'sanctum')->getJson('/api/v1/products')->assertForbidden();
    }

    public function test_bulk_price_update_by_percent_records_history_and_respects_minimum_price(): void
    {
        $cat = $this->inBusiness($this->business, fn () => Category::create(['name' => 'Drinks']));
        $this->create(['sku' => 'D-1', 'barcode' => null, 'category_id' => $cat->id, 'selling_price' => '100', 'min_selling_price' => '90'])->assertCreated();
        $this->create(['sku' => 'D-2', 'barcode' => null, 'category_id' => $cat->id, 'selling_price' => '200'])->assertCreated();
        $this->create(['sku' => 'X-1', 'barcode' => null, 'selling_price' => '50'])->assertCreated(); // other category

        $this->actingAs($this->owner)->post('/products/bulk-prices', [
            'scope' => 'category', 'category_id' => $cat->id, 'field' => 'selling_price', 'mode' => 'percent', 'value' => '10', 'reason' => 'Eid pricing',
        ])->assertSessionHasNoErrors();

        $this->inBusiness($this->business, function () {
            $this->assertEqualsWithDelta(110, (float) Product::where('sku', 'D-1')->value('selling_price'), 0.0001);
            $this->assertEqualsWithDelta(220, (float) Product::where('sku', 'D-2')->value('selling_price'), 0.0001);
            $this->assertEqualsWithDelta(50, (float) Product::where('sku', 'X-1')->value('selling_price'), 0.0001);
            $this->assertSame(2, ProductPrice::where('reason', 'Eid pricing')->count());
        });

        // A cut below the minimum selling price is skipped, not applied.
        $this->post('/products/bulk-prices', ['scope' => 'category', 'category_id' => $cat->id, 'field' => 'selling_price', 'mode' => 'percent', 'value' => '-50']);
        $this->inBusiness($this->business, function () {
            $this->assertEqualsWithDelta(110, (float) Product::where('sku', 'D-1')->value('selling_price'), 0.0001); // min 90 would be broken
            $this->assertEqualsWithDelta(110, (float) Product::where('sku', 'D-2')->value('selling_price'), 0.0001); // no minimum: applied
        });
    }

    public function test_bulk_price_update_is_scoped_to_the_business_and_permissioned(): void
    {
        [$b, $ownerB] = $this->makeBusiness('Shop B');
        $this->create(['sku' => 'B-1', 'barcode' => null, 'selling_price' => '10'], $ownerB, $b)->assertCreated();
        $this->create(['sku' => 'A-1', 'barcode' => null, 'selling_price' => '10'])->assertCreated();

        $this->actingAs($this->owner)->post('/products/bulk-prices', ['scope' => 'all', 'field' => 'selling_price', 'mode' => 'set', 'value' => '99']);

        $this->inBusiness($b, fn () => $this->assertEqualsWithDelta(10, (float) Product::where('sku', 'B-1')->value('selling_price'), 0.0001));
        $this->actingAs($this->makeStaff($this->business, 'Cashier'))->post('/products/bulk-prices', ['scope' => 'all', 'field' => 'selling_price', 'mode' => 'set', 'value' => '1'])->assertForbidden();
    }

    public function test_images_are_stored_and_can_be_removed(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->owner, 'sanctum')->post('/api/v1/products', $this->payload([
            'images' => [UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.jpg')],
        ]), ['Accept' => 'application/json'])->assertCreated();

        $id = $response->json('data.id');
        $this->assertNotNull($response->json('data.image_url'));
        $images = $this->inBusiness($this->business, fn () => ProductImage::where('product_id', $id)->orderBy('id')->get());
        $this->assertCount(2, $images);
        $this->assertTrue($images[0]->is_primary);
        Storage::disk('public')->assertExists($images[0]->path);

        $this->putJson("/api/v1/products/$id", $this->payload(['remove_image_ids' => [$images[0]->id]]))->assertOk();
        Storage::disk('public')->assertMissing($images[0]->path);
        $this->inBusiness($this->business, fn () => $this->assertTrue(ProductImage::where('product_id', $id)->sole()->is_primary)); // promoted
    }

    public function test_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner, 'sanctum')->post('/api/v1/products', $this->payload([
            'images' => [UploadedFile::fake()->create('evil.php', 10, 'text/x-php')],
        ]), ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('images.0');
    }

    public function test_product_pages_render_for_authorised_users(): void
    {
        $id = $this->create()->json('data.id');
        $this->actingAs($this->owner);

        $this->get('/products')->assertOk()->assertInertia(fn ($p) => $p->component('Products/Index')->has('products.data', 1));
        $this->get('/products/create')->assertOk()->assertInertia(fn ($p) => $p->component('Products/Form')->where('product', null)->has('units'));
        $this->get("/products/$id")->assertOk()->assertInertia(fn ($p) => $p->component('Products/Show')->where('product.sku', 'COLA-500')->has('prices'));
        $this->get("/products/$id/edit")->assertOk()->assertInertia(fn ($p) => $p->component('Products/Form'));
    }

    public function test_web_create_update_and_archive_flow(): void
    {
        $this->actingAs($this->owner);

        $this->post('/products', $this->payload(['sku' => 'WEB-1', 'barcode' => null]))->assertSessionHasNoErrors();
        $product = $this->inBusiness($this->business, fn () => Product::where('sku', 'WEB-1')->firstOrFail());

        $this->put("/products/{$product->id}", $this->payload(['sku' => 'WEB-1', 'barcode' => null, 'name' => 'Renamed']))->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $this->inBusiness($this->business, fn () => Product::find($product->id)->name));

        $this->delete("/products/{$product->id}")->assertRedirect('/products');
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }
}
