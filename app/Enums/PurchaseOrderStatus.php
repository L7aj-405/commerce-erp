<?php

namespace App\Enums;

/**
 * Supplier purchase order (bon de commande fournisseur) lifecycle:
 *
 *   draft → ordered → partially_received → received
 *              ↘ cancelled (only while nothing has been received)
 *
 * The receipt statuses are derived from the lines' received quantities by
 * ReceiveProcurementAction (the canonical receipt of the procurement domain).
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Ordered => 'Commandée',
            self::PartiallyReceived => 'Partiellement réceptionnée',
            self::Received => 'Réceptionnée',
            self::Cancelled => 'Annulée',
        };
    }

    /** Its lines count as quantity committed to the supplier. */
    public function isActive(): bool
    {
        return $this !== self::Cancelled;
    }

    /** Goods may still be received against it. */
    public function isReceivable(): bool
    {
        return in_array($this, [self::Ordered, self::PartiallyReceived], true);
    }
}
