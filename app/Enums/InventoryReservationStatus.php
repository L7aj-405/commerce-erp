<?php

namespace App\Enums;

enum InventoryReservationStatus: string
{
    case Active = 'active';
    case Consumed = 'consumed';
    case Released = 'released';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
