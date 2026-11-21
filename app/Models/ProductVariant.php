<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $fillable = ['business_id', 'product_id', 'name', 'sku', 'barcode', 'purchase_price', 'selling_price', 'wholesale_price', 'is_active'];

    protected function casts(): array
    {
        return ['purchase_price' => 'string', 'selling_price' => 'string', 'wholesale_price' => 'string', 'is_active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
