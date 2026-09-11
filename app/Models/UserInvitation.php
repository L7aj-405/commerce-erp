<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Organization-scoped, one-time, expiring invitation to join with a chosen
 * Role. The plaintext token only ever exists in the invite URL; only its
 * sha256 hash is persisted (see InvitationService).
 */
class UserInvitation extends Model
{
    use HasFactory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }

    /**
     * Admin-side lookups only (list/revoke). Acceptance never binds through
     * this — it looks the row up by hashed token directly, since the visitor
     * accepting it is frequently unauthenticated. Scoped the same way as
     * Role/OrganizationMembership: a foreign tenant's invitation id 404s
     * instead of authorizing/denying distinctly.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->when(Auth::check(), fn ($query) => $query->whereHas(
                'organization.memberships',
                fn ($query) => $query
                    ->where('user_id', Auth::id())
                    ->where('status', 'active'),
            ));
    }
}
