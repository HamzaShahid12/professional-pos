<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\BranchRequest;
use App\Models\Branch;
use App\Models\User;
use App\Services\Business\BranchService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function __construct(private BranchService $branches, private TenantContext $tenant) {}

    public function index(): Response
    {
        $this->authorize('create', Branch::class); // branches.manage

        $users = User::query()->whereHas('businesses', fn ($q) => $q->where('businesses.id', $this->tenant->businessId()))
            ->orderBy('name')->get(['users.id', 'users.name']);

        return Inertia::render('Settings/Branches', [
            'branches' => Branch::query()->with(['manager:id,name', 'users:id'])->orderByDesc('is_main')->orderBy('name')->get()
                ->map(fn ($b) => $b->only('id', 'name', 'code', 'address', 'phone', 'email', 'manager_id', 'is_main', 'is_active') + [
                    'manager' => $b->manager?->name,
                    'user_ids' => $b->users->pluck('id')->values(),
                ]),
            'users' => $users,
        ]);
    }

    public function store(BranchRequest $request): RedirectResponse
    {
        $this->branches->create($request->validated());

        return back()->with('success', 'Branch created.');
    }

    public function update(BranchRequest $request, Branch $branch): RedirectResponse
    {
        $this->branches->update($branch, $request->validated());

        return back()->with('success', 'Branch updated.');
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        $this->authorize('delete', $branch);
        $this->branches->delete($branch);

        return back()->with('success', 'Branch deleted.');
    }
}
