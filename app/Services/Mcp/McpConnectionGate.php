<?php

namespace App\Services\Mcp;

use App\Models\McpConnection;
use App\Models\OrganizationMembership;

/**
 * Live usability of an MCP connection: status, expiry, organization state and
 * policy, and the owner's active membership — evaluated on every use so a
 * revocation or membership removal takes effect immediately.
 */
final class McpConnectionGate
{
    /** @return 'revoked'|'expired'|'organization_inactive'|'mcp_disabled'|'membership_inactive'|null */
    public function failure(McpConnection $connection): ?string
    {
        return match (true) {
            $connection->status === McpConnection::STATUS_REVOKED || $connection->revoked_at !== null => 'revoked',
            $connection->expires_at !== null && $connection->expires_at->isPast() => 'expired',
            $connection->organization?->status !== 'active' => 'organization_inactive',
            ! $connection->organization->allowsMcpConnections() => 'mcp_disabled',
            ! OrganizationMembership::query()->where('organization_id', $connection->organization_id)
                ->where('user_id', $connection->user_id)->where('status', 'active')->exists() => 'membership_inactive',
            default => null,
        };
    }
}
