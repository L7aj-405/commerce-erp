<?php

namespace App\Services\CatalogImport;

use App\Models\ProductImportRow;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductImportFailureMapper
{
    /** @return array{code:string, message:string, expected:bool} */
    public function map(Throwable $exception, ProductImportRow $row): array
    {
        $sku = (string) data_get($row->normalized_data, 'sku', '');
        $reference = (string) data_get($row->normalized_data, 'reference', '');
        $quantity = (string) data_get($row->normalized_data, 'stock_quantity', '');

        if ($exception instanceof ValidationException) {
            $errors = $exception->errors();
            if (isset($errors['quantity']) || isset($errors['stock_quantity'])) {
                return [
                    'code' => 'invalid_stock_quantity',
                    'message' => $quantity !== ''
                        ? "La quantité \"{$quantity}\" n’est pas valide pour le stock initial."
                        : 'La quantité de stock initial n’est pas valide.',
                    'expected' => true,
                ];
            }
            if (isset($errors['warehouse_id'])) {
                return [
                    'code' => 'invalid_warehouse',
                    'message' => 'L’emplacement sélectionné n’est pas disponible pour cette organisation.',
                    'expected' => true,
                ];
            }

            return [
                'code' => 'business_validation',
                'message' => 'Les règles métier empêchent l’import de cette ligne.',
                'expected' => true,
            ];
        }

        if ($exception instanceof UniqueConstraintViolationException) {
            $constraint = strtolower(implode(' ', [
                $exception->index ?? '',
                ...$exception->columns,
                $exception->getPrevious()?->getMessage() ?? '',
            ]));
            if (str_contains($constraint, 'sku')) {
                return ['code' => 'duplicate_sku', 'message' => "Le SKU \"{$sku}\" existe déjà.", 'expected' => true];
            }
            if ($reference !== '' && str_contains($constraint, 'reference')) {
                return ['code' => 'duplicate_reference', 'message' => "La référence \"{$reference}\" existe déjà.", 'expected' => true];
            }
            if (str_contains($constraint, 'barcode')) {
                return ['code' => 'duplicate_barcode', 'message' => 'Le code-barres existe déjà.', 'expected' => true];
            }
            if (str_contains($constraint, 'product_channel_external_unique') || str_contains($constraint, 'external_product_id')) {
                return ['code' => 'duplicate_external_id', 'message' => 'L’ID WooCommerce existe déjà.', 'expected' => true];
            }
        }

        return [
            'code' => 'technical_error',
            'message' => 'Une erreur technique a empêché l’import de cette ligne.',
            'expected' => false,
        ];
    }
}
