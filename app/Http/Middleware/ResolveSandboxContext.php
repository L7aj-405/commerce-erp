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
        Inertia::share('sandbox', fn () => ['active' => $context->isSandbox(), 'restricted' => $context->isRestricted(), 'id' => $context->id()]);
        // Explicit route allowlist: expand only after each domain implements real isolation.
        $allowed = ['sandbox.show', 'sandbox.leave', 'logout'];
        if ($context->isSandbox()) {
            $allowed = [...$allowed, ...\App\Services\Sandbox\SandboxSalesRoutes::ALLOWED, ...\App\Services\Sandbox\SandboxDocumentRules::ROUTES, ...\App\Services\Sandbox\SandboxInventoryGuard::ROUTES];
        }
        if ($context->isRestricted() && !in_array($request->route()?->getName(), $allowed, true)) {
            if ($request->isMethod('GET') && !$request->expectsJson()) return redirect('/test-mode');
            return response()->json(['code' => 'SANDBOX_DOMAIN_UNAVAILABLE', 'message' => 'Les opérations métier ne sont pas encore disponibles.'], 403);
        }
        return $next($request);
    }
}
