<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessRequest;
use App\Services\Business\BusinessService;
use App\Services\Settings\SettingsService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class BusinessSettingsController extends Controller
{
    public function __construct(private TenantContext $tenant, private SettingsService $settings, private BusinessService $businesses) {}

    public function edit(): Response
    {
        $business = $this->tenant->business();
        $this->authorize('update', $business);

        return Inertia::render('Settings/Business', [
            'business' => $business->only(
                'id', 'name', 'legal_name', 'tax_number', 'email', 'phone', 'address', 'country', 'timezone', 'base_currency_code'
            ) + ['logo_url' => $business->logo_url],
            'settings' => $this->settings->all(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(BusinessRequest $request): RedirectResponse
    {
        $business = $this->tenant->business();
        $this->businesses->update($business, $request->validated());
        $this->settings->setMany($request->settingValues());

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('logos', 'public');
            if ($business->logo_path) {
                Storage::disk('public')->delete($business->logo_path);
            }
            $business->update(['logo_path' => $path]);
        }

        return back()->with('success', 'Business settings saved.');
    }
}
