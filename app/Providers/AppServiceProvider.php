<?php

namespace App\Providers;

use App\Support\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        // Super Admin passes every Gate/Policy check (permissions are still seeded for the role editor UI).
        Gate::before(fn ($user) => method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin() ? true : null);
    }
}
