<?php

namespace App\Http\Middleware;

use App\Models\McpConnection;
use App\Services\Mcp\McpConnectionGate;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\OAuth\McpOAuthMetadata;
use App\Services\Mcp\OAuth\McpOAuthServer;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the /mcp resource. Two credential forms resolve to the same
 * McpConnection and therefore the same tenant, user, scopes and checks:
 *  - opaque connection secrets `10xmcp_<id>_<hex>` (unchanged), and
 *  - OAuth 2.1 access tokens `10xoat_<hex>` bound to an OAuth connection.
 * 401 responses advertise the RFC 9728 protected resource metadata.
 */
final class AuthenticateMcpConnection
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly McpConnectionGate $gate,
        private readonly McpOAuthServer $oauth,
        private readonly McpOAuthMetadata $metadata,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // CORS preflight carries no credentials by definition.
        if ($request->isMethod('OPTIONS')) {
            return $this->cors($request, new Response('', 204, [
                'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID',
                'Access-Control-Max-Age' => '600',
            ]));
        }

        $token = $request->bearerToken();
        if (! is_string($token) || $token === '') {
            return $this->unauthorized($request, 'MCP_AUTHENTICATION_REQUIRED', 'A valid MCP bearer credential is required.', null);
        }

        $oauthToken = null;
        if (preg_match('/^10xmcp_([0-9A-HJKMNP-TV-Z]{26})_[a-f0-9]{64}$/i', $token, $matches)) {
            $connection = McpConnection::query()->with(['organization', 'user', 'scopeRecords'])->find($matches[1]);
            // OAuth-created connections are reachable through OAuth tokens only.
            if (! $connection || $connection->isOAuth() || ! hash_equals($connection->token_hash, hash('sha256', $token))) {
                return $this->unauthorized($request, 'MCP_INVALID_CREDENTIAL', 'The MCP credential is invalid.', 'invalid_token');
            }
        } elseif (str_starts_with($token, '10xoat_') && config('mcp.oauth.enabled', true)) {
            $oauthToken = $this->oauth->resolveAccessToken($token);
            $connection = $oauthToken
                ? McpConnection::query()->with(['organization', 'user', 'scopeRecords'])->find($oauthToken->mcp_connection_id)
                : null;
            if (! $connection || ! $connection->isOAuth() || $connection->oauth_client_id !== $oauthToken->client_id) {
                return $this->unauthorized($request, 'MCP_INVALID_CREDENTIAL', 'The access token is invalid or expired.', 'invalid_token');
            }
        } else {
            // Same code as before MCP-6 for malformed credentials.
            return $this->unauthorized($request, 'MCP_AUTHENTICATION_REQUIRED', 'A valid MCP bearer credential is required.', 'invalid_token');
        }

        $failure = $this->gate->failure($connection);
        if ($failure === 'revoked') return $this->unauthorized($request, 'MCP_CONNECTION_REVOKED', 'The MCP connection has been revoked.', 'invalid_token');
        if ($failure === 'expired') return $this->unauthorized($request, 'MCP_CONNECTION_EXPIRED', 'The MCP connection has expired.', 'invalid_token');
        if ($failure !== null) {
            [$code, $message] = match ($failure) {
                'organization_inactive' => ['MCP_ORGANIZATION_INACTIVE', 'The organization is not active.'],
                'mcp_disabled' => ['MCP_DISABLED', 'AI/MCP connections are disabled for this organization.'],
                default => ['MCP_MEMBERSHIP_INACTIVE', 'The owning user no longer has active organization access.'],
            };

            return $this->error($request, $code, $message, 403);
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

    private function unauthorized(Request $request, string $code, string $message, ?string $oauthError): JsonResponse
    {
        $challenge = 'Bearer realm="10xScale MCP", resource_metadata="'.$this->metadata->resourceMetadataUrl().'"';
        if ($oauthError !== null) $challenge .= ', error="'.$oauthError.'"';

        return $this->error($request, $code, $message, 401, ['WWW-Authenticate' => $challenge]);
    }

    /** @param array<string, string> $headers */
    private function error(Request $request, string $code, string $message, int $status, array $headers = []): JsonResponse
    {
        return $this->cors($request, response()->json(['error' => ['code' => $code, 'message' => $message]], $status, $headers));
    }

    /** Browser-based clients need CORS on errors too, to read WWW-Authenticate. */
    private function cors(Request $request, Response $response): Response
    {
        $origin = $request->headers->get('Origin');
        if (is_string($origin) && in_array($origin, config('mcp.allowed_origins', []), true)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Expose-Headers', 'WWW-Authenticate, Mcp-Session-Id');
            $response->headers->set('Vary', 'Origin');
        }

        return $response;
    }
}
