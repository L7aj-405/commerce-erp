<?php

namespace App\Services\Contacts;

use App\Models\Customer;
use App\Models\OrganizationContact;
use App\Models\Party;
use App\Models\Supplier;

/**
 * Resolves a LEGACY record to its canonical Party through the record's own party_id. Customer, supplier and legacy contact
 * ids are different namespaces from party ids and are never reinterpreted. A record that has no party yet (created before the
 * unified lifecycle and not backfilled) gets the party the backfill would have given it, through the same synchronizer.
 */
class LegacyPartyResolver
{
    public function __construct(private readonly PartyIdentitySynchronizer $sync) {}

    public function for(Customer|Supplier|OrganizationContact $legacy): Party
    {
        if ($legacy->party_id !== null) {
            return Party::query()->where('organization_id', $legacy->organization_id)->findOrFail($legacy->party_id);
        }

        return $legacy instanceof OrganizationContact ? $this->sync->syncContact($legacy) : $this->sync->syncFromLegacy($legacy);
    }
}
