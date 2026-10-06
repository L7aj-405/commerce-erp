<?php

namespace App\Enums;

enum NotificationCategory: string
{
    case System = 'system';
    case Security = 'security';
    case WooCommerce = 'woocommerce';
    case Backup = 'backup';
    case Inventory = 'inventory';
    case Finance = 'finance';
    case Sales = 'sales';
    case Documents = 'documents';
    case Account = 'account';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
