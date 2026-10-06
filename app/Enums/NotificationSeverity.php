<?php

namespace App\Enums;

enum NotificationSeverity: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Critical = 'critical';
}
