<?php

namespace App\Enums;

/** active: generates occurrences; paused: generates nothing until resumed; ended: permanently stopped. */
enum FollowUpRecurringStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
}
