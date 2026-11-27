<?php

namespace App\Enums;

enum SupplierInvoiceStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Approved => 'Approuvée',
            self::Void => 'Annulée',
        };
    }
}
