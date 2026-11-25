<?php

namespace App\Services\Finance;

use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * FC4-FIN-01: the single definition of an advance's available (unallocated) amount:
 *   payment amount - order allocations - opening-balance settlements still applied (released ones give the money back).
 * The advances register, the customer position, the dashboard and the Règlements tab all use it.
 */
final class CustomerAdvanceBalance
{
    /** Σ opening-balance settlements still applied to a payment (SQL fragment, $alias = the payments table alias). */
    public static function openingAppliedSql(string $alias = 'p'): string
    {
        return '(SELECT COALESCE(SUM(s.amount), 0) FROM customer_opening_balance_settlements s WHERE s.organization_id = '.$alias.'.organization_id'
            .' AND s.payment_id = '.$alias.'.id AND s.released_date IS NULL)';
    }

    /**
     * Opening-balance amounts still applied, keyed by payment id.
     *
     * @param  iterable<int>  $paymentIds
     * @return array<int|string, string>
     */
    public static function openingAppliedByPayment(int $organizationId, iterable $paymentIds): array
    {
        $ids = collect($paymentIds)->all();
        if ($ids === []) {
            return [];
        }

        return DB::table('customer_opening_balance_settlements')->where('organization_id', $organizationId)->whereIn('payment_id', $ids)->whereNull('released_date')
            ->groupBy('payment_id')->select('payment_id')->selectRaw('SUM(amount) as total')->pluck('total', 'payment_id')
            ->map(fn ($v) => Decimal::normalize((string) $v))->all();
    }
}
