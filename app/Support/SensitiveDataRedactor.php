<?php

namespace App\Support;

use Illuminate\Support\Str;

final class SensitiveDataRedactor
{
    private const SENSITIVE_KEY = '/(?:password|secret|token|authorization|credential|api[_-]?key|consumer[_-]?key|private[_-]?key|bearer|recovery[_-]?codes?|totp|session[_-]?id|cookie|app[_-]?key)/i';

    /** @param array<string|int, mixed> $values @return array<string|int, mixed> */
    public static function sanitizeArray(array $values): array
    {
        $sanitized = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                continue;
            }
            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeArray($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = self::text($value);
            } elseif (is_scalar($value) || $value === null) {
                $sanitized[$key] = $value;
            } else {
                // Never serialize an unknown object/resource into audit or
                // notification metadata where it may expose internal state.
                $sanitized[$key] = '[OMITTED]';
            }
        }

        return $sanitized;
    }

    public static function text(string $value, int $limit = 1000): string
    {
        $redacted = preg_replace(
            '/\b([a-z][a-z0-9+.-]*):\/\/[^\/\s:@]+:[^@\/\s]+@/i',
            '$1://[REDACTED]@',
            $value,
        ) ?? '[REDACTED]';
        $redacted = preg_replace([
            '/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i',
            '/(?:access_token|refresh_token|consumer_secret|client_secret|smtp_password|password|authorization|credentials|api[_-]?key|private[_-]?key|recovery[_-]?codes?|totp[_-]?secret|session[_-]?id|app[_-]?key|db[_-]?password)\s*[:=]\s*[^\s,;]+/i',
            '/([?&](?:access_token|refresh_token|token|code|client_secret|consumer_secret|api[_-]?key|signature|password)=)[^&#\s]*/i',
        ], '[REDACTED]', $redacted) ?? '[REDACTED]';

        return Str::limit($redacted, $limit, '');
    }
}
