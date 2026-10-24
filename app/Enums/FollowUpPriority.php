<?php

namespace App\Enums;

enum FollowUpPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
}
