<?php

namespace App\Enums;

enum FinanceOutgoingPaymentStatus: string
{
    case Posted = 'posted';
    case Reversed = 'reversed';
}
