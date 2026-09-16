<?php

namespace App\Enums;

enum WooCommerceStockTaskStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'À faire',
            self::Completed => 'Mis à jour',
            self::Cancelled => 'Annulé',
        };
    }
}
