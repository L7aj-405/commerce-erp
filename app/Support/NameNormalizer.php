<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Case-, accent- and whitespace-insensitive key used for duplicate prevention (roles, payment terms, identity matching). */
final class NameNormalizer
{
    public static function normalize(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        // Deterministic transliteration (not iconv, which differs per platform). Arabic is kept as written: transliterating
        // it would make different names collide.
        if (preg_match('/\p{Arabic}/u', $value) !== 1) {
            $value = Str::ascii($value);
        }

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));
    }
}
