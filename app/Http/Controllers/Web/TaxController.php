<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\TaxRequest;
use App\Models\Tax;
use App\Services\Catalog\MasterDataService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TaxController extends Controller
{
    public function __construct(private MasterDataService $master) {}

    public function index(): Response
    {
        $this->authorize('create', Tax::class); // taxes.manage

        return Inertia::render('Settings/Taxes', [
            'taxes' => Tax::query()->orderBy('name')->get()->map(fn ($t) => $t->only('id', 'name', 'is_inclusive', 'is_active') + ['rate' => (string) $t->rate]),
        ]);
    }

    public function store(TaxRequest $request): RedirectResponse
    {
        $this->master->saveTax($request->validated());

        return back()->with('success', 'Tax created.');
    }

    public function update(TaxRequest $request, Tax $tax): RedirectResponse
    {
        $this->master->saveTax($request->validated(), $tax);

        return back()->with('success', 'Tax updated. Existing documents keep the rate they were issued with.');
    }

    public function destroy(Tax $tax): RedirectResponse
    {
        $this->authorize('delete', $tax);
        $this->master->deleteTax($tax);

        return back()->with('success', 'Tax deleted.');
    }
}
