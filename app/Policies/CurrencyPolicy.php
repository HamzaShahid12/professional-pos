<?php

namespace App\Policies;

use App\Models\Currency;
use App\Models\User;
use App\Support\TenantContext;

class CurrencyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('currencies.manage');
    }

    public function update(User $user, Currency $currency): bool
    {
        return $user->can('currencies.manage')
            && (int) $currency->business_id === app(TenantContext::class)->businessId();
    }
}
