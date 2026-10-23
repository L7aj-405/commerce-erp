<?php

namespace App\Http\Middleware;

use App\Models\McpConnection;
use App\Models\OrganizationMembership;
use App\Services\Mcp\McpExecutionContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateMcpConnection
{
    public function __construct(private readonly McpExecutionContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! is_string($token) || ! preg_match('/^10xmcp_([0-9A-HJKMNP-TV-Z]{26})_[a-f0-9]{64}$/i', $token, $matches)) {
            return $this->error('MCP_AUTHENTICATION_REQUIRED', 'A valid MCP bearer credential is required.', 401);
        }

        $connection = McpConnection::query()->with(['organization', 'user', 'scopeRecords'])->find($matches[1]);
        if (! $connection || ! hash_equals($connection->token_hash, hash('sha256', $token))) {
            return $this->error('MCP_INVALID_CREDENTIAL', 'The MCP credential is invalid.', 401);
        }
        if ($connection->status === McpConnection::STATUS_REVOKED || $connection->revoked_at !== null) {
            return $this->error('MCP_CONNECTION_REVOKED', 'The MCP connection has been revoked.', 401);
        }
        if ($connection->expires_at !== null && $connection->expires_at->isPast()) {
            return $this->error('MCP_CONNECTION_EXPIRED', 'The MCP connection has expired.', 401);
        }
        if ($connection->organization->status !== 'active') {
            return $this->error('MCP_ORGANIZATION_INACTIVE', 'The organization is not active.', 403);
        }
        if (! $connection->organization->allowsMcpConnections()) {
            return $this->error('MCP_DISABLED', 'AI/MCP connections are disabled for this organization.', 403);
        }
        if (! OrganizationMembership::query()->where('organization_id', $connection->organization_id)->where('user_id', $connection->user_id)->where('status', 'active')->exists()) {
            return $this->error('MCP_MEMBERSHIP_INACTIVE', 'The owning user no longer has active organization access.', 403);
        }

        // Scoped services are reset between production requests. Explicitly
        // clear as well so long-running workers and the in-process HTTP test
        // kernel can never retain a previous request's tenant identity.
        $this->context->reset();
        $this->context->bind($connection);
        $connection->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('mcp_connection_id', $connection->getKey());

        return $next($request);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status, [
            'WWW-Authenticate' => 'Bearer realm="10xScale MCP"',
        ]);
    }
}
