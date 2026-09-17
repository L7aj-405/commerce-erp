<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\ChallengeVerifier;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\LoginThrottle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login', ['status' => session('status')]);
    }

    public function store(Request $request, LoginThrottle $throttle, ChallengeVerifier $challenge): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $email = $credentials['email'];
        $ip = (string) $request->ip();

        // Never a permanent lock (§D) — this always recovers on its own once
        // the cooldown window elapses.
        if ($throttle->underCooldown($email, $ip)) {
            // Sanitized: hashed email + IP only, never the plaintext address —
            // this is an operational abuse signal, not a place to log PII.
            Log::warning('auth.login_throttled', ['email_hash' => Str::substr(hash('sha256', Str::lower($email)), 0, 16), 'ip' => $ip]);

            throw ValidationException::withMessages([
                'email' => 'Trop de tentatives. Réessayez dans '.$throttle->cooldownSecondsRemaining($email, $ip).' secondes.',
            ]);
        }

        // §D2 — after repeated failures, a bot/abuse challenge is required in
        // addition to the correct password. Never distinguishes "unknown
        // email" from "known email, needs challenge" in its error (§D3).
        if ($throttle->requiresChallenge($email, $ip) && ! $challenge->verify($request)) {
            throw ValidationException::withMessages([
                'challenge' => 'Vérification supplémentaire requise avant de réessayer.',
            ]);
        }

        // Validate credentials WITHOUT establishing a session — Auth::validate()
        // checks the password but never calls login(), unlike Auth::attempt().
        // A 2FA-enabled account must not get an authenticated session until the
        // challenge in TwoFactorChallengeController also passes (§E4).
        if (! Auth::validate($credentials)) {
            $throttle->recordFailure($email, $ip);

            // Deliberately identical whether the email doesn't exist or the
            // password is wrong (§D3) — never "account not found" vs "wrong
            // password".
            throw ValidationException::withMessages([
                'email' => 'Ces identifiants ne correspondent à aucun compte.',
            ]);
        }

        $throttle->clearOnSuccess($email, $ip);

        /** @var User $user */
        $user = User::query()->where('email', $email)->firstOrFail();

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put('login.2fa.user_id', $user->getKey());
            $request->session()->put('login.2fa.remember', $request->boolean('remember'));

            return redirect()->route('two-factor.challenge.show');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('platform.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
