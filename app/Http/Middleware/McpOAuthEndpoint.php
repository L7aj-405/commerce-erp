<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stateless OAuth/discovery endpoints (metadata, register, token, revoke):
 *  - TLS only in production (behind the trusted proxy)
 *  - CORS for browser-based MCP clients; no cookies are ever used here, so a
 *    wildcard origin without credentials is safe
 *  - never cached unless the controller says otherwise
 */
final class McpOAuthEndpoint
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('mcp.oauth.enabled', true)) {
            return response()->json(['error' => 'not_found', 'error_description' => 'OAuth is disabled.'], 404);
        }
        if (app()->isProduction() && ! $request->secure()) {
            return $this->cors(response()->json(['error' => 'invalid_request', 'error_description' => 'TLS is required.'], 400));
        }
        if ($request->isMethod('OPTIONS')) {
            return $this->cors(new Response('', 204, [
                'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept, Mcp-Protocol-Version',
                'Access-Control-Max-Age' => '600',
            ]));
        }

        $response = $next($request);
        if (! $response->headers->has('Cache-Control') || $request->isMethod('POST')) {
            $response->headers->set('Cache-Control', 'no-store');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $this->cors($response);
    }

    private function cors(Response $response): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Expose-Headers', 'WWW-Authenticate');

        return $response;
    }
}
