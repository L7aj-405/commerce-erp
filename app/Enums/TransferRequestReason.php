<?php

namespace App\Enums;

enum TransferRequestReason: string
{
    /** Remote stock a confirmed customer Order needs at the operational warehouse. */
    case OrderFulfillment = 'order_fulfillment';

    /** Best-effort top-up so the operational warehouse keeps its minimum display stock. */
    case MinimumReplenishment = 'minimum_replenishment';

    /** A warehouse manager raised the request by hand. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::OrderFulfillment => 'Commande',
            self::MinimumReplenishment => 'Réassort showroom',
            self::Manual => 'Manuel',
        };
    }
}
