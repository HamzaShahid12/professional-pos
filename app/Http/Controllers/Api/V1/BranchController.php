<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BranchRequest;
use App\Http\Resources\Api\V1\BranchResource;
use App\Models\Branch;
use App\Services\Business\BranchService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BranchController extends Controller
{
    public function __construct(private BranchService $branches, private TenantContext $tenant) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $this->authorize('viewAny', Branch::class);

        $query = Branch::query()->with('manager:id,name')->orderByDesc('is_main')->orderBy('name');
        if (! $user->can('branches.manage')) {
            $query->whereIn('id', $user->accessibleBranchIds($this->tenant->businessId()));
        }

        return BranchResource::collection($query->get());
    }

    public function store(BranchRequest $request): JsonResponse
    {
        $branch = $this->branches->create($request->validated());

        return (new BranchResource($branch->load('manager', 'users')))->response()->setStatusCode(201);
    }

    public function show(Branch $branch): BranchResource
    {
        $this->authorize('view', $branch);

        return new BranchResource($branch->load('manager', 'users'));
    }

    public function update(BranchRequest $request, Branch $branch): BranchResource
    {
        return new BranchResource($this->branches->update($branch, $request->validated())->load('manager', 'users'));
    }
}
