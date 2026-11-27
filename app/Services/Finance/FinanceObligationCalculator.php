<?php

namespace App\Services\Finance;

use App\Enums\FinanceOutgoingPaymentStatus;
use App\Models\FinanceObligation;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FinanceObligationCalculator
{
    public function paid(FinanceObligation $obligation, bool $lock = false): string
    {
        if ($lock) {
            // Locking read for money decisions (overpayment, cancellation). Under InnoDB REPEATABLE READ the outer table of
            // a `SELECT ... FOR UPDATE` is read at the latest committed state, but a table reached through a subquery
            // (whereHas => EXISTS) is a NON-locking read from the transaction snapshot: a payment committed by a
            // concurrent session after that snapshot would show its allocation but not its "posted" payment row, so it
            // would not be counted as paid. A locking JOIN reads and locks both tables at the latest committed state.
            return Decimal::normalize((string) DB::table('finance_outgoing_payment_allocations as a')
                ->join('finance_outgoing_payments as p', fn ($join) => $join->on('p.id', '=', 'a.outgoing_payment_id')->on('p.organization_id', '=', 'a.organization_id'))
                ->where('a.organization_id', $obligation->organization_id)
                ->where('a.obligation_id', $obligation->getKey())
                ->where('p.status', FinanceOutgoingPaymentStatus::Posted->value)
                ->lockForUpdate()
                ->sum('a.amount'));
        }

        return Decimal::normalize((string) $obligation->allocations()
            ->whereHas('outgoingPayment', fn ($query) => $query->where('status', FinanceOutgoingPaymentStatus::Posted->value))
            ->sum('amount'));
    }

    /** @return array{planned:string,paid:string,remaining:string,status:string,status_label:string} */
    public function values(FinanceObligation $obligation, Carbon $cutoff, bool $lock = false): array
    {
        $planned = Decimal::normalize((string) $obligation->planned_amount);
        $paid = $this->paid($obligation, $lock);
        $remaining = Decimal::subtract($planned, $paid);
        if (Decimal::compare($remaining, '0.0000') < 0) {
            $remaining = '0.0000';
        }

        if ($obligation->cancelled_at) {
            [$status, $label] = ['cancelled', 'Annulée'];
        } elseif (Decimal::compare($remaining, '0.0000') === 0) {
            [$status, $label] = ['paid', 'Payée'];
        } elseif ($obligation->due_date->lt($cutoff->copy()->startOfDay())) {
            // Overdue takes display precedence over "partial" so a partially
            // paid September obligation remains visibly overdue in October.
            [$status, $label] = ['overdue', 'En retard'];
        } elseif (Decimal::compare($paid, '0.0000') > 0) {
            [$status, $label] = ['partial', 'Partiellement payée'];
        } else {
            [$status, $label] = ['upcoming', 'À venir'];
        }

        return compact('planned', 'paid', 'remaining', 'status') + ['status_label' => $label];
    }
}
