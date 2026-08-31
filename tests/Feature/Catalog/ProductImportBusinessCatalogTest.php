<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProductImportCorrectionTestCase;

class ProductImportBusinessCatalogTest extends ProductImportCorrectionTestCase
{
    public function test_real_business_columns_create_external_mapping_image_exact_prices_and_opening_stock(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization, 'Showroom');
        $import = $this->stageBusinessFile($owner, '987654,https://93.184.216.34/product.png,Microphone X,Black,MIC-BLK,1200.00,990.00,instock,8,Shure');

       $expected = $this->businessMapping();

$actual = collect($import->mapping)
    ->only(array_keys($expected))
    ->all();

ksort($expected);
ksort($actual);

$this->assertSame($expected, $actual);

        $this->previewBusinessFile($owner, $import, ['stock_mode' => 'import', 'warehouse_id' => $warehouse->id]);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->confirmBusinessFile($owner, $import);

        $product = Product::query()->where('organization_id', $organization->id)->firstOrFail();
        $variant = $product->variants()->firstOrFail();
        Http::assertNothingSent();
        $this->assertNotSame(987654, $product->id);
        $this->assertSame('https://93.184.216.34/product.png', $product->image_url);
        $this->assertSame('1200.0000', $variant->regular_sale_price);
        $this->assertSame('990.0000', $variant->promotional_sale_price);
        $this->assertSame('990.0000', $variant->default_sale_price);
        $this->assertDatabaseHas('product_channel_identifiers', [
            'organization_id' => $organization->id, 'product_id' => $product->id, 'source' => 'woocommerce',
            'external_product_id' => '987654', 'external_stock_status' => 'instock',
        ]);
        $this->assertDatabaseHas('inventory_movements', ['organization_id' => $organization->id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'movement_type' => 'opening', 'quantity' => '8.0000']);
        $this->assertDatabaseHas('inventory_balances', ['organization_id' => $organization->id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'on_hand' => '8.0000']);
        $this->assertDatabaseHas('product_imports', ['id' => $import->id, 'created_product_count' => 1, 'linked_image_url_count' => 1, 'invalid_or_missing_image_url_count' => 0, 'initialized_stock_count' => 1]);
    }

    public function test_null_promotional_price_keeps_regular_price_as_effective_price(): void
    {
        $owner = User::factory()->create();
        $this->createOrganization($owner);
        $import = $this->stageBusinessFile($owner, '1001,,Cable X,,CABLE-X,125.50,,outofstock,0,Acme');

        $this->previewBusinessFile($owner, $import);
        $this->confirmBusinessFile($owner, $import);

        $this->assertDatabaseHas('product_variants', [
            'sku' => 'CABLE-X', 'regular_sale_price' => '125.5000',
            'promotional_sale_price' => null, 'default_sale_price' => '125.5000',
        ]);
        $this->assertDatabaseCount('inventory_movements', 0);
    }
}
