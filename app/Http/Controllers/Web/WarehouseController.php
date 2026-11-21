<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\WarehouseRequest;
use App\Models\Branch;
use App\Models\Warehouse;
use App\Services\Catalog\MasterDataService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseController extends Controller
{
    public function __construct(private MasterDataService $master) {}

    public function index(): Response
    {
        $this->authorize('create', Warehouse::class); // warehouses.manage

        return Inertia::render('Warehouses/Index', [
            'warehouses' => Warehouse::query()->with('branch:id,name')->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn ($w) => $w->only('id', 'name', 'code', 'address', 'branch_id', 'is_default', 'is_active') + ['branch' => $w->branch?->name]),
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(WarehouseRequest $request): RedirectResponse
    {
        $this->master->saveWarehouse($request->validated());

        return back()->with('success', 'Warehouse created.');
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse): RedirectResponse
    {
        $this->master->saveWarehouse($request->validated(), $warehouse);

        return back()->with('success', 'Warehouse updated.');
    }

    public function destroy(Warehouse $warehouse): RedirectResponse
    {
        $this->authorize('delete', $warehouse);
        $this->master->deleteWarehouse($warehouse);

        return back()->with('success', 'Warehouse deleted.');
    }
}
