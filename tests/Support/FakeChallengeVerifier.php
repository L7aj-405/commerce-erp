<?php

namespace Tests\Support;

use App\Contracts\ChallengeVerifier;
use Illuminate\Http\Request;

/**
 * Test double for {@see ChallengeVerifier} — lets a login-security test
 * control whether the challenge passes or fails independently of
 * NullChallengeVerifier's environment-based default (which always passes in
 * `testing` so it never blocks the rest of the suite).
 */
class FakeChallengeVerifier implements ChallengeVerifier
{
    public function __construct(private readonly bool $passes) {}

    public function isConfigured(): bool
    {
        return true;
    }

    public function verify(Request $request): bool
    {
        return $this->passes;
    }
}
