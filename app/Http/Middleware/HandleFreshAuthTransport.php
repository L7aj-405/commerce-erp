<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Converts a successful redirect from a protected non-Inertia mutation into a
 * small transport response. The client then performs a normal Inertia GET, so
 * page props, flash data and history continue to use the public Inertia API.
 */
final class HandleFreshAuthTransport
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->header('X-Fresh-Auth-Transport') !== '1' || ! $response instanceof RedirectResponse) {
            return $response;
        }

        return new JsonResponse([
            'redirect_to' => $response->getTargetUrl(),
        ]);
    }
}
