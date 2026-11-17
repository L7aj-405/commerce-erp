<?php

namespace Tests\Unit\Architecture\Fixtures;

use App\Support\Decimal;

/**
 * Synthetic code analysed (never executed) by FinancialPrecisionTest. Methods prefixed `unsafe` MUST be
 * reported, methods prefixed `safe` MUST NOT. Not a test (no `Test` suffix).
 */
final class FloatFixtures
{
    public function unsafeCast($amount)
    {
        return (float) $amount;
    }

    public function unsafeFloatval($amount)
    {
        return floatval($amount);
    }

    public function unsafeLiteralArithmetic($amount)
    {
        return $amount * 1.2;
    }

    public function unsafeRound($amount)
    {
        return round($amount, 2);
    }

    public function unsafeArraySum(array $amounts)
    {
        return array_sum($amounts);
    }

    public function safeDecimalMath(string $a, string $b)
    {
        return Decimal::add($a, $b);
    }

    public function safeIntegerCastAndFormatting($count, $amount)
    {
        // `number_format` only formats an already computed value; integer casts are exact.
        return [(int) $count, number_format($amount, 2)];
    }

    // A float cast mentioned in a comment or a string must never match: (float) round(1.5)
    public function safeStringMentions()
    {
        return 'use (float) and round() carefully';
    }
}
