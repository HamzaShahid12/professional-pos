<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Posted receipt/payment document. Immutable: reverse a mistake with a ledger adjustment. */
class PartyPayment extends Model
{
    use BelongsToBusiness;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['paid_at' => 'date', 'amount' => 'string'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Payments are immutable.'));
        static::deleting(fn () => throw new LogicException('Payments are immutable.'));
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
