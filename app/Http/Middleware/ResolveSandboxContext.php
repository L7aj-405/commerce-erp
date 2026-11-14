<?php
namespace App\Http\Middleware;
use App\Services\Sandbox\SandboxContext;
use App\Services\ActiveTenantContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
class ResolveSandboxContext
{
    public function handle(Request $request, Closure $next)
    {
        $context = app(SandboxContext::class);
        $context->resolve($request, app(ActiveTenantContext::class));
        // Phase 4: one-time Test stock snapshot for sandboxes that predate it (no-op afterwards).
        if ($context->sandbox()) {
            app(\App\Services\Sandbox\SandboxInventoryInitializer::class)->ensure($context->sandbox());
        }
        Inertia::share('sandbox', fn () => ['active' => $context->isSandbox(), 'restricted' => $context->isRestricted(), 'id' => $context->id(), 'stale' => $context->isStale()]);
        // Explicit route allowlist: expand only after each domain implements real isolation.
        // `sandbox.enter` lets a restricted (e.g. reset / stale) session explicitly re-enter.
        // `notifications.feed` is the bell's read-only poll: in Test Mode NotificationFeed
        // answers an empty Test summary, so it can never expose the Production inbox.
        $allowed = ['sandbox.show', 'sandbox.leave', 'sandbox.enter', 'logout', 'sandbox.reset.show', 'sandbox.reset.execute', 'notifications.feed'];
        // Phase 10: a session left on an old generation by a reset gets a controlled answer.
        if ($context->isStale() && !in_array($request->route()?->getName(), $allowed, true)) {
            $selection = $context->selection() ?? [];
            $current = \App\Models\Sandbox::query()->whereKey($selection['id'] ?? null)->value('generation');
            return \App\Services\Sandbox\SandboxStaleSession::respond($request, isset($selection['id']) ? (int) $selection['id'] : null,
                isset($selection['generation']) ? (int) $selection['generation'] : null, $current === null ? null : (int) $current, 'request');
        }
        if ($context->isSandbox()) {
            $allowed = [...$allowed, ...\App\Services\Sandbox\SandboxSalesRoutes::ALLOWED, ...\App\Services\Sandbox\SandboxDocumentRules::ROUTES, ...\App\Services\Sandbox\SandboxInventoryGuard::ROUTES, ...\App\Services\Sandbox\SandboxQuotationRules::ROUTES, ...\App\Services\Sandbox\SandboxProcurementRules::ROUTES, ...\App\Services\Sandbox\SandboxFulfillmentRules::ROUTES, ...\App\Services\Sandbox\SandboxReturnsRules::ROUTES];
        }
        if ($context->isRestricted() && !in_array($request->route()?->getName(), $allowed, true)) {
            if ($request->isMethod('GET') && !$request->expectsJson()) return redirect('/test-mode');
            return response()->json(['code' => 'SANDBOX_DOMAIN_UNAVAILABLE', 'message' => 'Ce module n’est pas disponible en mode test.'], 403);
        }
        return $next($request);
    }
}
