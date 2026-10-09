<?php

namespace App\Services\ProductCosts;

use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

class ProductCostExcelExport
{
    /** @var list<string> */
    public const HEADERS = [
        'Internal Variant ID', 'Internal Product ID', 'Product', 'Variant', 'SKU', 'Reference', 'Barcode',
        'Brand', 'Category', 'Stock', 'Current Purchase Price HT', 'New Purchase Price HT',
    ];

    public function build(Builder $query): string
    {
        $base = tempnam(sys_get_temp_dir(), 'product_cost_');
        if ($base === false) {
            throw new \RuntimeException('Impossible de préparer le fichier Excel.');
        }
        $path = $base.'.xlsx';
        @unlink($base);
        $writer = new Writer;

        try {
            $writer->openToFile($path);
            $writer->addRow(Row::fromValues(self::HEADERS, (new Style)->setFontBold()));

            foreach ($query->lazyById(500, 'product_variants.id', 'id') as $variant) {
                $writer->addRow(Row::fromValues([
                    $variant->getKey(),
                    $variant->product_id,
                    $variant->product->name,
                    $variant->label ?: 'Variante principale',
                    $variant->sku,
                    $variant->reference,
                    $variant->barcode,
                    $variant->product->brand?->name,
                    $variant->product->defaultCategory?->name,
                    (string) $variant->current_stock,
                    $variant->purchase_price,
                    '',
                ]));
            }

            $writer->close();
        } catch (Throwable $exception) {
            try {
                $writer->close();
            } catch (Throwable) {
                // Preserve the original export failure.
            }
            @unlink($path);
            throw $exception;
        }

        return $path;
    }
}
