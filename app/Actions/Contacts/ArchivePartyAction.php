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
            // Take the commercial profiles out of the pickers, remembering what was switched off so a restore reinstates only that.
            $snapshot = [];
            if (DB::table('customers')->where('organization_id', $organization->getKey())->where('party_id', $locked->getKey())->where('status', 'active')->update(['status' => 'inactive', 'updated_at' => now()]) > 0) {
                $snapshot['customer'] = true;
            }
            if (DB::table('suppliers')->where('organization_id', $organization->getKey())->where('party_id', $locked->getKey())->where('active', true)->update(['active' => false, 'updated_at' => now()]) > 0) {
                $snapshot['supplier'] = true;
            }
            $locked->status = Party::STATUS_ARCHIVED;
            $locked->archived_at = now();
            $locked->archive_snapshot = $snapshot;
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
            $snapshot = (array) ($locked->archive_snapshot ?? []);
            if (! empty($snapshot['customer'])) {
                DB::table('customers')->where('organization_id', $organization->getKey())->where('party_id', $locked->getKey())->where('status', 'inactive')->update(['status' => 'active', 'updated_at' => now()]);
            }
            if (! empty($snapshot['supplier'])) {
                DB::table('suppliers')->where('organization_id', $organization->getKey())->where('party_id', $locked->getKey())->where('active', false)->update(['active' => true, 'updated_at' => now()]);
            }
            $locked->status = Party::STATUS_ACTIVE;
            $locked->archived_at = null;
            $locked->archive_snapshot = null;
            $locked->save();
            DB::table('organization_contacts')->where('organization_id', $organization->getKey())->where('party_id', $locked->getKey())
                ->update(['active' => true, 'archived_at' => null, 'updated_at' => now()]);
            $this->audit->record('party.restored', $actor, $organization, auditable: $locked, newValues: ['display_name' => $locked->display_name]);

            return $locked;
        });
    }
}
