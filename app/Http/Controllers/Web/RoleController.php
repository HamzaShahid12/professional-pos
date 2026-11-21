<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Models\ActivityLog;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function index(): Response
    {
        abort_unless(request()->user()->can('roles.manage'), 403);

        return Inertia::render('Roles/Index', [
            'roles' => Role::query()->with('permissions:id,name')->withCount('users')->orderBy('name')->get()->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'users_count' => $r->users_count,
                'is_system' => in_array($r->name, Permissions::SYSTEM_ROLES, true),
                'locked' => $r->name === Permissions::SUPER_ADMIN,
                'permissions' => $r->permissions->pluck('name')->values(),
            ]),
            'groups' => Permissions::groups(),
        ]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $role = Role::create(['name' => $request->input('name'), 'guard_name' => 'web']);
        $role->syncPermissions($request->input('permissions', []));
        ActivityLog::record('role.created', $role, ['name' => $role->name]);

        return back()->with('success', 'Role created.');
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        abort_if($role->name === Permissions::SUPER_ADMIN, 403, 'The Super Admin role cannot be changed.');

        $role->syncPermissions($request->input('permissions', []));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        ActivityLog::record('role.updated', $role, ['name' => $role->name]);

        return back()->with('success', 'Role permissions saved.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        abort_unless(request()->user()->can('roles.manage'), 403);
        abort_if(in_array($role->name, Permissions::SYSTEM_ROLES, true), 403, 'System roles cannot be deleted.');
        abort_if($role->users()->exists(), 422, 'Remove this role from its users before deleting it.');

        $role->delete();
        ActivityLog::record('role.deleted', null, ['name' => $role->name]);

        return back()->with('success', 'Role deleted.');
    }
}
