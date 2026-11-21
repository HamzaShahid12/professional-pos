<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\Currency;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();
        $tenant = app(TenantContext::class);
        $business = $user ? $tenant->business() : null;

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'roles' => $user->getRoleNames()->values(),
                    // Super Admin is granted every permission through the role seeder.
                    'permissions' => $user->getAllPermissions()->pluck('name')->values(),
                ] : null,
            ],
            'tenant' => $business ? [
                'business' => ['id' => $business->id, 'name' => $business->name, 'logo_url' => $business->logo_url, 'timezone' => $business->timezone],
                'businesses' => $user->accessibleBusinesses()->where('businesses.is_active', true)->orderBy('name')->get(['businesses.id', 'businesses.name']),
                'branch_id' => $tenant->branchId(),
                'branches' => Branch::query()->whereIn('id', $user->accessibleBranchIds($business->id))->orderByDesc('is_main')->orderBy('name')->get(['id', 'name', 'code']),
                'currency' => Currency::query()->where('is_base', true)->first(['code', 'symbol', 'decimal_places']),
            ] : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'import_errors' => fn () => $request->session()->get('import_errors'),
            ],
        ]);
    }
}
