<?php

namespace App\Enums;

/**
 * Operational status of one shipment. handed_to_carrier is NOT delivered: the
 * goods left the company with a transport contact.
 */
enum FulfillmentStatus: string
{
    case Preparing = 'preparing';
    case Ready = 'ready';
    case HandedToCarrier = 'handed_to_carrier';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /** Statuses whose items count against the Sales Order line quantities. */
    public function countsTowardsLines(): bool
    {
        return $this !== self::Cancelled;
    }

    /** Stock has left the company (or will never leave: cancelled is not shipped). */
    public function isShipped(): bool
    {
        return in_array($this, [self::HandedToCarrier, self::InTransit, self::Delivered, self::Closed], true);
    }

    public function isDelivered(): bool
    {
        return in_array($this, [self::Delivered, self::Closed], true);
    }
}
