<?php

namespace App\Actions\Sales\Concerns;

use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;

trait AuthorizesSalesAction
{
    private function authorizeSales(User $actor, Organization $organization, Store $store, string $permission): void
    {
        abort_unless(
            $actor->active_organization_id === $organization->getKey()
            && $actor->active_store_id === $store->getKey()
            && $organization->status === 'active'
            && $store->organization_id === $organization->getKey()
            && $store->status === 'active'
            && $store->memberships()->where('user_id', $actor->getKey())->exists()
            && $actor->hasPermission($organization, $permission),
            403
        );
    }

    private function authorizeOrder(User $actor, SalesOrder $order, string $permission): void
    {
        $this->authorizeSales($actor, $order->organization, $order->store, $permission);
    }
}
