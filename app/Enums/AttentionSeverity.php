<?php

namespace App\Enums;

enum AttentionSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
