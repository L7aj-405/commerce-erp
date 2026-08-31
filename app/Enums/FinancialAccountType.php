<?php

namespace App\Enums;

enum FinancialAccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case CardClearing = 'card_clearing';
    case ChequeClearing = 'cheque_clearing';
    case Other = 'other';
}
