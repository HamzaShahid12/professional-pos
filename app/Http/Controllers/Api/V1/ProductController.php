<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Services\Catalog\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function __construct(private ProductService $products) {}

    /** Filters: search (name/SKU/barcode), category_id, brand_id, active (1|0), per_page (max 100). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        $query = Product::query()->with($this->products->relations())
            ->search($request->query('search'))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->integer('brand_id')))
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('name');

        return ProductResource::collection($query->paginate(min((int) $request->integer('per_page', 25), 100)));
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $product = $this->products->create($request->validated(), $request->file('images', []), $request->user());

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        $this->authorize('view', $product);

        return new ProductResource($product->load($this->products->relations()));
    }

    public function update(ProductRequest $request, Product $product): ProductResource
    {
        return new ProductResource($this->products->update($product, $request->validated(), $request->file('images', []), $request->user()));
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);
        $this->products->archive($product);

        return response()->json(['message' => 'Product archived.']);
    }
}
