<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cost layer / batch of stock (FIFO costing and expiry tracking).
 * Not mass-assignable from requests: only the inventory services create/modify these rows.
 */
class ProductBatch extends Model
{
    use BelongsToBusiness;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'string', 'unit_cost' => 'string', 'expiry_date' => 'date', 'received_at' => 'datetime'];
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
}
