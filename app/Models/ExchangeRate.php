<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only. Historical exchange rates are never changed or removed. */
class ExchangeRate extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'currency_id', 'rate', 'effective_at', 'source', 'created_by'];

    protected function casts(): array
    {
        return ['rate' => 'string', 'effective_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Exchange rate history is immutable.'));
        static::deleting(fn () => throw new LogicException('Exchange rate history is immutable.'));
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
