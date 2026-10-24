<?php

namespace App\Enums;

enum FollowUpStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
