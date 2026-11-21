<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Line of a Sale (tenant isolation comes from the parent). Name/SKU/prices are snapshots. */
class SaleItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'string', 'unit_price' => 'string', 'discount' => 'string', 'net_amount' => 'string', 'tax_amount' => 'string',
            'line_total' => 'string', 'cost_total' => 'string', 'returned_qty' => 'string', 'refunded_total' => 'string', 'returned_cost' => 'string',
            'tax_snapshot' => 'array', 'tracks_stock' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
