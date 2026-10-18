<?php

namespace App\Enums;

enum FinancePaymentInstrumentType: string
{
    case Cheque = 'cheque';
    case Traite = 'traite';

    public function label(): string { return $this === self::Cheque ? 'Chèque' : 'Traite'; }
}
