<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;
use App\Support\TenantContext;

class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // controller limits non-managers to their own branches
    }

    public function view(User $user, Branch $branch): bool
    {
        return $this->inContext($branch)
            && ($user->can('branches.manage') || $user->accessibleBranchIds($branch->business_id)->contains($branch->id));
    }

    public function create(User $user): bool
    {
        return $user->can('branches.manage');
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->can('branches.manage') && $this->inContext($branch);
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $this->update($user, $branch);
    }

    private function inContext(Branch $branch): bool
    {
        return (int) $branch->business_id === app(TenantContext::class)->businessId();
    }
}
