<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ResolveGoogleAuthenticatedUserAction;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Auth\GoogleAuthClient;
use App\Services\Security\TrustedTwoFactorDeviceManager;
use App\Support\SensitiveDataRedactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleAuthenticationController extends Controller
{
    public function redirect(Request $request, GoogleAuthClient $google): RedirectResponse
    {
        $state = Str::random(48);
        $request->session()->put('auth.google.state', $state);

        return redirect()->away($google->authorizationUrl($state));
    }

    public function callback(
        Request $request,
        GoogleAuthClient $google,
        ResolveGoogleAuthenticatedUserAction $resolveUser,
        TrustedTwoFactorDeviceManager $trustedDevices,
        AuditLogger $audit,
    ): RedirectResponse {
        $expectedState = $request->session()->pull('auth.google.state');
        if (! is_string($expectedState) || ! hash_equals($expectedState, (string) $request->query('state'))) {
            return redirect()->route('login')->withErrors(['google' => 'Session Google invalide. Réessayez.']);
        }

        if ($request->query('error')) {
            return redirect()->route('login')->withErrors(['google' => 'Connexion Google annulée.']);
        }

        if (! $request->query('code')) {
            return redirect()->route('login')->withErrors(['google' => 'Connexion Google impossible.']);
        }

        try {
            $identity = $google->identityFromCode((string) $request->query('code'));
            $user = $resolveUser->execute($identity);
        } catch (RuntimeException $exception) {
            Log::notice('auth.google_failed', [
                'ip' => $request->ip(),
                'reason' => SensitiveDataRedactor::text($exception->getMessage(), 160),
            ]);
            return redirect()->route('login')->withErrors(['google' => $exception->getMessage()]);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            if ($trustedDevices->validFor($user, $request)) {
                Auth::login($user);
                $request->session()->regenerate();
                $audit->record('auth.google_login_succeeded', $user, $user->activeOrganization, auditable: $user, newValues: [
                    'second_factor' => 'trusted_device',
                ]);

                return redirect()->intended(route('platform.index'));
            }

            $request->session()->put('login.2fa.user_id', $user->getKey());
            $request->session()->put('login.2fa.remember', false);

            return redirect()->route('two-factor.challenge.show');
        }

        Auth::login($user);
        $request->session()->regenerate();
        $audit->record('auth.google_login_succeeded', $user, $user->activeOrganization, auditable: $user, newValues: [
            'second_factor' => 'not_enabled',
        ]);

        return redirect()->intended(route('platform.index'));
    }
}
