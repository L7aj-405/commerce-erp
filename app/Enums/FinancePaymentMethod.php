<?php

namespace App\Enums;

enum FinancePaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case Traite = 'traite';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Espèces', self::Card => 'TPE', self::BankTransfer => 'Virement',
            self::Cheque => 'Chèque', self::Traite => 'Traite',
        };
    }
}
