<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use App\Services\ActiveTenantContext;

class StoreMembershipPolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function create(User $user, Store $store): bool
    {
        return $this->isActiveOrganization($store->organization_id)
            && $user->hasPermission($store->organization_id, 'store-memberships.manage')
            && $store->memberships()->where('user_id', $user->getKey())->exists();
    }

    public function delete(User $user, StoreMembership $membership): bool
    {
        return $this->isActiveOrganization($membership->organization_id)
            && $user->hasPermission($membership->organization_id, 'store-memberships.manage')
            && $membership->store->memberships()->where('user_id', $user->getKey())->exists();
    }

    private function isActiveOrganization(int $organizationId): bool
    {
        return $this->context->organization()?->getKey() === $organizationId;
    }
}
