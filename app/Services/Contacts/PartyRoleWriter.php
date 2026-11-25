<?php

namespace App\Services\Contacts;

use App\Models\ContactRole;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;

/** The only writer of party_roles: idempotent attach (reactivating a deactivated role) and soft detach. */
class PartyRoleWriter
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return bool whether anything changed */
    public function attach(User $actor, Party $party, ContactRole $role): bool
    {
        $existing = PartyRole::query()->where('party_id', $party->getKey())->where('contact_role_id', $role->getKey())->first();
        if ($existing === null) {
            $row = new PartyRole;
            $row->organization_id = $party->organization_id;
            $row->party_id = $party->getKey();
            $row->contact_role_id = $role->getKey();
            $row->status = PartyRole::STATUS_ACTIVE;
            $row->assigned_by_user_id = $actor->getKey();
            try {
                $row->save();
            } catch (UniqueConstraintViolationException) {
                return false; // a concurrent activation won; the end state is identical
            }
        } elseif ($existing->status === PartyRole::STATUS_ACTIVE) {
            return false;
        } else {
            $existing->status = PartyRole::STATUS_ACTIVE;
            $existing->deactivated_at = null;
            $existing->assigned_by_user_id = $actor->getKey();
            $existing->save();
        }
        $this->audit->record('party.role_assigned', $actor, $party->organization, auditable: $party, newValues: ['role' => $role->name, 'role_id' => $role->getKey()]);

        return true;
    }

    /** The row is kept (history); the commercial profile is never touched. */
    public function detach(User $actor, Party $party, ContactRole $role): bool
    {
        $row = PartyRole::query()->where('party_id', $party->getKey())->where('contact_role_id', $role->getKey())->where('status', PartyRole::STATUS_ACTIVE)->first();
        if ($row === null) {
            return false;
        }
        $row->status = PartyRole::STATUS_INACTIVE;
        $row->deactivated_at = now();
        $row->save();
        $this->audit->record('party.role_deactivated', $actor, $party->organization, auditable: $party, oldValues: ['role' => $role->name], newValues: ['status' => 'inactive']);

        return true;
    }
}
