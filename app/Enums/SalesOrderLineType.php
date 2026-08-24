<?php

namespace App\Enums;

enum SalesOrderLineType: string
{
    case Catalog = 'catalog';
    case Custom = 'custom';
}
