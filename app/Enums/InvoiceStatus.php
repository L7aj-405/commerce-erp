<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Cancelled = 'cancelled';

    /**
     * A previously issued Invoice that has been replaced by an issued correction.
     * It stays immutable and its PDF stays available; it is no longer the
     * shareable document.
     */
    case Superseded = 'superseded';
}
