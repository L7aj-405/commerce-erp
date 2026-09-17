<?php

namespace App\Contracts;

use Illuminate\Http\Request;

/**
 * Bot/abuse challenge (CAPTCHA-style) required after repeated login failures
 * (see {@see \App\Services\Security\LoginThrottle}). Kept behind an interface
 * so authentication logic never couples to one vendor (hCaptcha, Cloudflare
 * Turnstile, reCAPTCHA, …) — swap the bound implementation in
 * AppServiceProvider once a provider is chosen, with its site/secret keys
 * read from config/env, never hardcoded.
 */
interface ChallengeVerifier
{
    /** Whether a real provider is configured (vs. the safe no-op default). */
    public function isConfigured(): bool;

    /** Server-side verification of the challenge response submitted with the request. */
    public function verify(Request $request): bool;
}
