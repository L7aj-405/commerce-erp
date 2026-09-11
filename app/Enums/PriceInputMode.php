<?php

namespace App\Enums;

/**
 * Which side of the HT/TTC relationship the employee typed. The server always
 * derives the counterpart with exact Decimal arithmetic — HT and TTC are never
 * two independent editable values.
 */
enum PriceInputMode: string
{
    case Ht = 'ht';
    case Ttc = 'ttc';
}
