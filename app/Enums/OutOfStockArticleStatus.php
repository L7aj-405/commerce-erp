<?php

namespace App\Enums;

enum OutOfStockArticleStatus: string
{
    case Unresolved = 'unresolved';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Unresolved => 'À traiter',
            self::Resolved => 'Résolu',
        };
    }
}
