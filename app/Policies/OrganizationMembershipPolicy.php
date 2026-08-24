<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class OrganizationMembershipPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'members.view');
    }

    public function view(User $user, OrganizationMembership $membership): bool
    {
        return $user->hasPermission($membership->organization_id, 'members.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'members.create');
    }

    public function update(User $user, OrganizationMembership $membership): bool
    {
        return $user->hasPermission($membership->organization_id, 'members.update');
    }

    public function delete(User $user, OrganizationMembership $membership): bool
    {
        return $user->hasPermission($membership->organization_id, 'members.delete');
    }
}
