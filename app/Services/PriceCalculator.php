<?php

namespace App\Services;

use App\Support\Decimal;

/**
 * Single source of truth for the relationship between a tax-exclusive (HT) price
 * and its public tax-inclusive (TTC) price for ONE tax rate.
 *
 *   TTC = HT + (HT × rate / 100)
 *   HT  = TTC / (1 + rate / 100)        (exact: TTC × 100 / (100 + rate))
 *
 * `$rate` is a percentage number, e.g. "20.0000" for a 20% VAT — the same shape
 * stored on `tax_rates.rate` and snapshotted on `sales_order_lines.tax_rate`.
 *
 * `inclusive()` derives the public price from an authoritative HT.
 * `exclusive()` derives the fallback HT from a public price when a Product has no
 * explicit HT stored (e.g. legacy TTC-only imports).
 */
class PriceCalculator
{
    /** Tax amount for an HT unit/line price at the given rate, at 4-decimal scale. */
    public function taxAmount(int|float|string $exclTax, int|float|string $rate): string
    {
        $exclTax = Decimal::normalize($exclTax);
        $rate = Decimal::normalize($rate);

        if (Decimal::compare($rate, '0.0000') === 0) {
            return '0.0000';
        }

        return Decimal::percentage($exclTax, $rate);
    }

    /** Public TTC price for an HT price at the given rate, at 4-decimal scale. */
    public function inclusive(int|float|string $exclTax, int|float|string $rate): string
    {
        return Decimal::add(Decimal::normalize($exclTax), $this->taxAmount($exclTax, $rate));
    }

    /**
     * HT price for a public TTC price at the given rate, at 4-decimal scale,
     * rounded half-up. Exact form: TTC × 100 / (100 + rate).
     */
    public function exclusive(int|float|string $inclTax, int|float|string $rate): string
    {
        $inclTax = Decimal::normalize($inclTax);
        $rate = Decimal::normalize($rate);

        if (Decimal::compare($rate, '0.0000') === 0) {
            return $inclTax;
        }

        return Decimal::divide(
            Decimal::multiply($inclTax, '100.0000'),
            Decimal::add('100.0000', $rate),
            4,
        );
    }
}
