<?php

namespace App\Actions\Quotations\Concerns;

use App\Models\Organization;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use App\Services\Mcp\McpExecutionContext;

trait AuthorizesQuotationAction
{
    private function authorizeQuotationScope(User $actor, Organization $organization, Store $store, string $permission): void
    {
        abort_unless(
            $this->tenantMatches($actor, $organization, $store)
            && $organization->status === 'active'
            && $store->organization_id === $organization->getKey()
            && $store->status === 'active'
            && $store->memberships()->where('user_id', $actor->getKey())->exists()
            && $actor->hasPermission($organization, $permission),
            403,
        );
    }

    private function authorizeQuotation(User $actor, Quotation $quotation, string $permission): void
    {
        $this->authorizeQuotationScope($actor, $quotation->organization, $quotation->store, $permission);
    }

    /**
     * Web requests act inside the browser's active organisation/store. An MCP
     * request has no browser tenant: the authenticated connection fixes the
     * organisation and owning user, and the store is proven by the membership
     * check above. MCP scopes are enforced by the calling tool.
     */
    private function tenantMatches(User $actor, Organization $organization, Store $store): bool
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
}
