<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Cache;

/**
 * RFC 6238 TOTP (HMAC-SHA1, 6 digits, 30-second step) — the standard every
 * mainstream authenticator app (Google/Microsoft Authenticator, 1Password,
 * Authy) implements, so no vendor SDK is needed. Secrets are RFC 4648 base32,
 * matching the `otpauth://` URI format those apps expect.
 *
 * Clock-skew tolerance is one step either side of "now" (±30s, §E6) — not the
 * dozens of windows some naive implementations accept.
 */
class TotpService
{
    private const PERIOD = 30;

    private const DIGITS = 6;

    private const DEFAULT_WINDOW = 1;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    public function provisioningUri(string $secret, string $accountLabel, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($accountLabel);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);

        return "otpauth://totp/{$label}?{$query}";
    }

    /**
     * Verifies a 6-digit code within ±1 time step, then marks that exact
     * (secret, context, code) triple spent for the rest of its validity
     * window so it cannot be replayed a second time for the same purpose.
     *
     * `$context` scopes the replay guard to one purpose (e.g. "enrollment" vs
     * "login") — without it, confirming enrollment and then logging in
     * moments later with the authenticator still showing the same 30-second
     * code would spuriously reject the second, entirely legitimate use.
     */
    public function verify(string $base32Secret, string $code, string $context = 'default', ?int $timestamp = null): bool
    {
        $code = preg_replace('/\s+/', '', (string) $code);
        if (! is_string($code) || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $replayKey = 'totp-used:'.hash('sha256', $base32Secret.'|'.$context.'|'.$code);
        if (Cache::has($replayKey)) {
            return false;
        }

        $timestamp ??= time();
        $key = $this->base32Decode($base32Secret);

        for ($step = -self::DEFAULT_WINDOW; $step <= self::DEFAULT_WINDOW; $step++) {
            $counter = intdiv($timestamp, self::PERIOD) + $step;
            if (hash_equals($this->hotp($key, $counter), $code)) {
                Cache::put($replayKey, true, self::PERIOD * (self::DEFAULT_WINDOW + 1));

                return true;
            }
        }

        return false;
    }

    private function hotp(string $key, int $counter): string
    {
        $binaryCounter = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($truncated % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $output;
    }

    private function base32Decode(string $data): string
    {
        $data = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $data));

        $bits = '';
        foreach (str_split($data) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                break;
            }
            $bytes .= chr(bindec($chunk));
        }

        return $bytes;
    }
}
