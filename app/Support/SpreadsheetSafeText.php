<?php

namespace App\Support;

final class SpreadsheetSafeText
{
    /**
     * Prefix formula-like user text with an apostrophe. Spreadsheet programs
     * display the original text but do not execute it as a formula.
     */
    public static function escape(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $first = $value[0] ?? '';
        $trimmed = ltrim($value);
        if (in_array($first, ["\t", "\r", "\n"], true)
            || ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true))) {
            return "'".$value;
        }

        return $value;
    }

    /** @param array<int, mixed> $values @return array<int, mixed> */
    public static function row(array $values): array
    {
        return array_map(self::escape(...), $values);
    }
}
