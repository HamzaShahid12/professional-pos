<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Immutable line of a till's cash ledger. Positive = cash into the drawer. */
class CashMovement extends Model
{
    use BelongsToBusiness;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'string', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Cash movements are immutable.'));
        static::deleting(fn () => throw new LogicException('Cash movements are immutable.'));
    }
}
