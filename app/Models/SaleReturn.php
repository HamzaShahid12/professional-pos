<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Goods returned by a customer. Immutable once posted. */
class SaleReturn extends Model
{
    use BelongsToBusiness;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['return_date' => 'date', 'refund_total' => 'string'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Sale returns are immutable.'));
        static::deleting(fn () => throw new LogicException('Sale returns are immutable.'));
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
