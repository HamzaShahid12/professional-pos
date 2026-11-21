<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Models\Branch;
use App\Models\User;
use App\Services\Auth\UserService;
use App\Support\Permissions;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct(private UserService $users, private TenantContext $tenant) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);
        $businessId = $this->tenant->businessId();
        $branchIds = Branch::query()->pluck('id');
        $search = trim((string) $request->query('search', ''));

        $users = User::query()
            ->whereHas('businesses', fn ($q) => $q->where('businesses.id', $businessId))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->with(['roles:id,name', 'branches' => fn ($q) => $q->withoutGlobalScopes()->whereIn('branches.id', $branchIds)])
            ->orderBy('name')->paginate(15)->withQueryString()
            ->through(fn ($u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'is_active' => $u->is_active,
                'roles' => $u->roles->pluck('name')->values(),
                'branch_ids' => $u->branches->pluck('id')->values(),
                'branches' => $u->branches->pluck('name')->values(),
                'last_login_at' => $u->last_login_at?->toIso8601String(),
                'is_super_admin' => $u->isSuperAdmin(),
            ]);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => ['search' => $search],
            'roles' => Role::query()->when(! $request->user()->isSuperAdmin(), fn ($q) => $q->where('name', '!=', Permissions::SUPER_ADMIN))->orderBy('name')->pluck('name'),
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $this->users->create($request->validated(), $request->user());

        return back()->with('success', 'User created.');
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->users->update($user, $request->validated(), $request->user());

        return back()->with('success', 'User updated.');
    }
}
