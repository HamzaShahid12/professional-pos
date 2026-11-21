<?php

namespace App\Services\Catalog;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Business rules for categories, brands, units, taxes and warehouses. */
class MasterDataService
{
    // ---- categories ----------------------------------------------------------------------------

    public function saveCategory(array $data, ?Category $category = null): Category
    {
        if ($category && ! empty($data['parent_id'])) {
            $this->assertNoCycle($category, (int) $data['parent_id']);
        }

        $attrs = collect($data)->only(['parent_id', 'name', 'description', 'is_active'])->all();
        $category = $category ? tap($category)->update($attrs) : Category::create($attrs);
        ActivityLog::record($category->wasRecentlyCreated ? 'category.created' : 'category.updated', $category);

        return $category;
    }

    /** A category cannot be its own parent or sit under one of its descendants. */
    private function assertNoCycle(Category $category, int $parentId): void
    {
        $cursor = $parentId;
        $guard = 0;
        while ($cursor && $guard++ < 50) {
            if ($cursor === $category->id) {
                throw ValidationException::withMessages(['parent_id' => 'A category cannot be placed under itself or its own sub-categories.']);
            }
            $cursor = Category::query()->whereKey($cursor)->value('parent_id');
        }
    }

    public function deleteCategory(Category $category): void
    {
        if (Category::query()->where('parent_id', $category->id)->exists()) {
            throw ValidationException::withMessages(['category' => 'Move or delete the sub-categories first.']);
        }
        $this->assertUnused(Product::query()->where('category_id', $category->id), 'category', 'products');
        $category->delete();
        ActivityLog::record('category.deleted', $category);
    }

    // ---- brands / units / taxes ----------------------------------------------------------------

    public function saveBrand(array $data, ?Brand $brand = null): Brand
    {
        $attrs = collect($data)->only(['name', 'description', 'is_active'])->all();
        $brand = $brand ? tap($brand)->update($attrs) : Brand::create($attrs);
        ActivityLog::record($brand->wasRecentlyCreated ? 'brand.created' : 'brand.updated', $brand);

        return $brand;
    }

    public function deleteBrand(Brand $brand): void
    {
        $this->assertUnused(Product::query()->where('brand_id', $brand->id), 'brand', 'products');
        $brand->delete();
        ActivityLog::record('brand.deleted', $brand);
    }

    public function saveUnit(array $data, ?Unit $unit = null): Unit
    {
        $attrs = collect($data)->only(['name', 'short_name', 'allow_decimal', 'is_active'])->all();
        $unit = $unit ? tap($unit)->update($attrs) : Unit::create($attrs);
        ActivityLog::record($unit->wasRecentlyCreated ? 'unit.created' : 'unit.updated', $unit);

        return $unit;
    }

    public function deleteUnit(Unit $unit): void
    {
        $this->assertUnused(Product::query()->where('unit_id', $unit->id), 'unit', 'products');
        $unit->delete();
        ActivityLog::record('unit.deleted', $unit);
    }

    public function saveTax(array $data, ?Tax $tax = null): Tax
    {
        $attrs = collect($data)->only(['name', 'rate', 'is_inclusive', 'is_active'])->all();
        $tax = $tax ? tap($tax)->update($attrs) : Tax::create($attrs);
        ActivityLog::record($tax->wasRecentlyCreated ? 'tax.created' : 'tax.updated', $tax, ['rate' => $tax->rate]);

        return $tax;
    }

    public function deleteTax(Tax $tax): void
    {
        if (DB::table('product_taxes')->where('tax_id', $tax->id)->exists()) {
            throw ValidationException::withMessages(['tax' => 'This tax is used by products. Deactivate it instead of deleting.']);
        }
        $tax->delete();
        ActivityLog::record('tax.deleted', $tax);
    }

    // ---- warehouses ----------------------------------------------------------------------------

    public function saveWarehouse(array $data, ?Warehouse $warehouse = null): Warehouse
    {
        return DB::transaction(function () use ($data, $warehouse) {
            $makeDefault = (bool) ($data['is_default'] ?? false);
            if ($warehouse && $warehouse->is_default && array_key_exists('is_default', $data) && ! $makeDefault) {
                throw ValidationException::withMessages(['is_default' => 'Choose another default warehouse instead of unsetting this one.']);
            }
            if ($warehouse && $warehouse->is_default && array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => 'The default warehouse cannot be deactivated.']);
            }
            if ($makeDefault) {
                Warehouse::query()->where('is_default', true)->update(['is_default' => false]);
            }

            $attrs = collect($data)->only(['branch_id', 'name', 'code', 'address', 'is_default', 'is_active'])->all();
            $warehouse = $warehouse ? tap($warehouse)->update($attrs) : Warehouse::create($attrs);
            ActivityLog::record($warehouse->wasRecentlyCreated ? 'warehouse.created' : 'warehouse.updated', $warehouse);

            return $warehouse;
        });
    }

    public function deleteWarehouse(Warehouse $warehouse): void
    {
        if ($warehouse->is_default) {
            throw ValidationException::withMessages(['warehouse' => 'The default warehouse cannot be deleted.']);
        }
        if (Stock::query()->where('warehouse_id', $warehouse->id)->where('quantity', '!=', 0)->exists()
            || StockMovement::query()->where('warehouse_id', $warehouse->id)->exists()) {
            throw ValidationException::withMessages(['warehouse' => 'This warehouse holds stock or has stock history. Deactivate it instead of deleting.']);
        }
        $warehouse->delete();
        ActivityLog::record('warehouse.deleted', $warehouse);
    }

    private function assertUnused($query, string $what, string $by): void
    {
        if ($query->exists()) {
            throw ValidationException::withMessages([$what => "This {$what} is used by {$by}. Deactivate it instead of deleting."]);
        }
    }
}
