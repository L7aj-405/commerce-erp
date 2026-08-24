<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_memberships')
            ->withPivot(['role_id', 'status'])
            ->withTimestamps();
    }

    public function storeMemberships(): HasMany
    {
        return $this->hasMany(StoreMembership::class);
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_memberships')
            ->withPivot('organization_id')
            ->withTimestamps();
    }

    public function activeOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'active_organization_id');
    }

    public function activeStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'active_store_id');
    }

    public function hasPermission(Organization|int $organization, string $permission): bool
    {
        $organizationId = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $this->organizationMemberships()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereHas('role.permissions', fn ($query) => $query->where('key', $permission))
            ->exists();
    }

    /** @return list<string> */
    public function permissionKeysFor(Organization|int $organization): array
    {
        $organizationId = $organization instanceof Organization ? $organization->getKey() : $organization;

        return Permission::query()
            ->whereHas('roles.memberships', function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId)
                    ->where('user_id', $this->getKey())
                    ->where('status', 'active');
            })
            ->orderBy('key')
            ->pluck('key')
            ->all();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
