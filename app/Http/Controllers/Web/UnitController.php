<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitRequest;
use App\Models\Unit;
use App\Services\Catalog\MasterDataService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class UnitController extends Controller
{
    public function __construct(private MasterDataService $master) {}

    public function index(): Response
    {
        $this->authorize('create', Unit::class); // units.manage

        return Inertia::render('Units/Index', [
            'units' => Unit::query()->withCount('products')->orderBy('name')->get()->map->only('id', 'name', 'short_name', 'allow_decimal', 'is_active', 'products_count'),
        ]);
    }

    public function store(UnitRequest $request): RedirectResponse
    {
        $this->master->saveUnit($request->validated());

        return back()->with('success', 'Unit created.');
    }

    public function update(UnitRequest $request, Unit $unit): RedirectResponse
    {
        $this->master->saveUnit($request->validated(), $unit);

        return back()->with('success', 'Unit updated.');
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        $this->authorize('delete', $unit);
        $this->master->deleteUnit($unit);

        return back()->with('success', 'Unit deleted.');
    }
}
