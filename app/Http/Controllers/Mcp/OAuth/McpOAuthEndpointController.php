<?php

namespace App\Http\Controllers\Mcp\OAuth;

use App\Http\Controllers\Controller;
use App\Services\Mcp\OAuth\McpOAuthException;
use App\Services\Mcp\OAuth\McpOAuthMetadata;
use App\Services\Mcp\OAuth\McpOAuthServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stateless OAuth 2.1 endpoints for remote MCP clients. Responses follow the
 * RFC error format with fixed, sanitized descriptions; request bodies and
 * credentials are never logged.
 */
final class McpOAuthEndpointController extends Controller
{
    /** Parameters that must never travel in a URL (RFC 6749 §2.3.1, OAuth 2.1 §3.2). */
    private const CREDENTIAL_PARAMS = ['client_secret', 'code', 'code_verifier', 'refresh_token', 'token'];

    public function __construct(
        private readonly McpOAuthMetadata $metadata,
        private readonly McpOAuthServer $server,
    ) {}

    /** RFC 9728 — served at /.well-known/oauth-protected-resource[/mcp]. */
    public function protectedResource(): JsonResponse
    {
        return response()->json($this->metadata->protectedResource())->header('Cache-Control', 'public, max-age=300');
    }

    /** RFC 8414 — served at /.well-known/oauth-authorization-server. */
    public function authorizationServer(): JsonResponse
    {
        return response()->json($this->metadata->authorizationServer())->header('Cache-Control', 'public, max-age=300');
    }

    /** RFC 7591 dynamic client registration (public clients by default). */
    public function register(Request $request): JsonResponse
    {
        return $this->handle($request, function () use ($request) {
            if (! config('mcp.oauth.dynamic_client_registration', true)) {
                throw new McpOAuthException('access_denied', 'Dynamic client registration is disabled.', 403);
            }

            return response()->json($this->server->registerClient($request->json()->all() ?: $request->all()), 201);
        });
    }

    public function token(Request $request): JsonResponse
    {
        return $this->handle($request, function () use ($request) {
            $client = $this->server->authenticateClient($request);

            return response()->json(match ($request->input('grant_type')) {
                'authorization_code' => $this->server->exchangeAuthorizationCode($client, $request),
                'refresh_token' => $this->server->refresh($client, $request),
                default => throw new McpOAuthException('unsupported_grant_type', 'Supported grant types: authorization_code, refresh_token.'),
            });
        });
    }

    /** RFC 7009 — 200 for any token, known or not, once the client authenticates. */
    public function revoke(Request $request): JsonResponse
    {
        return $this->handle($request, function () use ($request) {
            $client = $this->server->authenticateClient($request);
            $token = $request->input('token');
            if (! is_string($token) || $token === '') {
                throw new McpOAuthException('invalid_request', 'token is required.');
            }
            $this->server->revoke($client, $token);

            return response()->json([], 200);
        });
    }

    /** @param callable(): JsonResponse $callback */
    private function handle(Request $request, callable $callback): JsonResponse
    {
        try {
            foreach (self::CREDENTIAL_PARAMS as $param) {
                if ($request->query->has($param)) {
                    throw new McpOAuthException('invalid_request', 'Credentials must be sent in the request body, never in the URL.');
                }
            }

            return $callback();
        } catch (McpOAuthException $exception) {
            $response = response()->json($exception->payload(), $exception->status);
            if ($exception->error === 'invalid_client' && str_starts_with((string) $request->header('Authorization', ''), 'Basic ')) {
                $response->header('WWW-Authenticate', 'Basic realm="10xScale MCP OAuth"');
            }

            return $response;
        } catch (Throwable $exception) {
            Log::warning('MCP OAuth endpoint failed.', ['route' => $request->route()?->getName(), 'exception_type' => $exception::class]);

            return response()->json(['error' => 'server_error', 'error_description' => 'The authorization server could not complete the request.'], 500);
        }
    }
}
