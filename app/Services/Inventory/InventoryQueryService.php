<?php

namespace App\Services\Inventory;

use App\Models\ProductBatch;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\InventoryAccess;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Read side of inventory. Everything is limited to the warehouses the user may see. */
class InventoryQueryService
{
    public function __construct(private InventoryAccess $access, private TenantContext $tenant) {}

    /**
     * Stock per product/variant, summed over the visible warehouses (or the one chosen).
     * status: out = total <= 0; low = total > 0 and <= the product's minimum (when a minimum is set).
     */
    public function overview(User $user, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->aggregate($user, $filters['warehouse_id'] ?? null)
            ->when($filters['search'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('products.name', 'like', $like)->orWhere('products.sku', 'like', $like)->orWhere('products.barcode', 'like', $like));
            })
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('products.category_id', $id));

        match ($filters['status'] ?? null) {
            'out' => $query->havingRaw('SUM(stocks.quantity) <= 0'),
            'low' => $query->havingRaw('SUM(stocks.quantity) > 0 AND products.min_stock > 0 AND SUM(stocks.quantity) <= products.min_stock'),
            'in' => $query->havingRaw('SUM(stocks.quantity) > 0'),
            default => null,
        };

        return $query->orderBy('products.name')->orderBy('stocks.variant_key')->paginate($perPage)->withQueryString()
            ->through(fn ($row) => $this->presentRow($row));
    }

    /** Low and out-of-stock rows for the dashboard. */
    public function alerts(User $user, int $limit = 8): array
    {
        $rows = $this->aggregate($user)
            ->havingRaw('SUM(stocks.quantity) <= 0 OR (products.min_stock > 0 AND SUM(stocks.quantity) <= products.min_stock)')
            ->orderByRaw('SUM(stocks.quantity) ASC')->limit($limit)->get();

        return $rows->map(fn ($r) => $this->presentRow($r))->all();
    }

    /** @return array{low:int,out:int} */
    public function alertCounts(User $user): array
    {
        $row = DB::query()->fromSub($this->aggregate($user), 't')
            ->selectRaw('COALESCE(SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END), 0) AS out_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN quantity > 0 AND min_stock > 0 AND quantity <= min_stock THEN 1 ELSE 0 END), 0) AS low_count')
            ->first();

        return ['low' => (int) $row->low_count, 'out' => (int) $row->out_count];
    }

    /** @return array{quantity:string,value:string} */
    public function valuation(User $user, ?int $warehouseId = null): array
    {
        $row = $this->scopedStock($user, $warehouseId)->join('products', 'products.id', '=', 'stocks.product_id')
            ->whereNull('products.deleted_at')
            ->selectRaw('COALESCE(SUM(stocks.quantity), 0) AS qty, COALESCE(SUM(stocks.total_value), 0) AS val')->first();

        return ['quantity' => (string) $row->qty, 'value' => (string) $row->val];
    }

    public function movements(User $user, array $filters = [], int $perPage = 30): LengthAwarePaginator
    {
        $ids = $this->access->warehouseIds($user);

        return StockMovement::query()->with(['product:id,name,sku', 'variant:id,name', 'warehouse:id,name', 'author:id,name'])
            ->whereIn('warehouse_id', $ids)
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    /** Batches with stock that are expired or expire within `$days` days. */
    public function expiry(User $user, int $days = 30): array
    {
        $tz = $this->tenant->business()?->timezone ?? config('app.timezone');
        $today = now($tz)->startOfDay();

        $batches = ProductBatch::query()->with(['product:id,name,sku', 'variant:id,name', 'warehouse:id,name'])
            ->whereIn('warehouse_id', $this->access->warehouseIds($user))
            ->where('quantity', '>', 0)->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $today->copy()->addDays($days)->toDateString())
            ->orderBy('expiry_date')->orderBy('id')->get();

        return $batches->map(function (ProductBatch $b) use ($today) {
            $expiry = $b->expiry_date->copy()->startOfDay();

            return [
                'id' => $b->id, 'product' => $b->product?->name, 'sku' => $b->product?->sku, 'variant' => $b->variant?->name,
                'warehouse' => $b->warehouse?->name, 'batch_number' => $b->batch_number, 'expiry_date' => $expiry->toDateString(),
                'quantity' => (string) $b->quantity, 'unit_cost' => (string) $b->unit_cost,
                'days_left' => (int) $today->diffInDays($expiry, false),
                'status' => $expiry->lt($today) ? 'expired' : 'expiring',
            ];
        })->all();
    }

    // ---- internals -------------------------------------------------------------------------------

    private function scopedStock(User $user, ?int $warehouseId = null): Builder
    {
        $ids = $this->access->warehouseIds($user);
        if ($warehouseId !== null) {
            $ids = $ids->intersect([(int) $warehouseId])->values();
        }

        return Stock::query()->whereIn('stocks.warehouse_id', $ids);
    }

    private function aggregate(User $user, ?int $warehouseId = null): Builder
    {
        return $this->scopedStock($user, $warehouseId)
            ->join('products', 'products.id', '=', 'stocks.product_id')
            ->whereNull('products.deleted_at')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'stocks.variant_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->groupBy('stocks.product_id', 'stocks.variant_key', 'products.name', 'products.sku', 'products.min_stock',
                'products.category_id', 'product_variants.name', 'product_variants.sku', 'units.short_name')
            ->selectRaw('stocks.product_id, stocks.variant_key, products.name AS product_name, products.sku AS product_sku')
            ->selectRaw('products.min_stock AS min_stock, product_variants.name AS variant_name, product_variants.sku AS variant_sku')
            ->selectRaw('units.short_name AS unit, SUM(stocks.quantity) AS quantity, SUM(stocks.total_value) AS total_value');
    }

    private function presentRow(object $r): array
    {
        $qty = (string) $r->quantity;
        $min = (string) $r->min_stock;
        $q = BigDecimal::of($qty);
        $m = BigDecimal::of($min);
        $status = $q->isLessThanOrEqualTo(0) ? 'out'
            : ($m->isGreaterThan(0) && $q->isLessThanOrEqualTo($m) ? 'low' : 'in');

        return [
            'key' => $r->product_id.'-'.$r->variant_key,
            'product_id' => (int) $r->product_id, 'variant_id' => $r->variant_key ? (int) $r->variant_key : null,
            'name' => $r->product_name, 'variant' => $r->variant_name, 'sku' => $r->variant_sku ?: $r->product_sku,
            'unit' => $r->unit, 'quantity' => $qty, 'min_stock' => $min, 'total_value' => (string) $r->total_value, 'status' => $status,
        ];
    }
}
