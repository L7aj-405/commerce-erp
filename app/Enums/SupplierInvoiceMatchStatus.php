<?php

namespace App\Enums;

enum SupplierInvoiceMatchStatus: string
{
    case NoPo = 'no_po';
    case PoPartial = 'po_partial';
    case PoMatched = 'po_matched';
    case Variance = 'variance';

    public function label(): string
    {
        return match ($this) {
            self::NoPo => 'Sans bon de commande',
            self::PoPartial => 'Rapprochement partiel',
            self::PoMatched => 'Conforme au bon de commande',
            self::Variance => 'Écart avec le bon de commande',
        };
    }
}
