<?php

namespace App\Actions\Payments\Concerns;

use App\Actions\Concerns\MatchesActorTenant;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Sandbox\SandboxContext;

trait AuthorizesPaymentAction
{
    use MatchesActorTenant;

    private function authorizePayment(User $actor, SalesOrder $order, string $permission): void
    {
        // Phase 5: payments follow their order's environment. A Test order only
        // takes simulated Test payments (Test ledger) from a Test session, and a
        // Production order is unreachable from Test Mode (and vice versa).
        abort_unless((string) $order->sandbox_id === (string) app(SandboxContext::class)->id(), 404);
        if ($order->sandbox_id !== null) {
            abort_unless(in_array($permission, ['payments.view', 'payments.create', 'payments.reverse'], true), 403, 'TEST: transition indisponible.');
        }
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
