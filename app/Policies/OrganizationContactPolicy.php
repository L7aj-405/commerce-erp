<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\User;
use App\Services\ActiveTenantContext;

class OrganizationContactPolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'contacts.view');
    }

    public function view(User $user, OrganizationContact $contact): bool
    {
        return $this->allowed($user, $contact->organization_id, 'contacts.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->allowed($user, $organization->getKey(), 'contacts.create');
    }

    public function update(User $user, OrganizationContact $contact): bool
    {
        return $this->allowed($user, $contact->organization_id, 'contacts.update');
    }

    public function archive(User $user, OrganizationContact $contact): bool
    {
        return $this->allowed($user, $contact->organization_id, 'contacts.archive');
    }

    private function allowed(User $user, int $organizationId, string $permission): bool
    {
        return $this->context->organization()?->getKey() === $organizationId
            && $user->hasPermission($organizationId, $permission);
    }
}
