<?php

namespace App\Services\SupplierInvoices;

/**
 * The only sanctioned window in which the guarded fields of an obligation generated from a supplier invoice may be
 * written (approve creates, void cancels). Used by the supplier invoice actions around their obligation writes;
 * enforced by the FinanceObligation model guard. Nestable; always released, even on exceptions.
 */
final class SupplierInvoiceObligationScope
{
    private static int $depth = 0;

    public static function run(callable $callback): mixed
    {
        self::$depth++;
        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
