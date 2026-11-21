<?php

namespace App\Services\Dashboard;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\Supplier;
use App\Support\InventoryAccess;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\InventoryQueryService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/** Phase 1 dashboard: organisation metrics from real data. Sales/finance cards are added in later phases. */
class DashboardService
{
    public function __construct(private TenantContext $tenant, private InventoryQueryService $inventory) {}

    public function overview(User $user): array
    {
        $businessId = $this->tenant->businessId();

        $canSeeStock = $user->can('inventory.view');
        $money = fn (string $class) => (string) $class::query()->where('balance', '>', 0)->sum('balance');

        $today = null;
        if ($user->can('sales.view')) {
            $day = now($this->tenant->business()?->timezone ?? config('app.timezone'))->toDateString();
            $row = Sale::query()->where('status', 'completed')->soldBetween($day, $day)
                ->whereIn('warehouse_id', app(InventoryAccess::class)->warehouseIds($user))
                ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total), 0) AS total')->first();
            $today = ['count' => (int) $row->n, 'total' => (string) $row->total];
        }

        return [
            'sales_today' => $today,
            'accounts' => [
                'receivable' => $user->can('customers.manage') ? $money(Customer::class) : null,
                'payable' => $user->can('suppliers.manage') ? $money(Supplier::class) : null,
            ],
            'inventory' => $canSeeStock ? [
                'counts' => $this->inventory->alertCounts($user),
                'valuation' => $this->inventory->valuation($user),
                'alerts' => $this->inventory->alerts($user),
            ] : null,
            'stats' => [
                'branches' => Branch::query()->where('is_active', true)->count(),
                'users' => DB::table('business_user')->where('business_id', $businessId)->count(),
                'currencies' => Currency::query()->where('is_active', true)->count(),
                'products' => Product::query()->where('is_active', true)->count(),
            ],
            'recent_activity' => ActivityLog::query()->with('user:id,name')
                ->where('business_id', $businessId)->latest('created_at')->latest('id')->limit(10)->get()
                ->map(fn ($l) => [
                    'id' => $l->id,
                    'action' => $l->action,
                    'user' => $l->user?->name,
                    'at' => $l->created_at?->toIso8601String(),
                ])->all(),
        ];
    }
}
