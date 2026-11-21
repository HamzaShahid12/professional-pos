<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * The tenant root. Intentionally NOT business-scoped: access is governed by membership (User::accessibleBusinesses).
 */
class Business extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'legal_name', 'tax_number', 'logo_path', 'email', 'phone',
        'address', 'country', 'timezone', 'base_currency_code', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('is_owner')->withTimestamps();
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? \App\Support\PublicUrl::of($this->logo_path) : null;
    }
}
