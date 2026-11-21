<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current business and branch for an authenticated request and verifies membership.
 * Source order: X-Business-Id / X-Branch-Id headers (API), session (web), user's last used context, first allowed.
 * An id the user is not entitled to is silently replaced by an allowed one, never honoured.
 */
class SetTenantContext
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if (! $user->is_active) {
            if ($request->expectsJson() || $request->is('api/*')) {
                abort(403, 'Your account has been deactivated.');
            }
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Your account has been deactivated.']);
        }

        $session = $request->hasSession() ? $request->session() : null;

        $wantedBusiness = (int) ($request->header('X-Business-Id') ?: ($session?->get('business_id') ?? $user->current_business_id));
        $business = $user->accessibleBusinesses()->where('businesses.is_active', true)
            ->when($wantedBusiness, fn ($q) => $q->orderByRaw('businesses.id = ? desc', [$wantedBusiness]))
            ->orderBy('businesses.id')->first();

        if (! $business) {
            $this->tenant->forget();

            return $next($request);
        }

        $branchIds = $user->accessibleBranchIds($business->id);
        $wantedBranch = (int) ($request->header('X-Branch-Id') ?: ($session?->get('branch_id') ?? $user->current_branch_id));
        $branchId = $branchIds->contains($wantedBranch) ? $wantedBranch : $branchIds->first();

        $this->tenant->set($business->id, $branchId);
        $session?->put(['business_id' => $business->id, 'branch_id' => $branchId]);

        return $next($request);
    }
}
