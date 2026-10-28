<?php

namespace App\Enums;

/**
 * Supplier purchase order (bon de commande fournisseur) lifecycle, P1:
 *
 *   draft → ordered → cancelled
 *
 * Receiving is not modelled on the purchase order yet: received goods still go
 * through the procurement requirement (ReceiveProcurementAction).
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Ordered => 'Commandée',
            self::Cancelled => 'Annulée',
        };
    }

    /** Its lines count as quantity committed to the supplier. */
    public function isActive(): bool
    {
        return $this !== self::Cancelled;
    }
}
