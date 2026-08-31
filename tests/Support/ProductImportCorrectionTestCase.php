<?php

namespace Tests\Support;

use App\Models\ProductImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;

abstract class ProductImportCorrectionTestCase extends InventoryTestCase
{
    /** @return array<string, int|null> */
    protected function businessMapping(): array
    {
        return [
            'external_product_id' => 0,
            'image_url' => 1,
            'name' => 2,
            'variant_label' => 3,
            'sku' => 4,
            'sale_price' => 5,
            'promo_price' => 6,
            'external_stock_status' => 7,
            'stock_quantity' => 8,
            'brand' => 9,
        ];
    }

    protected function stageBusinessFile(User $user, string $rows, string $name = 'catalogue-entreprise.csv'): ProductImport
    {
        $headers = 'ID,Image,Nom du Produit,Variation,Référence (SKU),Prix Régulier,Prix Promo,État du Stock,Quantité,Marque';
        $this->actingAs($user)->post(route('catalog.product-imports.store'), [
            'file' => UploadedFile::fake()->createWithContent($name, $headers."\n".$rows),
        ])->assertRedirect();

        return ProductImport::query()->latest('id')->firstOrFail();
    }

    /** @param array<string, mixed> $defaults */
    protected function previewBusinessFile(User $user, ProductImport $import, array $defaults = ['stock_mode' => 'skip']): void
    {
        $this->actingAs($user)->put(route('catalog.product-imports.preview', $import), [
            'mapping' => $this->businessMapping(),
            'defaults' => $defaults,
        ])->assertRedirect();
    }

    protected function confirmBusinessFile(User $user, ProductImport $import): void
    {
        $this->actingAs($user)->post(route('catalog.product-imports.confirm', $import))->assertRedirect();
    }
}
