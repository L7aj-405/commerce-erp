<?php

namespace App\Enums;

enum DeliveryNoteStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Cancelled = 'cancelled';
}
