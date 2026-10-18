<?php

namespace App\Services\Finance;

use App\Models\FinanceObligation;
use App\Support\Decimal;

class FinanceInstrumentCommitmentService
{
    public function committed(FinanceObligation $obligation, ?int $exceptInstrumentId = null, bool $lock = false): string
    {
        $query = $obligation->paymentInstruments()->whereIn('state', ['issued', 'presented']);
        if ($exceptInstrumentId) $query->whereKeyNot($exceptInstrumentId);
        if ($lock) $query->lockForUpdate();
        return Decimal::normalize((string) $query->sum('amount'));
    }
}
