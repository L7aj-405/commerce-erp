<?php

namespace App\Services\Security;

use App\Contracts\ChallengeVerifier;
use Illuminate\Http\Request;

/**
 * Default {@see ChallengeVerifier} binding used until a real provider
 * (hCaptcha / Cloudflare Turnstile / reCAPTCHA) is configured. Deliberately
 * NOT "always pass everywhere" — an unconfigured security check that
 * silently lets every request through in production is worse than having no
 * check at all, since it would look like a challenge is enforced when it
 * is not. In local/testing it passes (nothing blocks development or CI on a
 * widget that cannot be completed there); in every other environment it
 * fails closed, so repeated-failure logins stay blocked until a real
 * provider is wired in.
 */
class NullChallengeVerifier implements ChallengeVerifier
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function verify(Request $request): bool
    {
        return app()->environment(['local', 'testing']);
    }
}
