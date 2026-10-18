<?php

namespace App\Enums;

enum FinanceRecurringDueDayMode: string
{
    case Fixed = 'fixed';
    case EndOfMonth = 'end_of_month';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Jour fixe',
            self::EndOfMonth => 'Fin du mois',
        };
    }
}
