<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only price history. */
class ProductPrice extends Model
{
    use BelongsToBusiness;

    public const UPDATED_AT = null;

    protected $fillable = ['business_id', 'product_id', 'variant_id', 'price_type', 'old_price', 'new_price', 'changed_by', 'reason'];

    protected function casts(): array
    {
        return ['old_price' => 'string', 'new_price' => 'string', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Price history is immutable.'));
        static::deleting(fn () => throw new LogicException('Price history is immutable.'));
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id')->withTrashed();
    }
}
