<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\BrandRequest;
use App\Models\Brand;
use App\Services\Catalog\MasterDataService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BrandController extends Controller
{
    public function __construct(private MasterDataService $master) {}

    public function index(): Response
    {
        $this->authorize('create', Brand::class); // brands.manage

        return Inertia::render('Brands/Index', [
            'brands' => Brand::query()->withCount('products')->orderBy('name')->get()->map->only('id', 'name', 'description', 'is_active', 'products_count'),
        ]);
    }

    public function store(BrandRequest $request): RedirectResponse
    {
        $this->master->saveBrand($request->validated());

        return back()->with('success', 'Brand created.');
    }

    public function update(BrandRequest $request, Brand $brand): RedirectResponse
    {
        $this->master->saveBrand($request->validated(), $brand);

        return back()->with('success', 'Brand updated.');
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        $this->authorize('delete', $brand);
        $this->master->deleteBrand($brand);

        return back()->with('success', 'Brand deleted.');
    }
}
