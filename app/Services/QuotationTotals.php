<?php

namespace App\Services;

use App\Support\Decimal;

/** Devis aggregates, computed strictly from line amounts. */
final class QuotationTotals
{
    /**
     * @param iterable<array<string, mixed>|object> $lines
     * @return array{subtotal_excl_tax: string, discount_total: string, tax_total: string, total_incl_tax: string}
     */
    public static function sum(iterable $lines): array
    {
        $totals = ['subtotal_excl_tax' => '0.0000', 'discount_total' => '0.0000', 'tax_total' => '0.0000', 'total_incl_tax' => '0.0000'];
        foreach ($lines as $line) {
            $totals['subtotal_excl_tax'] = Decimal::add($totals['subtotal_excl_tax'], data_get($line, 'subtotal_excl_tax'));
            $totals['discount_total'] = Decimal::add($totals['discount_total'], data_get($line, 'discount_amount'));
            $totals['tax_total'] = Decimal::add($totals['tax_total'], data_get($line, 'tax_amount'));
            $totals['total_incl_tax'] = Decimal::add($totals['total_incl_tax'], data_get($line, 'total_incl_tax'));
        }

        return $totals;
    }
}
