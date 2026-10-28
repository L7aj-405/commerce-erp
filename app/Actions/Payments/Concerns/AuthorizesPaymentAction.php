<?php

namespace App\Actions\Payments\Concerns;

use App\Actions\Concerns\MatchesActorTenant;
use App\Models\SalesOrder;
use App\Models\User;

trait AuthorizesPaymentAction
{
    use MatchesActorTenant;

    private function authorizePayment(User $actor, SalesOrder $order, string $permission): void
    {
        // Web: the browser's active organization + store. MCP: the connection's organization and user;
        // store membership below still applies to both.
        abort_unless(
            $this->actorTenantMatches($actor, $order->organization, $order->store)
            && $order->organization->status === 'active'
            && $order->store->status === 'active'
            && $order->store->memberships()->where('user_id', $actor->getKey())->exists()
            && $actor->hasPermission($order->organization_id, $permission),
            403,
        );
    }
}
