<?php

namespace App\Services\Mcp;

use App\Models\McpConnection;
use App\Models\OrganizationMembership;

/**
 * Re-establishes MCP tenant context outside the HTTP request (queue workers).
 * Applies the same connection/organization/membership checks as the
 * AuthenticateMcpConnection middleware so revocation, expiry, policy changes,
 * or membership removal take effect on the next queued chunk.
 */
final class McpConnectionRuntime
{
    public function __construct(private readonly McpExecutionContext $context) {}

    /**
     * Binds the connection and returns null, or returns a sanitized failure
     * code without binding.
     */
    public function bind(?string $connectionId): ?string
    {
        $this->context->reset();
        $connection = $connectionId === null ? null
            : McpConnection::query()->with(['organization', 'user', 'scopeRecords'])->find($connectionId);

        $failure = match (true) {
            ! $connection || ! $connection->user => 'connection_missing',
            ! $connection->isUsable() => 'connection_inactive',
            $connection->organization->status !== 'active' => 'organization_inactive',
            ! $connection->organization->allowsMcpConnections() => 'mcp_disabled',
            ! OrganizationMembership::query()->where('organization_id', $connection->organization_id)
                ->where('user_id', $connection->user_id)->where('status', 'active')->exists() => 'membership_inactive',
            default => null,
        };

        if ($failure === null) {
            $this->context->bind($connection);
        }

        return $failure;
    }

    /**
     * Binds the connection for audit attribution only (e.g. recording that an
     * operation finished after the connection was revoked). Callers must not
     * authorize or mutate anything under this binding.
     */
    public function attribute(?string $connectionId): void
    {
        $this->context->reset();
        $connection = $connectionId === null ? null
            : McpConnection::query()->with(['organization', 'user'])->find($connectionId);
        if ($connection) {
            $this->context->bind($connection);
        }
    }

    public function release(): void
    {
        $this->context->reset();
    }
}
