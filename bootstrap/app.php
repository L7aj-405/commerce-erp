<?php

use App\Http\Middleware\EnsureTwoFactorPolicy;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HandleFreshAuthTransport;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\RequireFreshAuthentication;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\AuthenticateMcpConnection;
use App\Support\TrustedProxyRanges;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware(['mcp.authenticate', 'throttle:mcp'])
                ->group(base_path('routes/mcp.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sprint 1.1 §2 — see App\Support\TrustedProxyRanges for the full
        // rationale. Trusting '*' let any REMOTE_ADDR act as a proxy, which
        // would let a request that ever reaches this app directly (bypassing
        // Coolify's Traefik) forge X-Forwarded-For and influence
        // $request->ip() — which LoginThrottle keys throttling on.
        $middleware->trustProxies(at: TrustedProxyRanges::resolve());
        $middleware->web(append: [
            ResolveTenantContext::class,
            HandleFreshAuthTransport::class,
            HandleInertiaRequests::class,
            SecurityHeaders::class,
        ]);
        $middleware->alias([
            'two-factor.policy' => EnsureTwoFactorPolicy::class,
            'fresh-auth' => RequireFreshAuthentication::class,
            'mcp.authenticate' => AuthenticateMcpConnection::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
