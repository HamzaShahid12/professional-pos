<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessRequest;
use App\Http\Resources\Api\V1\BusinessResource;
use App\Models\Business;
use App\Services\Business\BusinessService;
use App\Services\Settings\SettingsService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BusinessController extends Controller
{
    public function __construct(private BusinessService $businesses, private SettingsService $settings, private TenantContext $tenant) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Business::class);

        return BusinessResource::collection(
            $request->user()->accessibleBusinesses()->orderBy('name')->paginate(min((int) $request->integer('per_page', 25), 100))
        );
    }

    public function store(BusinessRequest $request): BusinessResource
    {
        $business = $this->businesses->create($request->validated(), $request->user());

        return new BusinessResource($business);
    }

    public function show(Business $business): BusinessResource
    {
        $this->authorize('view', $business);

        return new BusinessResource($business);
    }

    public function update(BusinessRequest $request, Business $business): BusinessResource
    {
        $business = $this->businesses->update($business, $request->validated());
        $this->tenant->runAs($business->id, fn () => $this->settings->setMany($request->settingValues()));

        return new BusinessResource($business->refresh());
    }
}
