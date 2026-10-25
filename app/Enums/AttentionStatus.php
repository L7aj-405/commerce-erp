<?php

namespace App\Enums;

/**
 * open: needs attention now; snoozed: hidden until snoozed_until;
 * dismissed: a person chose to ignore this episode; resolved: the business
 * condition no longer holds (set by the detector).
 */
enum AttentionStatus: string
{
    case Open = 'open';
    case Snoozed = 'snoozed';
    case Dismissed = 'dismissed';
    case Resolved = 'resolved';
}
