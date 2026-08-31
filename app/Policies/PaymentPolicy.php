<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\ActiveTenantContext;

class PaymentPolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization, Store $store): bool
    {
        return $this->scope($organization->getKey(), $store->getKey()) && $user->hasPermission($organization, 'payments.view');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->scope($payment->organization_id, $payment->store_id) && $user->hasPermission($payment->organization_id, 'payments.view');
    }

    public function create(User $user, SalesOrder $order): bool
    {
        return $this->scope($order->organization_id, $order->store_id) && $user->hasPermission($order->organization_id, 'payments.create');
    }

    public function reverse(User $user, Payment $payment): bool
    {
        return $this->scope($payment->organization_id, $payment->store_id) && $user->hasPermission($payment->organization_id, 'payments.reverse');
    }

    private function scope(int $organizationId, int $storeId): bool
    {
        return $this->context->organization()?->getKey() === $organizationId && $this->context->store()?->getKey() === $storeId;
    }
}
