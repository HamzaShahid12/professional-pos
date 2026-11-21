<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tax extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $fillable = ['business_id', 'name', 'rate', 'is_inclusive', 'tax_account_id', 'is_active'];

    protected function casts(): array
    {
        return ['rate' => 'string', 'is_inclusive' => 'boolean', 'is_active' => 'boolean'];
    }
}
