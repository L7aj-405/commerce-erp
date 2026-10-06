<?php

namespace App\Services\Security;

use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;

final class FreshAuthentication
{
    public const LEVEL_ACCOUNT = 1;

    public const LEVEL_TWO_FACTOR = 2;

    private const ACCOUNT_CONFIRMED_AT = 'auth.fresh.account_confirmed_at';

    private const TWO_FACTOR_CONFIRMED_AT = 'auth.fresh.two_factor_confirmed_at';

    private const REQUIRED_LEVEL = 'auth.fresh.required_level';

    private const RETURN_TO = 'auth.fresh.return_to';

    public function isSatisfied(Request $request, int $level): bool
    {
        if (! $this->timestampIsFresh($request->session()->get(self::ACCOUNT_CONFIRMED_AT))) {
            return false;
        }

        return $level < self::LEVEL_TWO_FACTOR
            || ! $request->user()?->hasEnabledTwoFactorAuthentication()
            || $this->timestampIsFresh($request->session()->get(self::TWO_FACTOR_CONFIRMED_AT));
    }

    public function require(Request $request, int $level): void
    {
        $current = (int) $request->session()->get(self::REQUIRED_LEVEL, self::LEVEL_ACCOUNT);
        $request->session()->put(self::REQUIRED_LEVEL, max($current, $this->normalizeLevel($level)));

        if (! $request->session()->has(self::RETURN_TO)) {
            $request->session()->put(self::RETURN_TO, $this->safeReturnUrl($request));
        }
    }

    public function ensure(Request $request, int $level): void
    {
        if ($this->isSatisfied($request, $level)) {
            return;
        }

        $this->require($request, $level);
        $url = route('security.confirm');

        throw new HttpResponseException($request->expectsJson()
            ? response()->json(['message' => 'Confirmation de sécurité requise.', 'reauthentication_url' => $url], 409)
            : redirect($url));
    }

    public function requiredLevel(Request $request): int
    {
        return $this->normalizeLevel((int) $request->session()->get(self::REQUIRED_LEVEL, self::LEVEL_ACCOUNT));
    }

    public function markAccountConfirmed(Request $request): void
    {
        $request->session()->put(self::ACCOUNT_CONFIRMED_AT, time());
    }

    public function markTwoFactorConfirmed(Request $request): void
    {
        $request->session()->put(self::TWO_FACTOR_CONFIRMED_AT, time());
    }

    public function accountIsFresh(Request $request): bool
    {
        return $this->timestampIsFresh($request->session()->get(self::ACCOUNT_CONFIRMED_AT));
    }

    public function complete(Request $request): string
    {
        $returnTo = (string) $request->session()->pull(self::RETURN_TO, route('security.edit'));
        $request->session()->forget(self::REQUIRED_LEVEL);

        return str_starts_with($returnTo, '/') && ! str_starts_with($returnTo, '//')
            ? $returnTo
            : route('security.edit');
    }

    public function clear(Request $request): void
    {
        $request->session()->forget([
            self::ACCOUNT_CONFIRMED_AT,
            self::TWO_FACTOR_CONFIRMED_AT,
            self::REQUIRED_LEVEL,
            self::RETURN_TO,
        ]);
    }

    public function timeoutMinutes(): int
    {
        return max(1, (int) config('security.fresh_auth_timeout_minutes', 15));
    }

    private function timestampIsFresh(mixed $timestamp): bool
    {
        return is_numeric($timestamp) && (int) $timestamp >= time() - ($this->timeoutMinutes() * 60);
    }

    private function normalizeLevel(int $level): int
    {
        return $level >= self::LEVEL_TWO_FACTOR ? self::LEVEL_TWO_FACTOR : self::LEVEL_ACCOUNT;
    }

    private function safeReturnUrl(Request $request): string
    {
        $referer = $request->headers->get('referer');
        if (! is_string($referer) || $referer === '') {
            return route('security.edit');
        }

        $parts = parse_url($referer);
        if (! is_array($parts) || isset($parts['host']) && ! hash_equals((string) $request->getHost(), (string) $parts['host'])) {
            return route('security.edit');
        }

        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return str_starts_with($path, '/') && ! str_starts_with($path, '//')
            ? $path.$query
            : route('security.edit');
    }
}
