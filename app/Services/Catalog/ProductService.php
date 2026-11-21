<?php

namespace App\Services\Catalog;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Rounding;
use Brick\Math\BigDecimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProductService
{
    private const ATTRIBUTES = [
        'name', 'sku', 'barcode', 'description', 'category_id', 'brand_id', 'unit_id',
        'purchase_price', 'selling_price', 'wholesale_price', 'min_selling_price', 'min_stock',
        'is_active', 'track_inventory', 'track_expiry',
    ];

    private const VARIANT_ATTRIBUTES = ['name', 'sku', 'barcode', 'purchase_price', 'selling_price', 'wholesale_price', 'is_active'];

    /** @param UploadedFile[] $images */
    public function create(array $data, array $images, User $by, string $reason = 'initial'): Product
    {
        return DB::transaction(function () use ($data, $images, $by, $reason) {
            $attrs = collect($data)->only(self::ATTRIBUTES)->all();
            $attrs['min_stock'] = $attrs['min_stock'] ?? '0';
            $attrs['has_variants'] = ! empty($data['variants']);

            $product = Product::create($attrs);
            $product->taxes()->sync($data['tax_ids'] ?? []);
            $this->syncVariants($product, $data['variants'] ?? [], $by, $reason);
            $this->recordPrices($product, null, [], $product->only(Product::PRICE_FIELDS), $by, $reason);
            $this->storeImages($product, $images);
            ActivityLog::record('product.created', $product, ['sku' => $product->sku]);

            return $product->refresh()->load($this->relations());
        });
    }

    /** @param UploadedFile[] $images */
    public function update(Product $product, array $data, array $images, User $by): Product
    {
        return DB::transaction(function () use ($product, $data, $images, $by) {
            $product = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $oldPrices = $product->only(Product::PRICE_FIELDS);

            $product->fill(collect($data)->only(self::ATTRIBUTES)->all());
            if (array_key_exists('variants', $data)) {
                $product->has_variants = ! empty($data['variants']);
            }
            $this->guardStockStructure($product);
            $product->save();

            if (array_key_exists('tax_ids', $data)) {
                $product->taxes()->sync($data['tax_ids']);
            }
            if (array_key_exists('variants', $data)) {
                $this->syncVariants($product, $data['variants'], $by, 'update');
            }
            $this->recordPrices($product, null, $oldPrices, $product->only(Product::PRICE_FIELDS), $by, 'update');
            $this->removeImages($product, $data['remove_image_ids'] ?? []);
            $this->storeImages($product, $images);
            ActivityLog::record('product.updated', $product, ['sku' => $product->sku]);

            return $product->refresh()->load($this->relations());
        });
    }

    /** Once a product has stock history its stock structure (tracking on/off, variants on/off) can no longer change. */
    private function guardStockStructure(Product $product): void
    {
        if (! $product->isDirty(['track_inventory', 'has_variants'])) {
            return;
        }
        if (StockMovement::query()->where('product_id', $product->id)->exists()) {
            throw ValidationException::withMessages(['track_inventory' => 'This product already has stock history, so inventory tracking and variants can no longer be switched on or off.']);
        }
    }

    /** Archive (soft delete). Sales, purchases and stock history keep referencing the product. */
    public function archive(Product $product): void
    {
        $onHand = Stock::query()->where('product_id', $product->id)->where('quantity', '!=', 0)->exists();
        if ($onHand) {
            throw ValidationException::withMessages(['product' => 'This product still has stock on hand. Adjust it to zero before archiving.']);
        }

        $product->delete();
        ActivityLog::record('product.archived', $product, ['sku' => $product->sku]);
    }

    public function relations(): array
    {
        return ['category', 'brand', 'unit', 'taxes', 'variants', 'images', 'primaryImage'];
    }

    // ---- variants --------------------------------------------------------------------------------

    private function syncVariants(Product $product, array $variants, User $by, string $reason): void
    {
        $existing = $product->variants()->get()->keyBy('id');
        $keep = [];

        foreach ($variants as $i => $row) {
            $attrs = collect($row)->only(self::VARIANT_ATTRIBUTES)->all();

            if (! empty($row['id'])) {
                $variant = $existing->get((int) $row['id']);
                if (! $variant) {
                    throw ValidationException::withMessages(["variants.$i.id" => 'This variant does not belong to the product.']);
                }
                $old = $variant->only(['purchase_price', 'selling_price', 'wholesale_price']);
                $variant->update($attrs);
                $this->recordPrices($product, $variant, $old, $variant->only(['purchase_price', 'selling_price', 'wholesale_price']), $by, $reason);
            } else {
                $variant = ProductVariant::create($attrs + ['product_id' => $product->id]);
                $this->recordPrices($product, $variant, [], $variant->only(['purchase_price', 'selling_price', 'wholesale_price']), $by, 'initial');
            }
            $keep[] = $variant->id;
        }

        // Variants dropped from the form are archived, never hard-deleted.
        $existing->except($keep)->each->delete();
    }

    // ---- price history ---------------------------------------------------------------------------

    /** Append a history row for every price that actually changed. */
    public function recordPrices(Product $product, ?ProductVariant $variant, array $old, array $new, ?User $by, ?string $reason): void
    {
        foreach ($new as $type => $newValue) {
            $oldValue = $old[$type] ?? null;
            if (! $this->differs($oldValue, $newValue)) {
                continue;
            }
            ProductPrice::create([
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'price_type' => $type,
                'old_price' => $oldValue,
                'new_price' => $newValue,
                'changed_by' => $by?->id,
                'reason' => $reason,
            ]);
        }
    }

    private function differs(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return $a !== $b;
        }

        return ! BigDecimal::of($a)->isEqualTo($b);
    }

    // ---- bulk price update -----------------------------------------------------------------------

    /**
     * @param  array{scope: string, category_id?: int, brand_id?: int, product_ids?: int[], field: string, mode: string, value: string, reason?: string}  $data
     * @return array{updated: int, skipped: int}
     */
    public function bulkUpdatePrices(array $data, User $by): array
    {
        $field = $data['field'];
        $value = BigDecimal::of((string) $data['value']);
        $updated = 0;
        $skipped = 0;
        $reason = $data['reason'] ?? 'bulk update';

        DB::transaction(function () use ($data, $field, $value, $by, $reason, &$updated, &$skipped) {
            Product::query()
                ->when($data['scope'] === 'category', fn ($q) => $q->where('category_id', $data['category_id']))
                ->when($data['scope'] === 'brand', fn ($q) => $q->where('brand_id', $data['brand_id']))
                ->when($data['scope'] === 'selected', fn ($q) => $q->whereIn('id', $data['product_ids'] ?? []))
                ->lockForUpdate()
                ->each(function (Product $product) use ($data, $field, $value, $by, $reason, &$updated, &$skipped) {
                    $current = $product->{$field};
                    if ($current === null && $data['mode'] !== 'set') {
                        $skipped++;

                        return;
                    }
                    $base = BigDecimal::of($current ?? '0');
                    $new = match ($data['mode']) {
                        'percent' => $base->multipliedBy(BigDecimal::of(100)->plus($value))->dividedBy(100, 12, Rounding::halfUp()),
                        'add' => $base->plus($value),
                        default => $value,
                    };
                    if ($new->isNegative()) {
                        $new = BigDecimal::zero();
                    }
                    $new = (string) $new->toScale(4, Rounding::halfUp());

                    // Never push the selling price below the minimum selling price (or the minimum above selling price).
                    $violates = ($field === 'selling_price' && $product->min_selling_price !== null && BigDecimal::of($new)->isLessThan($product->min_selling_price))
                        || ($field === 'min_selling_price' && BigDecimal::of($new)->isGreaterThan($product->selling_price));
                    if ($violates) {
                        $skipped++;

                        return;
                    }
                    if (! $this->differs($current, $new)) {
                        return;
                    }

                    $old = $product->only(Product::PRICE_FIELDS);
                    $product->update([$field => $new]);
                    $this->recordPrices($product, null, $old, $product->only(Product::PRICE_FIELDS), $by, $reason);
                    $updated++;
                });
        });

        ActivityLog::record('product.bulk_price_update', null, ['field' => $field, 'mode' => $data['mode'], 'updated' => $updated, 'skipped' => $skipped]);

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    // ---- images ----------------------------------------------------------------------------------

    /** @param UploadedFile[] $images */
    private function storeImages(Product $product, array $images): void
    {
        $hasPrimary = ProductImage::query()->where('product_id', $product->id)->where('is_primary', true)->exists();
        $order = (int) ProductImage::query()->where('product_id', $product->id)->max('sort_order');

        foreach ($images as $file) {
            // Generated filename on the public disk; only validated image uploads reach here (see ProductRequest).
            $path = $file->store('products', 'public');
            ProductImage::create([
                'product_id' => $product->id,
                'path' => $path,
                'is_primary' => ! $hasPrimary,
                'sort_order' => ++$order,
            ]);
            $hasPrimary = true;
        }
    }

    private function removeImages(Product $product, array $ids): void
    {
        if (! $ids) {
            return;
        }
        $images = ProductImage::query()->where('product_id', $product->id)->whereIn('id', $ids)->get();
        foreach ($images as $image) {
            Storage::disk('public')->delete($image->path);
            $image->delete();
        }
        // Keep one primary image if any remain.
        if (! ProductImage::query()->where('product_id', $product->id)->where('is_primary', true)->exists()) {
            ProductImage::query()->where('product_id', $product->id)->orderBy('sort_order')->limit(1)->update(['is_primary' => true]);
        }
    }
}
