<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** `balance` is maintained only by LedgerService (never mass-assignable) and equals the sum of the party's ledger entries. */
class Supplier extends Model
{
    use BelongsToBusiness, SoftDeletes;

    public const PARTY_TYPE = 'supplier';

    protected $fillable = ['business_id', 'code', 'name', 'contact_person', 'phone', 'email', 'address', 'tax_number', 'payment_terms_days', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['payment_terms_days' => 'integer', 'balance' => 'string', 'is_active' => 'boolean'];
    }

    public function scopeSearch($query, ?string $term)
    {
        if (blank($term)) {
            return $query;
        }
        $like = '%'.addcslashes(trim($term), '%_\\').'%';

        return $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like));
    }
}
