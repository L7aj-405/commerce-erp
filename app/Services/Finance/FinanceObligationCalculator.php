<?php

namespace App\Services\Finance;

use App\Enums\FinanceOutgoingPaymentStatus;
use App\Models\FinanceObligation;
use App\Support\Decimal;
use Illuminate\Support\Carbon;

class FinanceObligationCalculator
{
    public function paid(FinanceObligation $obligation, bool $lock = false): string
    {
        $query = $obligation->allocations()
            ->whereHas('outgoingPayment', fn ($query) => $query->where('status', FinanceOutgoingPaymentStatus::Posted->value));

        if ($lock) {
            $query->lockForUpdate();
        }

        return Decimal::normalize((string) $query->sum('amount'));
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
