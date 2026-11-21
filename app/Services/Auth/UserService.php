<?php

namespace App\Services\Auth;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\User;
use App\Support\Permissions;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(private TenantContext $tenant) {}

    /** Create a user inside the current business. */
    public function create(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'is_active' => $data['is_active'] ?? true,
                'current_business_id' => $this->tenant->businessId(),
            ]);

            $user->businesses()->attach($this->tenant->businessId(), ['is_owner' => false]);
            $this->syncRoles($user, $data['roles'] ?? [], $actor);
            $this->syncBranches($user, $data['branch_ids'] ?? []);
            ActivityLog::record('user.created', $user, ['email' => $user->email]);

            return $user;
        });
    }

    public function update(User $user, array $data, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor) {
            $user->fill(collect($data)->only(['name', 'email', 'phone', 'is_active'])->all());
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }

            if ($user->is($actor) && ! $user->is_active) {
                throw ValidationException::withMessages(['is_active' => 'You cannot deactivate your own account.']);
            }
            $user->save();

            if (array_key_exists('roles', $data)) {
                $this->syncRoles($user, $data['roles'], $actor);
            }
            if (array_key_exists('branch_ids', $data)) {
                $this->syncBranches($user, $data['branch_ids']);
            }
            ActivityLog::record('user.updated', $user);

            return $user;
        });
    }

    private function syncRoles(User $user, array $roles, User $actor): void
    {
        if (in_array(Permissions::SUPER_ADMIN, $roles, true) && ! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages(['roles' => 'Only a Super Admin can grant the Super Admin role.']);
        }
        // Do not let a non-super-admin strip the role from an existing super admin.
        if ($user->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages(['roles' => 'Super Admin accounts cannot be modified.']);
        }
        $user->syncRoles($roles);
    }

    /** Replace the user's branch assignments within the CURRENT business only; other businesses are untouched. */
    private function syncBranches(User $user, array $branchIds): void
    {
        $inBusiness = Branch::query()->pluck('id'); // scoped to the current business
        $valid = $inBusiness->intersect($branchIds)->values()->all();

        $user->branches()->detach($inBusiness->all());
        $user->branches()->attach($valid);
    }
}
