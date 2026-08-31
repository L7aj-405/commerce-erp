<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Posted = 'posted';
    case Reversed = 'reversed';
}
