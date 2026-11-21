<?php

namespace App\Services\Business;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BranchService
{
    public function __construct(private TenantContext $tenant) {}

    public function create(array $data): Branch
    {
        return DB::transaction(function () use ($data) {
            $makeMain = (bool) ($data['is_main'] ?? false);
            if ($makeMain) {
                Branch::query()->where('is_main', true)->update(['is_main' => false]);
            }

            $branch = Branch::create(array_merge(
                collect($data)->only(['name', 'code', 'address', 'phone', 'email', 'manager_id', 'is_active'])->all(),
                ['is_main' => $makeMain],
            ));

            $this->syncUsers($branch, $data);
            ActivityLog::record('branch.created', $branch, ['code' => $branch->code]);

            return $branch;
        });
    }

    public function update(Branch $branch, array $data): Branch
    {
        return DB::transaction(function () use ($branch, $data) {
            if (($data['is_main'] ?? false) && ! $branch->is_main) {
                Branch::query()->where('is_main', true)->update(['is_main' => false]);
            } elseif (array_key_exists('is_main', $data) && ! $data['is_main'] && $branch->is_main) {
                throw ValidationException::withMessages(['is_main' => 'Choose another main branch instead of unsetting this one.']);
            }

            if ($branch->is_main && array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => 'The main branch cannot be deactivated.']);
            }

            $branch->fill(collect($data)->only(['name', 'code', 'address', 'phone', 'email', 'manager_id', 'is_active', 'is_main'])->all())->save();
            $this->syncUsers($branch, $data);
            ActivityLog::record('branch.updated', $branch);

            return $branch;
        });
    }

    public function delete(Branch $branch): void
    {
        if ($branch->is_main) {
            throw ValidationException::withMessages(['branch' => 'The main branch cannot be deleted.']);
        }
        // Later phases add: refuse when the branch has stock or transactions (archive instead).
        $branch->delete();
        ActivityLog::record('branch.deleted', $branch);
    }

    private function syncUsers(Branch $branch, array $data): void
    {
        if (! array_key_exists('user_ids', $data)) {
            return;
        }
        // FormRequest already guarantees ids belong to this business; filter again as defence in depth.
        $allowed = DB::table('business_user')->where('business_id', $this->tenant->businessId())
            ->whereIn('user_id', $data['user_ids'] ?? [])->pluck('user_id')->all();
        $branch->users()->sync($allowed);
    }
}
