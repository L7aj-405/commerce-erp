<?php

namespace App\Enums;

/**
 * What the supplier has told the sales employee about THIS specific
 * procurement requirement. It is not a global supplier stock figure — only a
 * confirmation for the quantity this customer Order needs.
 */
enum SupplierAvailabilityStatus: string
{
    case PendingConfirmation = 'pending_confirmation';
    case ConfirmedAvailable = 'confirmed_available';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::PendingConfirmation => 'À confirmer',
            self::ConfirmedAvailable => 'Confirmée disponible',
            self::Unavailable => 'Indisponible',
        };
    }
}
