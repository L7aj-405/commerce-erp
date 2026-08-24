<?php

namespace App\Enums;

enum SalesOrderSource: string
{
    case Manual = 'manual';
    case Pos = 'pos';
    case Ecommerce = 'ecommerce';
}
