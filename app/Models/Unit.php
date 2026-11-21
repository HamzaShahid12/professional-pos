<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $fillable = ['business_id', 'name', 'short_name', 'allow_decimal', 'is_active'];

    protected function casts(): array
    {
        return ['allow_decimal' => 'boolean', 'is_active' => 'boolean'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
