<?php

namespace App\Actions\Contacts;

use App\Models\OrganizationContact;
use App\Models\User;
use App\Services\AuditLogger;

class ArchiveOrganizationContactAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, OrganizationContact $contact): void
    {
        $contact->active = false;
        $contact->archived_at = now();
        $contact->save();

        $this->audit->record('contact.archived', $actor, $contact->organization, auditable: $contact, newValues: [
            'full_name' => $contact->full_name,
            'email' => $contact->email,
        ]);
    }
}
