<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Models\WooCommerceIntegration;

class WooCommerceIntegrationPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'integrations.view');
    }

    public function view(User $user, WooCommerceIntegration $integration): bool
    {
        return $user->hasPermission($integration->organization_id, 'integrations.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'integrations.manage');
    }

    public function update(User $user, WooCommerceIntegration $integration): bool
    {
        return $user->hasPermission($integration->organization_id, 'integrations.manage');
    }

    public function sync(User $user, WooCommerceIntegration $integration): bool
    {
        return $user->hasPermission($integration->organization_id, 'integrations.sync');
    }
}
