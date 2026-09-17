<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, MustVerifyEmailTrait, Notifiable;

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

    public function receivedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'received_by_user_id');
    }

    public function reversedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'reversed_by_user_id');
    }

    public function issuedInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'issued_by_user_id');
    }

    public function issuedDeliveryNotes(): HasMany
    {
        return $this->hasMany(DeliveryNote::class, 'issued_by_user_id');
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
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function hasEnabledTwoFactorAuthentication(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Sprint 1.1 §7 — organization owners and admins are privileged accounts
     * on a public multi-tenant SaaS (they can manage members, roles,
     * integrations and settings for the whole organization), so 2FA is
     * mandatory for them regardless of the organization's own `require_2fa`
     * toggle — see EnsureTwoFactorPolicy. The `roles` table is unique per
     * (organization_id, slug), so a custom role can never itself take the
     * 'owner'/'admin' slug — checking the slug alone is sufficient.
     */
    public function hasPrivilegedRoleIn(Organization $organization): bool
    {
        return $this->organizationMemberships()
            ->where('organization_id', $organization->getKey())
            ->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->whereIn('slug', ['owner', 'admin']))
            ->exists();
    }
}
