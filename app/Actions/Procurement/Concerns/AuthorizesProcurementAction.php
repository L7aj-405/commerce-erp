<?php

namespace App\Actions\Procurement\Concerns;

use App\Actions\Concerns\MatchesActorTenant;
use App\Models\Organization;
use App\Models\User;
use App\Services\Sandbox\SandboxContext;

trait AuthorizesProcurementAction
{
    use MatchesActorTenant;

    private function authorizeProcurement(User $actor, Organization $organization, string $permission): void
    {
        // Web: the browser's active organization. MCP: the connection's organization and user.
        abort_unless(
            $this->actorOrganizationMatches($actor, $organization)
            && $organization->status === 'active'
            && $actor->organizationMemberships()->where('organization_id', $organization->getKey())->where('status', 'active')->exists()
            && $actor->hasPermission($organization, $permission),
            403
        );
    }

    /**
     * Phase 6: a procurement record (or its sales order) must belong to the
     * request's environment. Routed procurement models answer isSandboxInventory();
     * sales orders / quotations carry sandbox_id. A record of the other
     * environment is refused (404), never acted upon.
     */
    private function assertSameEnvironment(object ...$records): void
    {
        $context = app(SandboxContext::class);
        foreach ($records as $record) {
            $same = method_exists($record, 'isSandboxInventory')
                ? $record->isSandboxInventory() === $context->isSandbox()
                : (string) ($record->sandbox_id ?? '') === (string) $context->id();
            abort_unless($same, 404);
        }
    }
}
