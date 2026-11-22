<?php

namespace App\Enums;

enum SupplierInvoiceVoidReason: string
{
    case DuplicateEntry = 'duplicate_entry';
    case WrongSupplier = 'wrong_supplier';
    case WrongDataEntry = 'wrong_data_entry';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DuplicateEntry => 'Saisie en double',
            self::WrongSupplier => 'Mauvais fournisseur',
            self::WrongDataEntry => 'Erreur de saisie',
            self::Other => 'Autre',
        };
    }
}
