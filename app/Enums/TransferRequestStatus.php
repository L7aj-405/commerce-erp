<?php

namespace App\Enums;

enum TransferRequestStatus: string
{
    case Requested = 'requested';
    case Preparing = 'preparing';
    case Shipped = 'shipped';
    case Received = 'received';
    case Cancelled = 'cancelled';

    /** Still moving through the pipeline — its quantity counts as incoming stock. */
    public function isActive(): bool
    {
        return in_array($this, [self::Requested, self::Preparing, self::Shipped], true);
    }

    /** Not yet physically dispatched — can still be cancelled or reduced. */
    public function isCancellable(): bool
    {
        return in_array($this, [self::Requested, self::Preparing], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Received, self::Cancelled], true);
    }
}
