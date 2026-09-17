<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Security\RecoveryCodeService;
use App\Services\Security\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TOTP enrollment lives entirely under Account Settings > Security (§E1).
 * The secret is written to the user row as soon as it's generated but
 * `hasEnabledTwoFactorAuthentication()` requires BOTH the secret and
 * `two_factor_confirmed_at` — an unconfirmed secret grants no access, so
 * abandoning enrollment mid-flow is always safe.
 */
class TwoFactorAuthenticationController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        $currentSessionId = $request->session()->getId();

        return Inertia::render('Settings/Security', [
            'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
            'recoveryCodesRemaining' => $user->hasEnabledTwoFactorAuthentication()
                ? count($user->two_factor_recovery_codes ?? [])
                : 0,
            // §8 — active sessions (database session driver only, see
            // ActiveSessionController's class doc for why the raw id is
            // never sent to the client).
            'sessions' => DB::table('sessions')
                ->where('user_id', $user->getKey())
                ->orderByDesc('last_activity')
                ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
                ->map(fn ($session) => [
                    'token' => ActiveSessionController::opaqueToken($session->id),
                    'isCurrent' => hash_equals($session->id, $currentSessionId),
                    'ipAddress' => $session->ip_address,
                    'userAgent' => $session->user_agent,
                    'lastActiveAt' => Carbon::createFromTimestamp($session->last_activity)->toIso8601String(),
                ])
                ->values(),
        ]);
    }

    /** Step 1: generate a fresh (unconfirmed) secret and return its setup key / otpauth URI. */
    public function store(Request $request, TotpService $totp): JsonResponse
    {
        $user = $request->user();
        abort_if($user->hasEnabledTwoFactorAuthentication(), 422, 'La double authentification est déjà activée.');

        $secret = $totp->generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => $totp->provisioningUri($secret, $user->email, config('app.name', 'Commerce ERP')),
        ]);
    }

    /** Step 2: the user proves they scanned/typed the secret correctly before it counts as enabled. */
    public function confirm(Request $request, TotpService $totp, RecoveryCodeService $recovery, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_if($user->hasEnabledTwoFactorAuthentication(), 422, 'La double authentification est déjà activée.');
        abort_if($user->two_factor_secret === null, 422, 'Aucune configuration en cours.');

        $data = $request->validate(['code' => ['required', 'string']]);

        if (! $totp->verify($user->two_factor_secret, $data['code'], 'enrollment')) {
            throw ValidationException::withMessages(['code' => 'Code invalide.']);
        }

        $plainCodes = $recovery->generate();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recovery->hash($plainCodes),
        ])->save();

        $audit->record('two_factor.enabled', $user, $user->activeOrganization, auditable: $user);

        return response()->json(['recovery_codes' => $plainCodes]);
    }

    /** §E8 — the password is part of this exact request, not a stale session timestamp. */
    public function destroy(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->confirmPassword($request);

        $user = $request->user();
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $audit->record('two_factor.disabled', $user, $user->activeOrganization, auditable: $user);

        return back()->with('success', 'Double authentification désactivée.');
    }

    /** §E8 — the password is part of this exact request, not a stale session timestamp. */
    public function regenerateRecoveryCodes(Request $request, RecoveryCodeService $recovery, AuditLogger $audit): JsonResponse
    {
        $this->confirmPassword($request);

        $user = $request->user();
        abort_unless($user->hasEnabledTwoFactorAuthentication(), 422, 'La double authentification n’est pas activée.');

        $plainCodes = $recovery->generate();
        $user->forceFill(['two_factor_recovery_codes' => $recovery->hash($plainCodes)])->save();

        $audit->record('two_factor.recovery_codes_regenerated', $user, $user->activeOrganization, auditable: $user);

        return response()->json(['recovery_codes' => $plainCodes]);
    }

    private function confirmPassword(Request $request): void
    {
        $data = $request->validate(['password' => ['required', 'string']]);

        if (! Auth::guard('web')->validate(['email' => $request->user()->email, 'password' => $data['password']])) {
            throw ValidationException::withMessages(['password' => 'Mot de passe incorrect.']);
        }
    }
}
