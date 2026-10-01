<?php

namespace Tests\Support;

use App\Contracts\ChallengeVerifier;
use Illuminate\Http\Request;

/**
 * Test double for {@see ChallengeVerifier} — lets a login-security test
 * control whether the challenge passes or fails independently of
 * the deliberately unconfigured NullChallengeVerifier.
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
