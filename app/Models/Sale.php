<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** POS / sales invoice. Only SaleService and SaleReturnService write these rows. Completed sales are never edited. */
class Sale extends Model
{
    use BelongsToBusiness;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime', 'voided_at' => 'datetime',
            'subtotal' => 'string', 'discount_total' => 'string', 'tax_total' => 'string', 'total' => 'string',
            'paid_total' => 'string', 'change_due' => 'string', 'balance_due' => 'string', 'refunded_cash' => 'string', 'cogs_total' => 'string',
        ];
    }

    /** Sales between two calendar dates in the BUSINESS time zone (sold_at is stored in the app time zone). */
    public function scopeSoldBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        $tz = app(TenantContext::class)->business()?->timezone ?? config('app.timezone');
        $store = config('app.timezone');

        return $query
            ->when($from, fn ($q) => $q->where('sold_at', '>=', Carbon::parse($from, $tz)->startOfDay()->setTimezone($store)))
            ->when($to, fn ($q) => $q->where('sold_at', '<=', Carbon::parse($to, $tz)->endOfDay()->setTimezone($store)));
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class)->orderBy('id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
