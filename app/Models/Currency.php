<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'code', 'name', 'symbol', 'decimal_places', 'exchange_rate', 'is_base', 'is_active'];

    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            // Kept as string to avoid float rounding; math goes through brick/math in CurrencyService.
            'exchange_rate' => 'string',
            'is_base' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class)->orderByDesc('effective_at')->orderByDesc('id');
    }
}
