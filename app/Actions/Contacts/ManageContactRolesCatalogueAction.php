<?php

namespace App\Actions\Contacts;

use App\Actions\Contacts\Concerns\AuthorizesContactAction;
use App\Models\ContactRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Contacts\ContactRoleRegistry;

/** Administration of the organization custom roles (contacts.roles.manage). System roles are protected. */
class ManageContactRolesCatalogueAction
{
    use AuthorizesContactAction;

    public function __construct(private readonly ContactRoleRegistry $registry) {}

    public function create(User $actor, Organization $organization, string $name): ContactRole
    {
        $this->authorizeContact($actor, $organization, 'contacts.roles.manage');

        return $this->registry->createCustom($actor, $organization, $name);
    }

    public function deactivate(User $actor, Organization $organization, ContactRole $role): void
    {
        $this->authorizeContact($actor, $organization, 'contacts.roles.manage');
        $this->registry->deactivateCustom($actor, $organization, $role);
    }
}
