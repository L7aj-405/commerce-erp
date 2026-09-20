<?php

namespace App\Policies;

use App\Models\CustomerExchange;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\ActiveTenantContext;

class CustomerExchangePolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function create(User $user, SalesOrder $order): bool
    {
        return $this->scope($order->organization_id, $order->store_id)
            && $user->hasPermission($order->organization_id, 'sales_exchanges.create')
            && $user->hasPermission($order->organization_id, 'sales_returns.create');
    }

    public function view(User $user, CustomerExchange $exchange): bool
    {
        return $this->scope($exchange->organization_id, $exchange->store_id)
            && $user->hasPermission($exchange->organization_id, 'sales_exchanges.view');
    }

    public function process(User $user, CustomerExchange $exchange): bool
    {
        return $this->scope($exchange->organization_id, $exchange->store_id)
            && $user->hasPermission($exchange->organization_id, 'sales_exchanges.process');
    }

    private function scope(int $organizationId, int $storeId): bool
    {
        return $this->context->organization()?->id === $organizationId && $this->context->store()?->id === $storeId;
    }
}
