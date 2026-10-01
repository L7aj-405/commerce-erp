<?php

namespace App\Services\Security;

use App\Contracts\ChallengeVerifier;
use Illuminate\Http\Request;

/**
 * Default binding until a real challenge provider is configured. Callers use
 * isConfigured() to cleanly omit this optional challenge; verify() itself
 * fails closed so accidental direct use can never masquerade as protection.
 * Login cooldown/rate limiting remains active independently.
 */
class NullChallengeVerifier implements ChallengeVerifier
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function verify(Request $request): bool
    {
        return false;
    }
}
