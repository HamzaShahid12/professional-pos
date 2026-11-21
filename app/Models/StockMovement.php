<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Immutable stock ledger row. Corrections are made with new, opposite movements. */
class StockMovement extends Model
{
    use BelongsToBusiness;

    public const UPDATED_AT = null;

    public const TYPES = [
        'opening', 'purchase_received', 'sale', 'sale_return', 'purchase_return',
        'adjustment_in', 'adjustment_out', 'transfer_out', 'transfer_in',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'string', 'unit_cost' => 'string', 'total_cost' => 'string', 'balance_after' => 'string',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock movements are immutable.'));
        static::deleting(fn () => throw new LogicException('Stock movements are immutable.'));
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class)->withTrashed();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
