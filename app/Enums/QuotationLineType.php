<?php

namespace App\Enums;

enum QuotationLineType: string
{
    /** Backed by a real ProductVariant in the catalogue. */
    case Catalog = 'catalog';

    /** A manually quoted article that is not (yet) in the Product catalogue. */
    case NonStock = 'non_stock';
}
