<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Tax;
use App\Services\Catalog\ProductImportService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ProductImportExportTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private $business;
    private $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
        [$this->business, $this->owner] = $this->makeBusiness();
        app(TenantContext::class)->set($this->business->id);
    }

    private function row(array $o = []): array
    {
        return array_merge([
            'name' => 'Cola', 'sku' => 'COLA', 'barcode' => '111', 'category' => 'Drinks', 'brand' => 'Acme', 'unit' => 'pcs',
            'purchase_price' => '60', 'selling_price' => '100', 'wholesale_price' => '90', 'min_selling_price' => '85',
            'min_stock' => '5', 'tax' => '', 'description' => '', 'active' => 'yes',
        ], $o);
    }

    private function import(array $rows)
    {
        return app(ProductImportService::class)->import($rows, $this->owner);
    }

    public function test_import_creates_products_and_auto_creates_category_and_brand(): void
    {
        $result = $this->import([$this->row(), $this->row(['name' => 'Chips', 'sku' => 'CHIPS', 'barcode' => '222', 'category' => 'Snacks'])]);

        $this->assertSame(['created' => 2, 'updated' => 0, 'errors' => []], $result);
        $this->assertSame(2, Product::count());
        $this->assertSame(['Drinks', 'Snacks'], Category::orderBy('name')->pluck('name')->all());
        $this->assertSame(1, Brand::count()); // reused, not duplicated
        $this->assertSame(1, ProductPrice::where('price_type', 'selling_price')->where('reason', 'import')->whereNull('variant_id')
            ->where('product_id', Product::where('sku', 'COLA')->value('id'))->count());
    }

    public function test_reimport_updates_by_sku_and_records_price_history(): void
    {
        $this->import([$this->row()]);
        $result = $this->import([$this->row(['name' => 'Cola Zero', 'selling_price' => '110'])]);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $product = Product::where('sku', 'COLA')->sole();
        $this->assertSame('Cola Zero', $product->name);
        $this->assertSame(1, Product::count());
        $this->assertSame(2, ProductPrice::where('product_id', $product->id)->where('price_type', 'selling_price')->count());
    }

    public function test_import_assigns_taxes_by_name(): void
    {
        $gst = Tax::create(['name' => 'GST', 'rate' => 18, 'is_inclusive' => false]);
        $this->import([$this->row(['tax' => 'gst'])]);

        $this->assertSame([$gst->id], Product::where('sku', 'COLA')->sole()->taxes()->pluck('taxes.id')->all());
    }

    public function test_import_is_all_or_nothing_and_reports_row_numbers(): void
    {
        $result = $this->import([
            $this->row(['sku' => 'OK-1', 'barcode' => '1']),
            $this->row(['sku' => 'BAD-1', 'barcode' => '2', 'unit' => 'furlong']),
            $this->row(['sku' => 'BAD-2', 'barcode' => '3', 'selling_price' => '-5']),
            $this->row(['sku' => 'OK-1', 'barcode' => '4']),                                  // duplicate of row 2
            $this->row(['sku' => 'BAD-3', 'barcode' => '5', 'min_selling_price' => '500']),   // above selling price
            $this->row(['sku' => 'BAD-4', 'barcode' => '6', 'tax' => 'Nope']),
        ]);

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, Product::count()); // nothing written, even the valid first row
        $this->assertSame(0, Category::count());
        $this->assertEqualsCanonicalizing([3, 4, 5, 6, 7], array_keys($result['errors'])); // spreadsheet rows (header = row 1)
        $this->assertStringContainsString('furlong', $result['errors'][3][0]);
        $this->assertStringContainsString('Duplicate SKU', $result['errors'][5][0]);
    }

    public function test_import_rejects_barcode_or_sku_owned_by_other_products_or_variants(): void
    {
        $this->import([$this->row()]);
        $result = $this->import([$this->row(['sku' => 'NEW', 'barcode' => '111'])]); // barcode belongs to COLA

        $this->assertArrayHasKey(2, $result['errors']);
        $this->assertSame(1, Product::count());
    }

    public function test_import_never_touches_another_business(): void
    {
        [$b, $ownerB] = $this->makeBusiness('Shop B');
        app(TenantContext::class)->runAs($b->id, fn () => app(ProductImportService::class)->import([$this->row()], $ownerB));

        $result = $this->import([$this->row(['selling_price' => '999'])]); // same SKU, but in Shop A it is a NEW product

        $this->assertSame(1, $result['created']);
        $theirs = app(TenantContext::class)->runAs($b->id, fn () => Product::where('sku', 'COLA')->sole());
        $this->assertEqualsWithDelta(100, (float) $theirs->selling_price, 0.0001);
    }

    public function test_blank_rows_are_ignored_and_size_is_limited(): void
    {
        $this->assertSame(1, $this->import([$this->row(), ['name' => '', 'sku' => null]])['created']);

        $tooMany = array_fill(0, ProductImportService::MAX_ROWS + 1, $this->row());
        $this->assertNotEmpty($this->import($tooMany)['errors']);
    }

    public function test_export_and_template_downloads(): void
    {
        Excel::fake();
        $this->actingAs($this->owner);

        $this->get('/products/export')->assertOk();
        Excel::assertDownloaded('products.xlsx');

        $this->get('/products/import-template')->assertOk();
        Excel::assertDownloaded('products-template.xlsx');
    }

    public function test_cashier_can_not_import_or_download_template_but_can_export_nothing_without_permission(): void
    {
        $cashier = $this->makeStaff($this->business, 'Cashier'); // products.view only
        $this->actingAs($cashier);

        $file = UploadedFile::fake()->create('p.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->post('/products/import', ['file' => $file])->assertForbidden();
        $this->get('/products/import-template')->assertForbidden();

        $accountant = $this->makeStaff($this->business, 'Accountant'); // no products.view
        $this->actingAs($accountant)->get('/products/export')->assertForbidden();
    }
}
