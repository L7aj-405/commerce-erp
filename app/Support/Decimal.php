<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class Decimal
{
    public static function normalize(int|float|string $value, string $field = 'amount'): string
    {
        return InventoryQuantity::normalize($value, $field);
    }

    public static function positive(int|float|string $value, string $field = 'amount'): string
    {
        return InventoryQuantity::positive($value, $field);
    }

    public static function nonNegative(int|float|string $value, string $field = 'amount'): string
    {
        $value = self::normalize($value, $field);
        if (self::compare($value, InventoryQuantity::ZERO) < 0) {
            throw ValidationException::withMessages([$field => 'The value may not be negative.']);
        }

        return $value;
    }

    public static function add(int|float|string $left, int|float|string $right): string
    {
        return InventoryQuantity::add($left, $right);
    }

    public static function subtract(int|float|string $left, int|float|string $right): string
    {
        return InventoryQuantity::subtract($left, $right);
    }

    public static function compare(int|float|string $left, int|float|string $right): int
    {
        return InventoryQuantity::compare($left, $right);
    }

    public static function multiply(int|float|string $left, int|float|string $right): string
    {
        return self::multiplyAndScale($left, $right, 4);
    }

    public static function percentage(int|float|string $amount, int|float|string $rate): string
    {
        return self::multiplyAndScale($amount, $rate, 6);
    }

    private static function multiplyAndScale(int|float|string $left, int|float|string $right, int $dropDigits): string
    {
        $left = self::normalize($left);
        $right = self::normalize($right);
        $negative = str_starts_with($left, '-') !== str_starts_with($right, '-');
        $leftDigits = ltrim(str_replace('.', '', ltrim($left, '-')), '0') ?: '0';
        $rightDigits = ltrim(str_replace('.', '', ltrim($right, '-')), '0') ?: '0';
        $product = self::multiplyDigits($leftDigits, $rightDigits);
        $product = str_pad($product, $dropDigits + 1, '0', STR_PAD_LEFT);
        $cut = strlen($product) - $dropDigits;
        $scaled = substr($product, 0, $cut);
        $remainder = substr($product, $cut);
        if ($remainder !== '' && (int) $remainder[0] >= 5) {
            $scaled = self::incrementDigits($scaled);
        }
        $scaled = str_pad(ltrim($scaled, '0') ?: '0', 5, '0', STR_PAD_LEFT);
        $formatted = (ltrim(substr($scaled, 0, -4), '0') ?: '0').'.'.substr($scaled, -4);

        return self::normalize(($negative && $formatted !== InventoryQuantity::ZERO ? '-' : '').$formatted);
    }

    private static function multiplyDigits(string $left, string $right): string
    {
        if ($left === '0' || $right === '0') {
            return '0';
        }

        $result = array_fill(0, strlen($left) + strlen($right), 0);
        for ($i = strlen($left) - 1; $i >= 0; $i--) {
            for ($j = strlen($right) - 1; $j >= 0; $j--) {
                $position = $i + $j + 1;
                $sum = $result[$position] + ((int) $left[$i] * (int) $right[$j]);
                $result[$position] = $sum % 10;
                $result[$position - 1] += intdiv($sum, 10);
            }
        }

        return ltrim(implode('', $result), '0') ?: '0';
    }

    private static function incrementDigits(string $digits): string
    {
        $carry = 1;
        for ($index = strlen($digits) - 1; $index >= 0 && $carry; $index--) {
            $value = (int) $digits[$index] + $carry;
            $digits[$index] = (string) ($value % 10);
            $carry = intdiv($value, 10);
        }

        return $carry ? '1'.$digits : $digits;
    }
}
