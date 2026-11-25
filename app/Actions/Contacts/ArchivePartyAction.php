<?php

namespace App\Actions\Contacts;

use App\Actions\Contacts\Concerns\AuthorizesContactAction;
use App\Models\Organization;
use App\Models\Party;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Contacts\PartyLifecycleGuard;
use Illuminate\Support\Facades\DB;

/**
 * Protected soft archive. Never deletes a party, profile, document or financial row. Refused while the party still has
 * open money (PartyLifecycleGuard). Linked legacy contacts are hidden; customer / supplier profiles keep their own state.
 */
class ArchivePartyAction
{
    use AuthorizesContactAction;

    public function __construct(private readonly AuditLogger $audit, private readonly PartyLifecycleGuard $guard) {}

    public function archive(User $actor, Organization $organization, Party $party): Party
    {
        $this->authorizeContact($actor, $organization, 'contacts.archive');
        $this->assertPartyInOrganization($party, $organization);

        return DB::transaction(function () use ($actor, $organization, $party) {
            $locked = Party::query()->where('organization_id', $organization->getKey())->whereKey($party->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isArchived()) {
                return $locked;
            }
            $blockers = $this->guard->blockers($locked);
            if ($blockers !== []) {
                $this->refuse('party', 'Archivage refusé : des opérations sont en cours ('.implode(', ', $blockers).').');
            }
            $locked->status = Party::STATUS_ARCHIVED;
            $locked->archived_at = now();
            $locked->save();
            DB::table('organization_contacts')->where('organization_id', $organization->getKey())->where('party_id', $locked->getKey())
                ->update(['active' => false, 'archived_at' => now(), 'updated_at' => now()]);
            $this->audit->record('party.archived', $actor, $organization, auditable: $locked, newValues: ['display_name' => $locked->display_name]);

            return $locked;
        });
    }

    public function restore(User $actor, Organization $organization, Party $party): Party
    {
        $this->authorizeContact($actor, $organization, 'contacts.archive');
        $this->assertPartyInOrganization($party, $organization);

        return DB::transaction(function () use ($actor, $organization, $party) {
            $locked = Party::query()->where('organization_id', $organization->getKey())->whereKey($party->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isArchived()) {
                return $locked;
            }
            $locked->status = Party::STATUS_ACTIVE;
            $locked->archived_at = null;
            $locked->save();
            DB::table('organization_contacts')->where('organization_id', $organization->getKey())->where('party_id', $locked->getKey())
                ->update(['active' => true, 'archived_at' => null, 'updated_at' => now()]);
            $this->audit->record('party.restored', $actor, $organization, auditable: $locked, newValues: ['display_name' => $locked->display_name]);

            return $locked;
        });
    }
}
