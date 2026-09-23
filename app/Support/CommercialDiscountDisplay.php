<?php

namespace App\Support;

use Illuminate\Support\Collection;

final class CommercialDiscountDisplay
{
    public static function lineAmountInclTax(object $line): string
    {
        $grossInclTax = Decimal::multiply((string) $line->unit_price_incl_tax, (string) $line->quantity);
        $discountInclTax = Decimal::subtract($grossInclTax, (string) $line->total_incl_tax);

        return Decimal::compare($discountInclTax, '0.0000') > 0 ? $discountInclTax : '0.0000';
    }

    /** @param iterable<object>|Collection<int, object> $lines */
    public static function linesTotalInclTax(iterable $lines): string
    {
        $total = '0.0000';
        foreach ($lines as $line) {
            $total = Decimal::add($total, self::lineAmountInclTax($line));
        }

        return $total;
    }
}
