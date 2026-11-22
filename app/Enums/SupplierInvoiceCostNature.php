<?php

namespace App\Enums;

enum SupplierInvoiceCostNature: string
{
    case Inventory = 'inventory';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Inventory => 'Marchandise (stock)',
            self::Expense => 'Charge',
        };
    }
}
