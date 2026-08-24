<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class InventoryQuantity
{
    public const ZERO = '0.0000';

    public static function normalize(int|float|string $value, string $field = 'quantity'): string
    {
        $value = is_float($value) ? rtrim(rtrim(sprintf('%.4F', $value), '0'), '.') : (string) $value;

        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d{1,4}))?$/', trim($value), $matches)) {
            throw ValidationException::withMessages([$field => 'The quantity must be a decimal with at most four fractional digits.']);
        }

        $integer = ltrim($matches[2], '0') ?: '0';
        if (strlen($integer) > 15) {
            throw ValidationException::withMessages([$field => 'The quantity exceeds the supported DECIMAL(19,4) range.']);
        }

        $fraction = str_pad($matches[3] ?? '', 4, '0');
        $negative = $matches[1] === '-' && ($integer !== '0' || $fraction !== '0000');

        return ($negative ? '-' : '').$integer.'.'.$fraction;
    }

    public static function positive(int|float|string $value, string $field = 'quantity'): string
    {
        $normalized = self::normalize($value, $field);
        if (self::compare($normalized, self::ZERO) <= 0) {
            throw ValidationException::withMessages([$field => 'The quantity must be greater than zero.']);
        }

        return $normalized;
    }

    public static function add(int|float|string $left, int|float|string $right): string
    {
        [$leftNegative, $leftDigits] = self::parts(self::normalize($left));
        [$rightNegative, $rightDigits] = self::parts(self::normalize($right));

        if ($leftNegative === $rightNegative) {
            return self::format(self::addDigits($leftDigits, $rightDigits), $leftNegative);
        }

        $comparison = self::compareDigits($leftDigits, $rightDigits);
        if ($comparison === 0) {
            return self::ZERO;
        }

        return $comparison > 0
            ? self::format(self::subtractDigits($leftDigits, $rightDigits), $leftNegative)
            : self::format(self::subtractDigits($rightDigits, $leftDigits), $rightNegative);
    }

    public static function subtract(int|float|string $left, int|float|string $right): string
    {
        $right = self::normalize($right);

        return self::add($left, str_starts_with($right, '-') ? substr($right, 1) : '-'.$right);
    }

    public static function compare(int|float|string $left, int|float|string $right): int
    {
        [$leftNegative, $leftDigits] = self::parts(self::normalize($left));
        [$rightNegative, $rightDigits] = self::parts(self::normalize($right));

        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $comparison = self::compareDigits($leftDigits, $rightDigits);

        return $leftNegative ? -$comparison : $comparison;
    }

    /** @return array{bool, string} */
    private static function parts(string $value): array
    {
        $negative = str_starts_with($value, '-');
        $digits = str_replace('.', '', ltrim($value, '-'));

        return [$negative, ltrim($digits, '0') ?: '0'];
    }

    private static function format(string $digits, bool $negative): string
    {
        $digits = str_pad(ltrim($digits, '0') ?: '0', 5, '0', STR_PAD_LEFT);
        $integer = ltrim(substr($digits, 0, -4), '0') ?: '0';
        $fraction = substr($digits, -4);
        if (strlen($integer) > 15) {
            throw ValidationException::withMessages(['quantity' => 'The resulting quantity exceeds the supported DECIMAL(19,4) range.']);
        }
        $isZero = $integer === '0' && $fraction === '0000';

        return ($negative && ! $isZero ? '-' : '').$integer.'.'.$fraction;
    }

    private static function compareDigits(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';

        return strlen($left) <=> strlen($right) ?: strcmp($left, $right) <=> 0;
    }

    private static function addDigits(string $left, string $right): string
    {
        $carry = 0;
        $result = '';
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;

        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry) {
            $sum = ($leftIndex >= 0 ? (int) $left[$leftIndex--] : 0)
                + ($rightIndex >= 0 ? (int) $right[$rightIndex--] : 0)
                + $carry;
            $result = ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return $result;
    }

    private static function subtractDigits(string $larger, string $smaller): string
    {
        $borrow = 0;
        $result = '';
        $smaller = str_pad($smaller, strlen($larger), '0', STR_PAD_LEFT);

        for ($index = strlen($larger) - 1; $index >= 0; $index--) {
            $digit = (int) $larger[$index] - $borrow - (int) $smaller[$index];
            if ($digit < 0) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = $digit.$result;
        }

        return ltrim($result, '0') ?: '0';
    }
}
