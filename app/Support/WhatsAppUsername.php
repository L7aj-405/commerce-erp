<?php

namespace App\Support;

final class WhatsAppUsername
{
    public const MAX_LENGTH = 35;

    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return str_starts_with($value, '@') ? substr($value, 1) : $value;
    }

    public static function isValid(?string $value): bool
    {
        $value = self::normalize($value);

        return $value !== null
            && preg_match('/\A(?![0-9]+\z)[a-z0-9._]{3,35}\z/', $value) === 1;
    }
}
