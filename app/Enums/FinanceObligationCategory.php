<?php

namespace App\Enums;

enum FinanceObligationCategory: string
{
    case Supplier = 'supplier';
    case Salary = 'salary';
    case Cnss = 'cnss';
    case Tax = 'tax';
    case Rent = 'rent';
    case Subscription = 'subscription';
    case FixedExpense = 'fixed_expense';
    case VariableExpense = 'variable_expense';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Supplier => 'Fournisseur',
            self::Salary => 'Salaire',
            self::Cnss => 'CNSS',
            self::Tax => 'Taxe / Impôt',
            self::Rent => 'Loyer',
            self::Subscription => 'Abonnement',
            self::FixedExpense => 'Charge fixe',
            self::VariableExpense => 'Charge variable',
            self::Other => 'Autre',
        };
    }
}
