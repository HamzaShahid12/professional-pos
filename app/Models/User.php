<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasRoles, Notifiable;

    protected string $guard_name = 'web';

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'avatar_path', 'is_active',
        'current_business_id', 'current_branch_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class)->withPivot('is_owner')->withTimestamps();
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withTimestamps();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Permissions::SUPER_ADMIN);
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    /** Businesses this user may operate in. Super Admin sees all. */
    public function accessibleBusinesses(): Builder
    {
        if ($this->isSuperAdmin()) {
            return Business::query();
        }

        return Business::query()->whereIn('businesses.id', function ($q) {
            $q->select('business_id')->from('business_user')->where('user_id', $this->id);
        });
    }

    public function canAccessBusiness(int $businessId): bool
    {
        return $this->accessibleBusinesses()->where('businesses.id', $businessId)->exists();
    }

    public function hasFullBranchAccess(int $businessId): bool
    {
        if ($this->hasAnyRole(Permissions::ALL_BRANCH_ROLES)) {
            return true;
        }

        return $this->businesses()->where('businesses.id', $businessId)->wherePivot('is_owner', true)->exists();
    }

    /**
     * Branch ids the user may work in inside the given business.
     *
     * @return Collection<int, int>
     */
    public function accessibleBranchIds(int $businessId): Collection
    {
        $query = Branch::withoutGlobalScopes()->where('business_id', $businessId)->where('is_active', true);

        if (! $this->hasFullBranchAccess($businessId)) {
            $query->whereIn('id', function ($q) {
                $q->select('branch_id')->from('branch_user')->where('user_id', $this->id);
            });
        }

        return $query->orderByDesc('is_main')->orderBy('name')->pluck('id');
    }
}
