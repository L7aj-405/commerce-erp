<?php

namespace App\Actions\Payments\Concerns;

use App\Models\SalesOrder;
use App\Models\User;

trait AuthorizesPaymentAction
{
    private function authorizePayment(User $actor, SalesOrder $order, string $permission): void
    {
        abort_unless(
            $actor->active_organization_id === $order->organization_id
            && $actor->active_store_id === $order->store_id
            && $order->organization->status === 'active'
            && $order->store->status === 'active'
            && $order->store->memberships()->where('user_id', $actor->getKey())->exists()
            && $actor->hasPermission($order->organization_id, $permission),
            403,
        );
    }
}
