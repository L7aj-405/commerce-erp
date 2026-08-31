<?php

namespace App\Services\CatalogImport;

use Illuminate\Support\Str;

class ProductImportHeaderMapper
{
    /** @return array<string, array{label:string, required:bool}> */
    public function fields(): array
    {
        return [
            'external_product_id' => ['label' => 'ID WooCommerce / ID externe', 'required' => false],
            'name' => ['label' => 'Nom du produit', 'required' => true],
            'sku' => ['label' => 'SKU', 'required' => false],
            'sale_price' => ['label' => 'Prix régulier', 'required' => true],
            'promo_price' => ['label' => 'Prix promotionnel', 'required' => false],
            'reference' => ['label' => 'Référence', 'required' => false],
            'barcode' => ['label' => 'Code-barres', 'required' => false],
            'description' => ['label' => 'Description', 'required' => false],
            'category' => ['label' => 'Catégorie', 'required' => false],
            'brand' => ['label' => 'Marque', 'required' => false],
            'product_type' => ['label' => 'Type de produit', 'required' => false],
            'parent_sku' => ['label' => 'SKU du produit parent', 'required' => false],
            'variant_label' => ['label' => 'Variante / Option', 'required' => false],
            'image_url' => ['label' => 'URL de l’image principale', 'required' => false],
            'external_stock_status' => ['label' => 'État stock WooCommerce', 'required' => false],
            'stock_quantity' => ['label' => 'Quantité de stock', 'required' => false],
        ];
    }

    /** @param list<string> $headers @return array<string, int|null> */
    public function suggest(array $headers): array
    {
        $mapping = array_fill_keys(array_keys($this->fields()), null);
        foreach ($headers as $index => $header) {
            $key = $this->match($this->normalize($header));
            if ($key && $mapping[$key] === null) {
                $mapping[$key] = $index;
            }
        }

        return $mapping;
    }

    private function match(string $header): ?string
    {
        $aliases = [
            'external_product_id' => ['id', 'product id', 'product_id', 'external id', 'external_id', 'woocommerce id'],
            'name' => ['name', 'product name', 'product_name', 'nom', 'nom du produit', 'title'],
            'sku' => ['sku', 'ugs', 'reference (sku)', 'reference sku'],
            'sale_price' => ['regular price', 'regular_price', 'price', 'prix', 'prix de vente', 'prix regulier'],
            'promo_price' => ['sale price', 'sale_price', 'promo price', 'promo_price', 'prix promo', 'prix promotionnel'],
            'reference' => ['reference', 'ref', 'product reference'],
            'barcode' => ['barcode', 'ean', 'gtin', 'upc', 'code barre', 'code-barres'],
            'description' => ['description', 'short description', 'short_description'],
            'category' => ['category', 'categories', 'categorie', 'categories produit'],
            'brand' => ['brand', 'brands', 'marque'],
            'product_type' => ['type', 'product type', 'product_type'],
            'parent_sku' => ['parent', 'parent sku', 'parent_sku', 'sku parent'],
            'variant_label' => ['variant', 'variation', 'attribute 1 value(s)', 'attribute_1_value'],
            'image_url' => ['image', 'images', 'image url', 'image_url'],
            'external_stock_status' => ['stock status', 'stock_status', 'etat du stock'],
            'stock_quantity' => ['stock', 'quantity', 'quantite', 'inventory', 'stock quantity', 'stock_quantity'],
        ];

        foreach ($aliases as $field => $values) {
            if (in_array($header, $values, true)) {
                return $field;
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii(str_replace(['-', '.'], ' ', $value)))) ?? '');
    }
}
