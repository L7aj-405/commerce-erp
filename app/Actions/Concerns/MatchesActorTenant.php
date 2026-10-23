<?php

namespace App\Actions\Concerns;

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\Mcp\McpExecutionContext;

trait MatchesActorTenant
{
    /**
     * Web requests act inside the browser's active organisation/store. An MCP
     * request has no browser tenant: the authenticated connection fixes the
     * organisation and owning user, and the calling action still proves the
     * store through its store-membership check. MCP scopes are enforced by the
     * calling tool.
     */
    private function actorTenantMatches(User $actor, Organization $organization, Store $store): bool
    {
        $mcp = app(McpExecutionContext::class);
        if ($mcp->isBound()) {
            $connection = $mcp->connectionOrFail();

            return (int) $connection->organization_id === (int) $organization->getKey()
                && (int) $connection->user_id === (int) $actor->getKey();
        }

        return $actor->active_organization_id === $organization->getKey()
            && $actor->active_store_id === $store->getKey();
    }

    /** Organisation-only variant for services that are not store-scoped. */
    private function actorOrganizationMatches(User $actor, Organization $organization): bool
    {
        $mcp = app(McpExecutionContext::class);
        if ($mcp->isBound()) {
            $connection = $mcp->connectionOrFail();

            return (int) $connection->organization_id === (int) $organization->getKey()
                && (int) $connection->user_id === (int) $actor->getKey();
        }

        return $actor->active_organization_id === $organization->getKey();
    }
}
