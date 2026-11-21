<?php

namespace App\Policies;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Base policy for business-owned records: a permission per action plus a check that the record belongs
 * to the business in context (defence in depth on top of the global BusinessScope).
 */
abstract class PermissionPolicy
{
    /** Empty string = any authenticated user may read (needed for dropdowns). */
    protected string $viewPermission = '';

    protected string $createPermission = '';

    protected string $updatePermission = '';

    protected string $deletePermission = '';

    public function viewAny(User $user): bool
    {
        return $this->viewPermission === '' || $user->can($this->viewPermission);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user) && $this->inBusiness($model);
    }

    public function create(User $user): bool
    {
        return $user->can($this->createPermission);
    }

    public function update(User $user, Model $model): bool
    {
        return $user->can($this->updatePermission) && $this->inBusiness($model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->can($this->deletePermission) && $this->inBusiness($model);
    }

    protected function inBusiness(Model $model): bool
    {
        return (int) $model->getAttribute('business_id') === app(TenantContext::class)->businessId();
    }
}
