<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\ActiveTenantContext;

class StorePolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->isActiveOrganization($organization->getKey())
            && $user->hasPermission($organization, 'stores.view');
    }

    public function view(User $user, Store $store): bool
    {
        return $this->isActiveOrganization($store->organization_id)
            && $user->hasPermission($store->organization_id, 'stores.view')
            && $store->memberships()->where('user_id', $user->getKey())->exists();
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->isActiveOrganization($organization->getKey())
            && $user->hasPermission($organization, 'stores.create');
    }

    public function update(User $user, Store $store): bool
    {
        return $this->isActiveOrganization($store->organization_id)
            && $user->hasPermission($store->organization_id, 'stores.update')
            && $store->memberships()->where('user_id', $user->getKey())->exists();
    }

    public function delete(User $user, Store $store): bool
    {
        return $this->isActiveOrganization($store->organization_id)
            && $user->hasPermission($store->organization_id, 'stores.delete')
            && $store->memberships()->where('user_id', $user->getKey())->exists();
    }

    private function isActiveOrganization(int $organizationId): bool
    {
        return $this->context->organization()?->getKey() === $organizationId;
    }
}
