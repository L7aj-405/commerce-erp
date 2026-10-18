<?php

namespace App\Enums;

enum FinancePaymentInstrumentState: string
{
    case Issued = 'issued';
    case Presented = 'presented';
    case Cleared = 'cleared';
    case Cancelled = 'cancelled';
    case Returned = 'returned';
}
