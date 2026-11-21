<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Line of a Purchase. Tenant isolation comes from the parent document. */
class PurchaseItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'string', 'unit_cost' => 'string', 'discount' => 'string', 'net_amount' => 'string', 'tax_amount' => 'string',
            'line_total' => 'string', 'line_total_base' => 'string', 'net_unit_cost_base' => 'string',
            'returned_qty' => 'string', 'credited_base' => 'string', 'tax_snapshot' => 'array', 'expiry_date' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class)->withTrashed();
    }
}
