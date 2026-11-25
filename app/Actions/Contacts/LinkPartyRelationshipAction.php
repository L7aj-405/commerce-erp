<?php

namespace App\Actions\Contacts;

use App\Actions\Contacts\Concerns\AuthorizesContactAction;
use App\Models\Organization;
use App\Models\Party;
use App\Models\PartyRelationship;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Links a person to an organization (employee / interlocutor ...) WITHOUT merging their identities: the person keeps
 * their own party, the company keeps theirs. Idempotent per (person, organization, type). Same organization only.
 */
class LinkPartyRelationshipAction
{
    use AuthorizesContactAction;

    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Organization $organization, Party $person, Party $company, string $type = 'interlocutor', ?string $jobTitle = null, bool $primary = false): PartyRelationship
    {
        $this->authorizeContact($actor, $organization, 'contacts.update');
        $this->assertPartyInOrganization($person, $organization);
        $this->assertPartyInOrganization($company, $organization);
        if (! in_array($type, PartyRelationship::TYPES, true)) {
            $this->refuse('type', 'Type de relation inconnu.');
        }
        if ($person->kind !== Party::KIND_PERSON || $company->kind !== Party::KIND_ORGANIZATION) {
            $this->refuse('party', 'Une relation lie une personne à une organisation.');
        }
        if ($person->isArchived() || $company->isArchived()) {
            $this->refuse('party', 'Un contact archivé ne peut pas être relié.');
        }

        return DB::transaction(function () use ($actor, $organization, $person, $company, $type, $jobTitle, $primary) {
            $find = fn () => PartyRelationship::query()->where('party_id', $person->getKey())->where('related_party_id', $company->getKey())->where('type', $type)->first();
            $row = $find();
            $created = $row === null;
            if ($created) {
                $row = new PartyRelationship;
                $row->organization_id = $organization->getKey();
                $row->party_id = $person->getKey();
                $row->related_party_id = $company->getKey();
                $row->type = $type;
            }
            $row->job_title = $jobTitle !== null && trim($jobTitle) !== '' ? trim($jobTitle) : $row->job_title;
            $row->active = true;
            if ($primary) {
                PartyRelationship::query()->where('party_id', $person->getKey())->update(['is_primary' => false]);
                $row->is_primary = true;
            }
            try {
                $row->save();
            } catch (UniqueConstraintViolationException) {
                $row = $find() ?? throw new \LogicException('Relationship vanished after a unique violation.');
                $created = false;
            }
            if ($created) {
                $this->audit->record('party.relationship_linked', $actor, $organization, auditable: $person, newValues: ['related_party_id' => $company->getKey(), 'type' => $type]);
            }

            return $row;
        });
    }
}
