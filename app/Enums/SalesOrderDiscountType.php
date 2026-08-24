<?php

namespace App\Enums;

enum SalesOrderDiscountType: string
{
    case None = 'none';
    case Fixed = 'fixed';
    case Percentage = 'percentage';
}
