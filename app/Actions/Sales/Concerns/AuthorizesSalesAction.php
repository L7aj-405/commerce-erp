<?php

namespace App\Actions\Sales\Concerns;

use App\Actions\Concerns\MatchesActorTenant;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Sandbox\SandboxContext;
use App\Services\Sandbox\SandboxFulfillmentRules;
use App\Services\Sandbox\SandboxProcurementRules;

trait AuthorizesSalesAction
{
    use MatchesActorTenant;

    private function authorizeSales(User $actor, Organization $organization, Store $store, string $permission): void
    {
        abort_unless(
            $this->actorTenantMatches($actor, $organization, $store)
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
        abort_unless((string) $order->sandbox_id === (string) app(SandboxContext::class)->id(), 404);
        if ($order->sandbox_id !== null) {
            abort_unless(in_array($permission, [...['sales_orders.view', 'sales_orders.update', 'sales_orders.confirm', 'sales_orders.cancel'], ...SandboxProcurementRules::PERMISSIONS, ...SandboxFulfillmentRules::PERMISSIONS], true), 403, 'TEST: transition indisponible.');
        }
        $this->authorizeSales($actor, $order->organization, $order->store, $permission);
    }
}
