<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\UserSocialIdentity;
use App\Services\AuditLogger;
use App\Services\Auth\GoogleAuthClient;
use App\Services\Security\FreshAuthentication;
use App\Services\Security\RecoveryCodeService;
use App\Services\Security\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class FreshAuthenticationController extends Controller
{
    public function show(Request $request, FreshAuthentication $fresh): Response|RedirectResponse
    {
        $level = $fresh->requiredLevel($request);
        if ($fresh->isSatisfied($request, $level)) {
            return redirect($fresh->complete($request));
        }

        $user = $request->user();

        return Inertia::render('Auth/SecurityConfirmation', [
            'hasPassword' => $user->password !== null,
            'googleConnected' => $user->socialIdentities()
                ->where('provider', UserSocialIdentity::PROVIDER_GOOGLE)
                ->exists(),
            'accountConfirmed' => $fresh->accountIsFresh($request),
            'requiresTwoFactor' => $level >= FreshAuthentication::LEVEL_TWO_FACTOR
                && $user->hasEnabledTwoFactorAuthentication(),
            'timeoutMinutes' => $fresh->timeoutMinutes(),
            'status' => session('status'),
        ]);
    }

    public function password(
        Request $request,
        FreshAuthentication $fresh,
        AuditLogger $audit,
    ): RedirectResponse {
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();

        if ($user->password === null || ! Auth::guard('web')->validate(['email' => $user->email, 'password' => $data['password']])) {
            throw ValidationException::withMessages(['password' => 'Mot de passe incorrect.']);
        }

        $fresh->markAccountConfirmed($request);

        return $this->continueOrComplete($request, $fresh, $audit, 'password');
    }

    public function twoFactor(
        Request $request,
        FreshAuthentication $fresh,
        TotpService $totp,
        RecoveryCodeService $recovery,
        AuditLogger $audit,
    ): RedirectResponse {
        if (! $fresh->accountIsFresh($request)) {
            return redirect()->route('security.confirm');
        }

        $user = $request->user();
        abort_unless($user->hasEnabledTwoFactorAuthentication(), 422, 'La double authentification n’est pas activée.');
        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);

        $verified = $totp->verify($user->two_factor_secret, $data['code'], 'fresh-auth');
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
            throw ValidationException::withMessages(['code' => 'Code invalide.']);
        }

        $fresh->markTwoFactorConfirmed($request);

        if ($usedRecovery) {
            $audit->record('two_factor.recovery_code_used', $user, $user->activeOrganization, auditable: $user);
        }

        return $this->complete($request, $fresh, $audit, 'two_factor');
    }

    public function googleRedirect(Request $request, GoogleAuthClient $google): RedirectResponse
    {
        abort_unless($request->user()->socialIdentities()->where('provider', UserSocialIdentity::PROVIDER_GOOGLE)->exists(), 403);

        $state = Str::random(48);
        $request->session()->put('auth.fresh.google_state', $state);

        return redirect()->away($google->authorizationUrl(
            $state,
            forceReauthentication: true,
            redirectUri: route('security.confirm.google.callback'),
        ));
    }

    public function googleCallback(
        Request $request,
        GoogleAuthClient $google,
        FreshAuthentication $fresh,
        AuditLogger $audit,
    ): RedirectResponse {
        $expectedState = $request->session()->pull('auth.fresh.google_state');
        if (! is_string($expectedState) || ! hash_equals($expectedState, (string) $request->query('state'))) {
            return redirect()->route('security.confirm')->withErrors(['google' => 'Session Google invalide. Réessayez.']);
        }

        if ($request->query('error') || ! $request->query('code')) {
            return redirect()->route('security.confirm')->withErrors(['google' => 'Confirmation Google annulée.']);
        }

        try {
            $identity = $google->identityFromCode(
                (string) $request->query('code'),
                route('security.confirm.google.callback'),
            );
        } catch (RuntimeException) {
            return redirect()->route('security.confirm')->withErrors(['google' => 'Confirmation Google impossible.']);
        }

        $matchesAccount = $request->user()->socialIdentities()
            ->where('provider', UserSocialIdentity::PROVIDER_GOOGLE)
            ->where('provider_user_id', $identity->sub)
            ->exists();
        if (! $matchesAccount || ! $identity->emailVerified) {
            return redirect()->route('security.confirm')->withErrors(['google' => 'Ce compte Google ne correspond pas au compte connecté.']);
        }

        $fresh->markAccountConfirmed($request);

        return $this->continueOrComplete($request, $fresh, $audit, 'google');
    }

    private function continueOrComplete(
        Request $request,
        FreshAuthentication $fresh,
        AuditLogger $audit,
        string $method,
    ): RedirectResponse {
        $level = $fresh->requiredLevel($request);
        if ($level >= FreshAuthentication::LEVEL_TWO_FACTOR && $request->user()->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('security.confirm')->with('status', 'Identité confirmée. Saisissez maintenant votre code d’authentification.');
        }

        return $this->complete($request, $fresh, $audit, $method);
    }

    private function complete(
        Request $request,
        FreshAuthentication $fresh,
        AuditLogger $audit,
        string $method,
    ): RedirectResponse {
        $audit->record(
            'auth.fresh_confirmed',
            $request->user(),
            $request->user()->activeOrganization,
            auditable: $request->user(),
            newValues: ['level' => $fresh->requiredLevel($request), 'method' => $method],
        );

        return redirect($fresh->complete($request));
    }
}
