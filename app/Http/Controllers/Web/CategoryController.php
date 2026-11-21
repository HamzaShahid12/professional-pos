<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use App\Services\Catalog\MasterDataService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function __construct(private MasterDataService $master) {}

    public function index(): Response
    {
        $this->authorize('create', Category::class); // categories.manage

        $all = Category::query()->withCount('products')->orderBy('name')->get();
        $names = $all->pluck('name', 'id');

        return Inertia::render('Categories/Index', [
            'categories' => $all->map(fn ($c) => $c->only('id', 'name', 'parent_id', 'description', 'is_active', 'products_count') + [
                'parent' => $c->parent_id ? $names->get($c->parent_id) : null,
            ])->values(),
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $this->master->saveCategory($request->validated());

        return back()->with('success', 'Category created.');
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $this->master->saveCategory($request->validated(), $category);

        return back()->with('success', 'Category updated.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        $this->authorize('delete', $category);
        $this->master->deleteCategory($category);

        return back()->with('success', 'Category deleted.');
    }
}
