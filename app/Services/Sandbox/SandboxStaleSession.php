<?php

namespace App\Services\Sandbox;

use App\Models\Sandbox;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Phase 10 — one controlled answer for a Test session whose sandbox was reset
 * (or a Test write refused by SandboxGenerationFence): never a 500, never a
 * silent switch to the new generation. Pages go to the Test Mode home, which
 * explains the reset and offers « Entrer à nouveau en Mode Test »; JSON
 * clients get 409 SANDBOX_STALE_GENERATION.
 */
final class SandboxStaleSession
{
    public const CODE = 'SANDBOX_STALE_GENERATION';

    private const AUDITED = 'sandbox_stale_audited';

    /** @param 'request'|'write' $stage */
    public static function respond(Request $request, ?int $sandboxId, ?int $sessionGeneration, ?int $currentGeneration, string $stage): Response
    {
        self::audit($request, $sandboxId, $sessionGeneration, $currentGeneration, $stage);
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['code' => self::CODE, 'message' => SandboxStaleGenerationException::MESSAGE], 409);
        }

        return redirect('/test-mode', 303)->with('sandbox_stale', SandboxStaleGenerationException::MESSAGE);
    }

    /**
     * Phase 10.1: a Test request whose sandbox was reset while it ran may first hit a
     * record the reset deleted (404) instead of a fenced write. When the generation
     * moved, answer with the stale-generation response instead of a bare 404.
     */
    public static function forMissingRecord(Request $request): ?Response
    {
        if (! SandboxGenerationFence::active()) {
            return null;
        }
        $context = app(SandboxContext::class);
        $row = Sandbox::query()->whereKey($context->id())->first(['generation', 'status']);
        if ($row !== null && (int) $row->generation === (int) $context->generation() && $row->status === 'active') {
            return null;
        }

        return self::respond($request, $context->id(), $context->generation(), $row === null ? null : (int) $row->generation, 'write');
    }

    /** Audited once per session and stale generation for page navigation; every refused write is audited. */
    private static function audit(Request $request, ?int $sandboxId, ?int $sessionGeneration, ?int $currentGeneration, string $stage): void
    {
        $key = $sandboxId.':'.$sessionGeneration;
        $session = $request->hasSession() ? $request->session() : null;
        if ($stage === 'request' && $session?->get(self::AUDITED) === $key) {
            return;
        }
        try {
            $organization = app(ActiveTenantContext::class)->organization();
            app(AuditLogger::class)->record('sandbox.stale_rejected', $request->user(), $organization,
                auditable: $sandboxId ? Sandbox::query()->find($sandboxId) : null,
                newValues: ['sandbox_id' => $sandboxId, 'session_generation' => $sessionGeneration,
                    'current_generation' => $currentGeneration, 'stage' => $stage, 'route' => $request->route()?->getName()]);
            $session?->put(self::AUDITED, $key);
        } catch (Throwable) {
            // Auditing must never turn the controlled response into a 500.
        }
    }
}
