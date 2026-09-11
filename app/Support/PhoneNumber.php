<?php

namespace App\Support;

/**
 * Presentation / navigation helpers for phone numbers.
 *
 * These never mutate a stored Customer phone — they only derive a value for a
 * specific use (currently: a wa.me deep link). Moroccan numbers are the common
 * case, stored in many shapes: "06 12 34 56 78", "+212612345678",
 * "00212612345678", "0612-345-678".
 */
final class PhoneNumber
{
    /**
     * International digits-only form suitable for a wa.me link (no "+", no
     * spaces). Returns null when the input cannot be resolved to a plausible
     * mobile number.
     */
    public static function forWhatsApp(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $hadPlus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        // 00 <cc> …  ->  <cc> …
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // Local Moroccan form: 0XXXXXXXXX (10 digits, leading 0) -> 212XXXXXXXXX
        if (! $hadPlus && strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '212'.substr($digits, 1);
        }

        // Bare national mobile without the trunk 0 (9 digits starting 6/7).
        if (! $hadPlus && strlen($digits) === 9 && in_array($digits[0], ['6', '7'], true)) {
            $digits = '212'.$digits;
        }

        // Reject anything that still looks too short/long to be an international number.
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }
}
