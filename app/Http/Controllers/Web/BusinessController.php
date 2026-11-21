<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessRequest;
use App\Models\Business;
use App\Services\Business\BusinessService;
use App\Support\CurrencyCatalog;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Platform-level business list (requires businesses.manage, i.e. Super Admin). */
class BusinessController extends Controller
{
    public function index(): Response
    {
        $this->authorize('create', Business::class);

        return Inertia::render('Businesses/Index', [
            'businesses' => Business::query()->withCount('users')->orderBy('name')->get()
                ->map(fn ($b) => $b->only('id', 'name', 'country', 'base_currency_code', 'is_active', 'users_count')),
            'currencies' => collect(CurrencyCatalog::all())->map(fn ($v, $k) => ['code' => $k, 'name' => $v['name']])->values(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function store(BusinessRequest $request, BusinessService $service): RedirectResponse
    {
        $service->create($request->validated(), $request->user());

        return back()->with('success', 'Business created with its base currency and main branch.');
    }
}
