<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Security\RecoveryCodeService;
use App\Services\Security\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The second step of login for a 2FA-enabled account (§E4). Reached only via
 * AuthenticatedSessionController::store(), which validates the password and
 * — for a 2FA account — stops there: it stores the pending user id in the
 * session WITHOUT calling Auth::login(), so no authenticated session exists
 * until this controller's `store()` succeeds. There is no route that skips
 * straight to an authenticated area from here; every protected route still
 * requires Auth::check(), which stays false until the challenge passes.
 */
class TwoFactorChallengeController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const COOLDOWN_SECONDS = 300;

    public function create(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('login.2fa.user_id')) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(Request $request, TotpService $totp, RecoveryCodeService $recovery, AuditLogger $audit): RedirectResponse
    {
        $userId = $request->session()->get('login.2fa.user_id');
        if (! $userId) {
            return redirect()->route('login');
        }

        $throttleKey = 'two-factor-challenge:'.$userId.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'code' => 'Trop de tentatives. Réessayez dans '.RateLimiter::availableIn($throttleKey).' secondes.',
            ]);
        }

        $user = User::query()->find($userId);
        if (! $user || ! $user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->forget(['login.2fa.user_id', 'login.2fa.remember']);

            return redirect()->route('login');
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);

        $verified = $totp->verify($user->two_factor_secret, $data['code'], 'login');
        $usedRecovery = false;

        if (! $verified && $user->two_factor_recovery_codes) {
            $remaining = $recovery->consume($user->two_factor_recovery_codes, $data['code']);
            if ($remaining !== null) {
                $user->forceFill(['two_factor_recovery_codes' => $remaining])->save();
                $verified = true;
                $usedRecovery = true;
            }
        }

        if (! $verified) {
            RateLimiter::hit($throttleKey, self::COOLDOWN_SECONDS);
            throw ValidationException::withMessages(['code' => 'Code invalide.']);
        }

        RateLimiter::clear($throttleKey);
        $remember = (bool) $request->session()->pull('login.2fa.remember', false);
        $request->session()->forget('login.2fa.user_id');

        Auth::login($user, $remember);
        $request->session()->regenerate();

        if ($usedRecovery) {
            $audit->record('two_factor.recovery_code_used', $user, $user->activeOrganization, auditable: $user);
        }

        return redirect()->intended(route('platform.index'));
    }
}
