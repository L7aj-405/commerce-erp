<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\ActiveTenantContext;

class SalesOrderPolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization, Store $store): bool
    {
        return $this->scope($organization->getKey(), $store->getKey()) && $user->hasPermission($organization, 'sales_orders.view');
    }

    public function view(User $user, SalesOrder $order): bool
    {
        return $this->allowed($user, $order, 'sales_orders.view');
    }

    public function create(User $user, Organization $organization, Store $store): bool
    {
        return $this->scope($organization->getKey(), $store->getKey()) && $user->hasPermission($organization, 'sales_orders.create');
    }

    public function update(User $user, SalesOrder $order): bool
    {
        return $this->allowed($user, $order, 'sales_orders.update');
    }

    public function confirm(User $user, SalesOrder $order): bool
    {
        return $this->allowed($user, $order, 'sales_orders.confirm');
    }

    public function cancel(User $user, SalesOrder $order): bool
    {
        return $this->allowed($user, $order, 'sales_orders.cancel');
    }

    public function fulfill(User $user, SalesOrder $order): bool
    {
        return $this->allowed($user, $order, 'sales_orders.fulfill');
    }

    private function allowed(User $user, SalesOrder $order, string $permission): bool
    {
        return $this->scope($order->organization_id, $order->store_id) && $user->hasPermission($order->organization_id, $permission);
    }

    private function scope(int $organizationId, int $storeId): bool
    {
        return $this->context->organization()?->getKey() === $organizationId && $this->context->store()?->getKey() === $storeId;
    }
}
