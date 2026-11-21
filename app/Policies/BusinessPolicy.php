<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\User;

class BusinessPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // list is filtered to the user's accessible businesses
    }

    public function view(User $user, Business $business): bool
    {
        return $user->canAccessBusiness($business->id);
    }

    public function create(User $user): bool
    {
        return $user->can('businesses.manage');
    }

    public function update(User $user, Business $business): bool
    {
        return $user->can('settings.manage') && $user->canAccessBusiness($business->id);
    }
}
