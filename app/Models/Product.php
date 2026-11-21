<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use BelongsToBusiness, SoftDeletes;

    public const PRICE_FIELDS = ['purchase_price', 'selling_price', 'wholesale_price', 'min_selling_price'];

    protected $fillable = [
        'business_id', 'category_id', 'brand_id', 'unit_id', 'name', 'sku', 'barcode', 'description',
        'purchase_price', 'selling_price', 'wholesale_price', 'min_selling_price', 'min_stock',
        'is_active', 'track_inventory', 'track_expiry', 'has_variants',
    ];

    protected function casts(): array
    {
        // Money/quantities stay strings: exact decimals, never floats.
        return [
            'purchase_price' => 'string', 'selling_price' => 'string', 'wholesale_price' => 'string',
            'min_selling_price' => 'string', 'min_stock' => 'string',
            'is_active' => 'boolean', 'track_inventory' => 'boolean', 'track_expiry' => 'boolean', 'has_variants' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }

    public function taxes(): BelongsToMany
    {
        return $this->belongsToMany(Tax::class, 'product_taxes');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class)->orderByDesc('id');
    }

    public function getImageUrlAttribute(): ?string
    {
        $image = $this->relationLoaded('primaryImage') ? $this->primaryImage : $this->primaryImage()->first();

        return $image ? \App\Support\PublicUrl::of($image->path) : null;
    }

    /** Name, SKU or barcode. Exact barcode/SKU matches are what a scanner sends. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('barcode', $term)
            ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', $like)->orWhere('barcode', $term)));
    }
}
