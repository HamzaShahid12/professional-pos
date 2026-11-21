<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContextRequest;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;

/** Switch the active business and/or branch. Ids the user cannot access are rejected, never trusted. */
class ContextController extends Controller
{
    public function __invoke(ContextRequest $request, TenantContext $tenant): RedirectResponse
    {
        $user = $request->user();
        $businessId = $request->integer('business_id') ?: $tenant->businessId();

        abort_unless($businessId && $user->canAccessBusiness($businessId), 403, 'You do not have access to that business.');

        $branchIds = $user->accessibleBranchIds($businessId);
        $branchId = $request->integer('branch_id') ?: ($businessId === $tenant->businessId() ? $tenant->branchId() : null);
        if ($request->filled('branch_id')) {
            abort_unless($branchIds->contains($request->integer('branch_id')), 403, 'You do not have access to that branch.');
        }
        $branchId = $branchIds->contains($branchId) ? $branchId : $branchIds->first();

        $request->session()->put(['business_id' => $businessId, 'branch_id' => $branchId]);
        $user->forceFill(['current_business_id' => $businessId, 'current_branch_id' => $branchId])->save();

        return back();
    }
}
