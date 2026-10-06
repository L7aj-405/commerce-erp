<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\UserSocialIdentity;
use App\Services\AuditLogger;
use App\Services\Notifications\OperationalNotificationProducer;
use App\Services\Security\RecoveryCodeService;
use App\Services\Security\TotpService;
use App\Services\Security\TrustedTwoFactorDeviceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function show(Request $request, TrustedTwoFactorDeviceManager $trustedDevices): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/Security', [
            // Surfaces EnsureTwoFactorPolicy's redirect explanation (e.g. "Votre
            // organisation exige la double authentification") — without this the
            // policy's `->with('status', …)` flash was never actually read by
            // anything, since it isn't part of the globally shared `flash` props
            // (see HandleInertiaRequests) — same page-scoped-prop pattern as
            // EmailVerificationPromptController's own `status`.
            'status' => session('status'),
            'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
            'hasPassword' => $user->password !== null,
            'googleAuthConnected' => $user->socialIdentities()
                ->where('provider', UserSocialIdentity::PROVIDER_GOOGLE)
                ->exists(),
            'recoveryCodesRemaining' => $user->hasEnabledTwoFactorAuthentication()
                ? count($user->two_factor_recovery_codes ?? [])
                : 0,
            'trustedDevices' => $user->trustedTwoFactorDevices()
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('last_used_at')
                ->get()
                ->map(fn ($device) => [
                    'id' => $device->getKey(),
                    'deviceName' => $device->device_name,
                    'browser' => $device->browser,
                    'platform' => $device->platform,
                    'lastIp' => $device->last_ip,
                    'lastUsedAt' => $device->last_used_at?->toIso8601String(),
                    'expiresAt' => $device->expires_at->toIso8601String(),
                    'isCurrent' => $trustedDevices->currentDeviceId($request) === $device->getKey(),
                ])
                ->values(),
        ]);
    }

    /** Step 1: generate a fresh (unconfirmed) secret and return its setup key / otpauth URI. */
    public function store(Request $request, TotpService $totp, TrustedTwoFactorDeviceManager $trustedDevices): JsonResponse
    {
        $user = $request->user();
        abort_if($user->hasEnabledTwoFactorAuthentication(), 422, 'La double authentification est déjà activée.');

        // Starting a new enrollment replaces any abandoned/old secret, so no
        // previous trusted-device grant may survive it.
        $trustedDevices->revokeAll($user);

        $secret = $totp->generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => $totp->provisioningUri($secret, $user->email, config('app.name', '10xScale ERP')),
        ])->withCookie($trustedDevices->forgetCookie());
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

        $log = $audit->record('two_factor.enabled', $user, $user->activeOrganization, auditable: $user);
        if ($organization = $user->activeOrganization) {
            app(OperationalNotificationProducer::class)->security($user, $organization, $log->getKey(), 'two_factor.enabled');
        }

        return response()->json(['recovery_codes' => $plainCodes]);
    }

    /** §E8 — the password is part of this exact request, not a stale session timestamp. */
    public function destroy(Request $request, AuditLogger $audit, TrustedTwoFactorDeviceManager $trustedDevices): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $trustedDevices->revokeAll($user);

        $log = $audit->record('two_factor.disabled', $user, $user->activeOrganization, auditable: $user);
        if ($organization = $user->activeOrganization) {
            app(OperationalNotificationProducer::class)->security($user, $organization, $log->getKey(), 'two_factor.disabled');
        }

        return back()
            ->with('success', 'Double authentification désactivée.')
            ->withCookie($trustedDevices->forgetCookie());
    }

    /** §E8 — the password is part of this exact request, not a stale session timestamp. */
    public function regenerateRecoveryCodes(Request $request, RecoveryCodeService $recovery, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasEnabledTwoFactorAuthentication(), 422, 'La double authentification n’est pas activée.');

        $plainCodes = $recovery->generate();
        $user->forceFill(['two_factor_recovery_codes' => $recovery->hash($plainCodes)])->save();

        $log = $audit->record('two_factor.recovery_codes_regenerated', $user, $user->activeOrganization, auditable: $user);
        if ($organization = $user->activeOrganization) {
            app(OperationalNotificationProducer::class)->security($user, $organization, $log->getKey(), 'two_factor.recovery_codes_regenerated');
        }

        return response()->json(['recovery_codes' => $plainCodes]);
    }
}
