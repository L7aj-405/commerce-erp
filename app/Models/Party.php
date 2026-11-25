<?php

namespace App\Models;

use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Canonical identity of a person or organization within ONE organization. Authoritative for shared identity
 * fields; customers / suppliers / organization_contacts are commercial profiles or legacy mirrors of it.
 * Carries NO banking data: sensitive supplier banking stays on the supplier profile (see PartyIdentitySerializer).
 */
class Party extends Model
{
    use ScopesToActiveOrganization;

    public const KIND_PERSON = 'person';

    public const KIND_ORGANIZATION = 'organization';

    public const KINDS = [self::KIND_PERSON, self::KIND_ORGANIZATION];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    /** Identity fields shared by every profile (the synchronizer's contract). */
    public const IDENTITY_FIELDS = ['display_name', 'legal_name', 'email', 'phone', 'whatsapp', 'whatsapp_username', 'address', 'city', 'country_code', 'ice', 'tax_identifier', 'rc_number', 'notes'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'archive_snapshot' => 'array'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    public function supplier(): HasOne
    {
        return $this->hasOne(Supplier::class);
    }

    public function partyRoles(): HasMany
    {
        return $this->hasMany(PartyRole::class);
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(PartyRelationship::class);
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }
}
