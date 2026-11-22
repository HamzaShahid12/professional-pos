<?php

namespace App\Http\Controllers\Web;

use App\Exports\ProductsExport;
use App\Exports\ProductsTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkPriceRequest;
use App\Http\Requests\ProductImportRequest;
use App\Http\Requests\ProductRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Tax;
use App\Models\Unit;
use App\Services\Catalog\ProductImportService;
use App\Services\Catalog\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductController extends Controller
{
    public function __construct(private ProductService $products) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        $filters = $request->only('search', 'category_id', 'brand_id', 'status');
        $paginator = Product::query()->with(['category', 'brand', 'unit', 'primaryImage'])
            ->search($filters['search'] ?? null)
            ->when(! empty($filters['category_id']), fn ($q) => $q->where('category_id', (int) $filters['category_id']))
            ->when(! empty($filters['brand_id']), fn ($q) => $q->where('brand_id', (int) $filters['brand_id']))
            ->when(($filters['status'] ?? '') === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? '') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')->paginate(15)->withQueryString()
            ->through(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode,
                'category' => $p->category?->name, 'brand' => $p->brand?->name, 'unit' => $p->unit?->short_name,
                'purchase_price' => (string) $p->purchase_price, 'selling_price' => (string) $p->selling_price,
                'is_active' => $p->is_active, 'has_variants' => $p->has_variants, 'image_url' => $p->image_url,
            ]);

        return Inertia::render('Products/Index', [
            'products' => $paginator,
            'filters' => $filters,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'can' => [
                'create' => $request->user()->can('create', Product::class),
                'update' => $request->user()->can('products.update'),
                'delete' => $request->user()->can('products.delete'),
            ],
        ]);
    }

    /**
     * Fast JSON lookup for pickers: exact barcode/SKU or name search, active products only.
     * `stockable=1` limits it to inventory-tracked products (stock forms).
     */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $items = Product::query()->with(['taxes', 'unit', 'primaryImage', 'variants' => fn ($q) => $q->where('is_active', true)])
            ->where('is_active', true)
            ->when($request->boolean('stockable'), fn ($q) => $q->where('track_inventory', true))
            ->search($request->query('q'))
            ->orderBy('name')->limit(20)->get();

        // On-hand quantity in one warehouse (POS shows it next to the product).
        $onHand = [];
        if ($request->filled('warehouse_id')) {
            Stock::query()->where('warehouse_id', $request->integer('warehouse_id'))->whereIn('product_id', $items->pluck('id'))
                ->get(['product_id', 'variant_key', 'quantity'])
                ->each(function ($s) use (&$onHand) {
                    $onHand[$s->product_id.'-'.$s->variant_key] = (string) $s->quantity;
                });
        }

        $items = $items->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode,
                'selling_price' => (string) $p->selling_price, 'purchase_price' => (string) $p->purchase_price,
                'track_inventory' => $p->track_inventory, 'track_expiry' => $p->track_expiry, 'has_variants' => $p->has_variants,
                'image_url' => $p->image_url, 'unit' => $p->unit?->short_name, 'allow_decimal' => (bool) $p->unit?->allow_decimal,
                'on_hand' => $p->has_variants ? null : ($onHand[$p->id.'-0'] ?? '0.0000'),
                'variants' => $p->variants->map(fn ($v) => [
                    'id' => $v->id, 'name' => $v->name, 'sku' => $v->sku, 'barcode' => $v->barcode,
                    'purchase_price' => $v->purchase_price === null ? null : (string) $v->purchase_price,
                    'selling_price' => $v->selling_price === null ? null : (string) $v->selling_price,
                    'on_hand' => $onHand[$p->id.'-'.$v->id] ?? '0.0000',
                ])->values(),
                // Only active taxes apply at the till, exactly as the server prices the sale.
                'taxes' => $p->taxes->where('is_active', true)->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'rate' => (string) $t->rate, 'is_inclusive' => $t->is_inclusive])->values(),
            ]);

        return response()->json(['data' => $items]);
    }

    public function create(): Response
    {
        $this->authorize('create', Product::class);

        return Inertia::render('Products/Form', ['product' => null] + $this->formOptions());
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = $this->products->create($request->validated(), $request->file('images', []), $request->user());

        return redirect()->route('products.show', $product)->with('success', 'Product created.');
    }

    public function show(Product $product): Response
    {
        $this->authorize('view', $product);
        $product->load($this->products->relations());

        return Inertia::render('Products/Show', [
            'product' => $this->payload($product),
            'prices' => $product->prices()->with(['author:id,name', 'variant:id,name'])->limit(50)->get()->map(fn ($p) => [
                'id' => $p->id, 'type' => $p->price_type, 'variant' => $p->variant?->name, 'old' => $p->old_price, 'new' => $p->new_price,
                'by' => $p->author?->name, 'reason' => $p->reason, 'at' => $p->created_at?->toIso8601String(),
            ]),
            'can' => ['update' => request()->user()->can('update', $product), 'delete' => request()->user()->can('delete', $product)],
        ]);
    }

    public function edit(Product $product): Response
    {
        $this->authorize('update', $product);

        return Inertia::render('Products/Form', ['product' => $this->payload($product->load($this->products->relations()))] + $this->formOptions());
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->products->update($product, $request->validated(), $request->file('images', []), $request->user());

        return redirect()->route('products.show', $product)->with('success', 'Product updated.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);
        $this->products->archive($product);

        return redirect()->route('products.index')->with('success', 'Product archived. Its history is preserved.');
    }

    public function bulkPrices(BulkPriceRequest $request): RedirectResponse
    {
        $result = $this->products->bulkUpdatePrices($request->validated(), $request->user());

        return back()->with('success', "{$result['updated']} price(s) updated".($result['skipped'] ? ", {$result['skipped']} skipped (price rules)." : '.'));
    }

    public function import(ProductImportRequest $request, ProductImportService $importer): RedirectResponse
    {
        // Heading-row keys are lower-cased/slugified by Laravel Excel (e.g. "Purchase Price" -> purchase_price).
        $rows = Excel::toArray(new class implements WithHeadingRow {}, $request->file('file'))[0] ?? [];
        $result = $importer->import($rows, $request->user());

        if ($result['errors']) {
            return back()->with('error', 'Nothing was imported. Fix these rows and try again.')->with('import_errors', $result['errors']);
        }

        return back()->with('success', "Import finished: {$result['created']} created, {$result['updated']} updated.");
    }

    public function export(): BinaryFileResponse
    {
        $this->authorize('viewAny', Product::class);

        return Excel::download(new ProductsExport, 'products.xlsx');
    }

    public function template(): BinaryFileResponse
    {
        $this->authorize('create', Product::class);

        return Excel::download(new ProductsTemplateExport, 'products-template.xlsx');
    }

    private function formOptions(): array
    {
        return [
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'brands' => Brand::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'units' => Unit::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'short_name']),
            'taxes' => Tax::query()->where('is_active', true)->orderBy('name')->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'rate' => (string) $t->rate, 'is_inclusive' => $t->is_inclusive]),
        ];
    }

    private function payload(Product $p): array
    {
        return $p->only(
            'id', 'name', 'sku', 'barcode', 'description', 'category_id', 'brand_id', 'unit_id', 'purchase_price', 'selling_price',
            'wholesale_price', 'min_selling_price', 'min_stock', 'is_active', 'track_inventory', 'track_expiry'
        ) + [
            'tax_ids' => $p->taxes->pluck('id')->values(),
            'taxes' => $p->taxes->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'rate' => (string) $t->rate, 'is_inclusive' => $t->is_inclusive])->values(),
            'category' => $p->category?->name, 'brand' => $p->brand?->name,
            'unit' => $p->unit ? $p->unit->name.' ('.$p->unit->short_name.')' : null,
            'variants' => $p->variants->map->only('id', 'name', 'sku', 'barcode', 'purchase_price', 'selling_price', 'wholesale_price', 'is_active')->values(),
            'images' => $p->images->map(fn ($i) => ['id' => $i->id, 'url' => \App\Support\PublicUrl::of($i->path), 'is_primary' => $i->is_primary])->values(),
        ];
    }
}