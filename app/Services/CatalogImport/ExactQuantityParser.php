<?php

namespace App\Services\CatalogImport;

use App\Support\InventoryQuantity;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ExactQuantityParser
{
    public function parse(mixed $value): string
    {
        $raw = trim(str_replace(["\u{00A0}", ' '], '', (string) $value));
        if ($raw === '') {
            return InventoryQuantity::ZERO;
        }
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            throw new InvalidArgumentException('Format de quantité ambigu.');
        }
        if (substr_count($raw, ',') === 1) {
            $raw = str_replace(',', '.', $raw);
        }

        try {
            $quantity = InventoryQuantity::normalize($raw, 'stock_quantity');
        } catch (ValidationException) {
            throw new InvalidArgumentException('Quantité de stock invalide.');
        }
        if (InventoryQuantity::compare($quantity, InventoryQuantity::ZERO) < 0) {
            throw new InvalidArgumentException('La quantité de stock ne peut pas être négative.');
        }

        return $quantity;
    }
}
