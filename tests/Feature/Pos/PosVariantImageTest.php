<?php

namespace Tests\Feature\Pos;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Tests\Support\PosTestCase;

/**
 * Regression coverage for the REAL root cause of "variants still show the
 * parent image": SyncWooCommerceProductsAction already persisted a correct,
 * distinct ProductVariant.image_url per variation (verified separately in
 * VariantImageSyncTest), but PosController — the ERP's main "pick a specific
 * variant" screen — unconditionally serialized `$variant->product->image_url`
 * for every variant, discarding the correct per-variant value. Fixed in
 * PosController::products() and the draft/held-sale line payload.
 */
class PosVariantImageTest extends PosTestCase
{
    /** @return array{User, Organization, Store, Warehouse} */
    private function context(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $this->activate($owner, $organization, $store);

        return [$owner, $organization, $store, $warehouse];
    }

    private function addVariant(Product $product, string $sku, string $label, ?string $imageUrl): ProductVariant
    {
        $variant = new ProductVariant;
        $variant->organization_id = $product->organization_id;
        $variant->product_id = $product->getKey();
        $variant->label = $label;
        $variant->sku = $sku;
        $variant->image_url = $imageUrl;
        $variant->default_sale_price = '100.0000';
        $variant->regular_sale_price = '100.0000';
        $variant->status = 'active';
        $variant->save();

        return $variant;
    }

    public function test_the_catalogue_search_returns_each_variants_own_image_not_the_parents(): void
    {
        [$owner, $organization, , $warehouse] = $this->context();
        $product = $this->createProduct($organization, 'T-Shirt', null, ['image_url' => 'https://shop.test/uploads/black-main.jpg']);
        $black = $product->variants->first();
        $black->sku = 'TSHIRT-BLACK';
        $black->image_url = 'https://shop.test/uploads/black.jpg';
        $black->save();

        $white = $this->addVariant($product, 'TSHIRT-WHITE', 'White', 'https://shop.test/uploads/white.jpg');

        $this->openStock($owner, $organization, $warehouse, $black, '5.0000');
        $this->openStock($owner, $organization, $warehouse, $white, '5.0000');

        $response = $this->actingAs($owner)->getJson('/pos/products?warehouse_id='.$warehouse->getKey());
        $response->assertOk();

        $items = collect($response->json('data'));
        $this->assertSame('https://shop.test/uploads/black.jpg', $items->firstWhere('sku', 'TSHIRT-BLACK')['image_url']);
        $this->assertSame('https://shop.test/uploads/white.jpg', $items->firstWhere('sku', 'TSHIRT-WHITE')['image_url']);
    }

    public function test_the_catalogue_search_falls_back_to_the_parent_image_when_the_variant_has_none(): void
    {
        [$owner, $organization, , $warehouse] = $this->context();
        $product = $this->createProduct($organization, 'Sac', 'SAC-1', ['image_url' => 'https://shop.test/uploads/main.jpg']);
        $variant = $product->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '3.0000');

        $response = $this->actingAs($owner)->getJson('/pos/products?warehouse_id='.$warehouse->getKey());

        $item = collect($response->json('data'))->firstWhere('sku', 'SAC-1');
        $this->assertSame('https://shop.test/uploads/main.jpg', $item['image_url']);
    }

    public function test_an_active_draft_line_reports_its_own_variant_image_not_the_parents(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->context();
        $product = $this->createProduct($organization, 'T-Shirt', null, ['image_url' => 'https://shop.test/uploads/black-main.jpg']);
        $black = $product->variants->first();
        $black->sku = 'TSHIRT-BLACK';
        $black->image_url = 'https://shop.test/uploads/black.jpg';
        $black->save();
        $this->openStock($owner, $organization, $warehouse, $black, '5.0000');

        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $response = $this->actingAs($owner)->postJson(
            "/pos/drafts/{$draft->id}/lines",
            $this->posCatalogLine($black, ['warehouse_id' => $warehouse->getKey(), 'quantity' => '1']),
        );

        $response->assertOk();
        $line = collect($response->json('active_sale.lines'))->firstWhere('product_variant_id', $black->id);
        $this->assertSame('https://shop.test/uploads/black.jpg', $line['image_url']);
    }
}
