<?php

namespace App\Support;

/**
 * Currency-aware business comparisons on top of the exact 4-decimal Decimal
 * engine. Amounts stay stored and summed at the internal DECIMAL(19,4) scale;
 * only the business question "is this balance zero / are these amounts
 * equal?" is answered at the currency's real precision (ISO 4217 minor
 * units, half-up rounding). 0.0001 MAD is not a payable amount, so an
 * outstanding of 0.0001 MAD is settled; 0.0100 MAD is not.
 */
final class Money
{
    /** ISO 4217 minor units that differ from the default of 2. */
    private const MINOR_UNITS = [
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
        'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
    ];

    private const DEFAULT_MINOR_UNITS = 2;

    public static function scale(?string $currencyCode): int
    {
        return self::MINOR_UNITS[strtoupper(trim((string) $currencyCode))] ?? self::DEFAULT_MINOR_UNITS;
    }

    /** True when the amount rounds (half-up) to zero at the currency precision. */
    public static function isZero(int|float|string $amount, ?string $currencyCode): bool
    {
        return Decimal::compare(Decimal::round($amount, self::scale($currencyCode)), InventoryQuantity::ZERO) === 0;
    }

    /** Sign of (left - right) once the difference is expressed in the currency's minor units. */
    public static function compare(int|float|string $left, int|float|string $right, ?string $currencyCode): int
    {
        $difference = Decimal::subtract($left, $right);

        return self::isZero($difference, $currencyCode) ? 0 : Decimal::compare($difference, InventoryQuantity::ZERO);
    }

    /**
     * Amount still due: total - paid at the internal 4-decimal scale, or an
     * exact '0.0000' when nothing payable remains in this currency (settled,
     * overpaid, or only a sub-minor-unit residue).
     */
    public static function outstanding(int|float|string $total, int|float|string $paid, ?string $currencyCode): string
    {
        return self::compare($total, $paid, $currencyCode) > 0
            ? Decimal::subtract($total, $paid)
            : InventoryQuantity::ZERO;
    }
}
