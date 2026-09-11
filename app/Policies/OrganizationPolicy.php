<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->organizationMemberships()->where('status', 'active')->exists();
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'organizations.view');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'organizations.update');
    }

    public function updateSettings(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'settings.update');
    }

    /**
     * Read-only access to settings screens (document profile, quotation
     * settings, …). `settings.update` still implies read access — this only
     * adds the ability to grant viewing without granting mutation.
     */
    public function viewSettings(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'settings.view')
            || $user->hasPermission($organization, 'settings.update');
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'organizations.delete');
    }
}
