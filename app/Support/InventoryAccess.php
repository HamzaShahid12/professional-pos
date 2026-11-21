<?php

namespace App\Support;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Which warehouses a user may see and use.
 * Owners / admins (full branch access) see every warehouse of the business, including ones without a branch.
 * Everybody else only sees warehouses that belong to a branch they are assigned to.
 */
class InventoryAccess
{
    public function __construct(private TenantContext $tenant) {}

    /** @return Collection<int, int> */
    public function warehouseIds(User $user, bool $activeOnly = false): Collection
    {
        $businessId = $this->tenant->businessId();
        if (! $businessId) {
            return collect();
        }

        $query = Warehouse::query(); // business scoped

        if ($activeOnly) {
            $query->where('is_active', true);
        }
        if (! $user->hasFullBranchAccess($businessId)) {
            $query->whereIn('branch_id', $user->accessibleBranchIds($businessId));
        }

        return $query->pluck('id');
    }

    /** Warehouses for dropdowns. */
    public function warehouses(User $user, bool $activeOnly = false): Collection
    {
        return Warehouse::query()->whereIn('id', $this->warehouseIds($user, $activeOnly))
            ->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'code', 'branch_id']);
    }

    /** Returns the warehouse or fails with a validation error (never reveals other businesses' warehouses). */
    public function assertUsable(User $user, int $warehouseId, string $field = 'warehouse_id'): Warehouse
    {
        $warehouse = Warehouse::query()->where('is_active', true)->find($warehouseId);

        if (! $warehouse || ! $this->warehouseIds($user, true)->contains($warehouse->id)) {
            throw ValidationException::withMessages([$field => 'This warehouse is not available to you.']);
        }

        return $warehouse;
    }

    public function canView(User $user, int $warehouseId): bool
    {
        return $this->warehouseIds($user)->contains($warehouseId);
    }
}
