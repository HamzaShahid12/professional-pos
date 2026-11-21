<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Goods sent back to a supplier. Immutable once posted. */
class PurchaseReturn extends Model
{
    use BelongsToBusiness;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['return_date' => 'date', 'total_base' => 'string'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Purchase returns are immutable.'));
        static::deleting(fn () => throw new LogicException('Purchase returns are immutable.'));
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
