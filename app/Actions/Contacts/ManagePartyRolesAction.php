<?php

namespace App\Actions\Contacts;

use App\Actions\Contacts\Concerns\AuthorizesContactAction;
use App\Enums\ContactSystemRole;
use App\Models\ContactRole;
use App\Models\Organization;
use App\Models\Party;
use App\Models\User;
use App\Services\Contacts\ContactRoleRegistry;
use App\Services\Contacts\PartyLifecycleGuard;
use App\Services\Contacts\PartyRoleWriter;
use Illuminate\Support\Facades\DB;

/**
 * Add / remove roles on an existing party.
 *
 *  - Client / Fournisseur are commercial: activating them reuses or creates the profile (see ActivateCommercialProfileAction)
 *    and needs the customers.create / suppliers.manage permission on top of contacts.update.
 *  - Every other role is a plain label (contacts.update). A custom role entered through "Autre" is reused when it already
 *    exists; creating a NEW one additionally needs contacts.roles.manage.
 *  - Removing a role is a soft deactivation. Commercial roles are refused while money is open; the profile is never deleted.
 */
class ManagePartyRolesAction
{
    use AuthorizesContactAction;

    public function __construct(
        private readonly ContactRoleRegistry $roles,
        private readonly PartyRoleWriter $writer,
        private readonly ActivateCommercialProfileAction $profiles,
        private readonly PartyLifecycleGuard $guard,
    ) {}

    /**
     * @param  list<ContactSystemRole|int|string>  $roles  system role enums, role ids, or custom role names (the "Autre" entry)
     * @return list<ContactRole> the roles now active on the party
     */
    public function add(User $actor, Organization $organization, Party $party, array $roles): array
    {
        $this->authorizeContact($actor, $organization, 'contacts.update');
        $this->assertPartyInOrganization($party, $organization);

        return DB::transaction(function () use ($actor, $organization, $party, $roles) {
            $locked = Party::query()->where('organization_id', $organization->getKey())->whereKey($party->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isArchived()) {
                $this->refuse('party', 'Un contact archivé doit être restauré avant de modifier ses rôles.');
            }
            $applied = [];
            foreach ($roles as $input) {
                $role = $this->resolve($actor, $organization, $input);
                if ($role->system_key === ContactSystemRole::Client->value) {
                    $this->profiles->customer($actor, $organization, $locked);
                } elseif ($role->system_key === ContactSystemRole::Supplier->value) {
                    $this->profiles->supplier($actor, $organization, $locked);
                } else {
                    $this->writer->attach($actor, $locked, $role);
                }
                $applied[] = $role;
            }

            return $applied;
        });
    }

    public function remove(User $actor, Organization $organization, Party $party, ContactRole $role): void
    {
        $this->authorizeContact($actor, $organization, 'contacts.update');
        $this->assertPartyInOrganization($party, $organization);
        $role = $this->roles->findForOrganization($role->getKey(), $organization);

        DB::transaction(function () use ($actor, $organization, $party, $role) {
            $locked = Party::query()->where('organization_id', $organization->getKey())->whereKey($party->getKey())->lockForUpdate()->firstOrFail();
            if (in_array($role->system_key, [ContactSystemRole::Client->value, ContactSystemRole::Supplier->value], true)) {
                $blockers = $this->guard->blockers($locked, $role->system_key);
                if ($blockers !== []) {
                    $this->refuse('role', 'Rôle commercial protégé : des opérations sont en cours ('.implode(', ', $blockers).').');
                }
            }
            $this->writer->detach($actor, $locked, $role);
        });
    }

    private function resolve(User $actor, Organization $organization, ContactSystemRole|int|string $input): ContactRole
    {
        if ($input instanceof ContactSystemRole) {
            return $this->roles->system($input);
        }
        if (is_int($input)) {
            return $this->roles->findForOrganization($input, $organization);
        }
        // "Autre": reuse an existing role of that name, otherwise persist a new reusable custom role (authorized separately).
        $existing = $this->roles->find($organization, $input);
        if ($existing === null) {
            $this->authorizeContact($actor, $organization, 'contacts.roles.manage');
        }

        return $this->roles->findOrCreateCustom($actor, $organization, $input);
    }
}
