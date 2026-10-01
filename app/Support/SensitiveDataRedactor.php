<?php

namespace App\Support;

use Illuminate\Support\Str;

final class SensitiveDataRedactor
{
    private const SENSITIVE_KEY = '/(?:password|secret|token|authorization|credential|api[_-]?key|consumer[_-]?key|private[_-]?key|bearer)/i';

    /** @param array<string|int, mixed> $values @return array<string|int, mixed> */
    public static function sanitizeArray(array $values): array
    {
        $sanitized = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                continue;
            }
            $sanitized[$key] = is_array($value)
                ? self::sanitizeArray($value)
                : (is_string($value) ? self::text($value) : $value);
        }

        return $sanitized;
    }

    public static function text(string $value, int $limit = 1000): string
    {
        $redacted = preg_replace([
            '/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i',
            '/(?:access_token|refresh_token|consumer_secret|client_secret|smtp_password|password|authorization|credentials|api[_-]?key|private[_-]?key)\s*[:=]\s*[^\s,;]+/i',
        ], '[REDACTED]', $value) ?? '[REDACTED]';

        return Str::limit($redacted, $limit, '');
    }
}
