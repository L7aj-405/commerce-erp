<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Progressive login-failure protection, keyed by NORMALIZED EMAIL + IP
 * together — never either alone (an email-only key lets one attacker lock a
 * victim's account for everyone; an IP-only key over-punishes shared
 * corporate/NAT connections for one person's mistakes). Built entirely on
 * Laravel's own {@see RateLimiter} rather than a bespoke lock table.
 *
 *   attempts 1-2   → normal password check only
 *   attempts 3+    → a ChallengeVerifier check is also required
 *   attempts 5+    → a temporary cooldown blocks the identifier/IP pair
 *                    entirely, doubling each time it re-triggers (capped)
 *
 * Never a permanent lock: every window has a decay, so the identifier always
 * recovers on its own — nobody can be denial-of-serviced out of their own
 * account by an attacker who deliberately fails 3 logins.
 */
class LoginThrottle
{
    public const CHALLENGE_THRESHOLD = 3;

    public const COOLDOWN_THRESHOLD = 5;

    private const ATTEMPT_WINDOW_SECONDS = 1800; // failures age out after 30 minutes of no activity

    private const MAX_COOLDOWN_SECONDS = 3600; // cooldown never grows past 1 hour

    public function requiresChallenge(string $email, string $ip): bool
    {
        return $this->attempts($email, $ip) >= self::CHALLENGE_THRESHOLD;
    }

    public function underCooldown(string $email, string $ip): bool
    {
        return RateLimiter::tooManyAttempts($this->cooldownKey($email, $ip), 1);
    }

    public function cooldownSecondsRemaining(string $email, string $ip): int
    {
        return RateLimiter::availableIn($this->cooldownKey($email, $ip));
    }

    public function attempts(string $email, string $ip): int
    {
        return RateLimiter::attempts($this->attemptsKey($email, $ip));
    }

    /** Records one failed password check and escalates the cooldown if the threshold is crossed. */
    public function recordFailure(string $email, string $ip): void
    {
        $attempts = RateLimiter::hit($this->attemptsKey($email, $ip), self::ATTEMPT_WINDOW_SECONDS);

        if ($attempts >= self::COOLDOWN_THRESHOLD) {
            $tier = $attempts - self::COOLDOWN_THRESHOLD;
            $seconds = (int) min(60 * (2 ** $tier), self::MAX_COOLDOWN_SECONDS);
            RateLimiter::hit($this->cooldownKey($email, $ip), $seconds);
        }
    }

    public function clearOnSuccess(string $email, string $ip): void
    {
        RateLimiter::clear($this->attemptsKey($email, $ip));
        RateLimiter::clear($this->cooldownKey($email, $ip));
    }

    private function identifier(string $email, string $ip): string
    {
        return Str::lower(trim($email)).'|'.$ip;
    }

    private function attemptsKey(string $email, string $ip): string
    {
        return 'login-attempts:'.sha1($this->identifier($email, $ip));
    }

    private function cooldownKey(string $email, string $ip): string
    {
        return 'login-cooldown:'.sha1($this->identifier($email, $ip));
    }
}
