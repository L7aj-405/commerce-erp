<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case Opening = 'opening';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case ReservationConsumed = 'reservation_consumed';
}
