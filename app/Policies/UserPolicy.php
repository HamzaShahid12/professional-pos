<?php

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    /** Target must belong to the current business; only Super Admins may touch Super Admin accounts. */
    public function update(User $user, User $target): bool
    {
        if (! $user->can('users.manage')) {
            return false;
        }
        if ($target->isSuperAdmin() && ! $user->isSuperAdmin()) {
            return false;
        }
        $businessId = app(TenantContext::class)->businessId();

        return $businessId !== null
            && $target->businesses()->where('businesses.id', $businessId)->exists();
    }
}
