<?php

namespace Tests\Feature\Integrations\WooCommerce;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WooCommerceIntegration;
use Illuminate\Support\Facades\Http;
use Tests\Support\WooCommerceTestCase;

/**
 * Regression coverage for the WooCommerce variant-image bug: variable-product
 * variations were all inheriting the parent's main image instead of their own
 * WooCommerce variation image. Root cause was two-fold:
 *  1. `product_variants` had no image column at all (fixed by an additive
 *     migration — products.image_url already existed, but nothing equivalent
 *     existed per-variant).
 *  2. SyncWooCommerceProductsAction never read NormalizedWooVariant::$imageUrl
 *     (which the normalizer already computed correctly) when building/updating
 *     a ProductVariant — see variantPayload()/applyVariantValues().
 */
class VariantImageSyncTest extends WooCommerceTestCase
{
    private function variantByExternalSku(Organization $organization, string $sku): ProductVariant
    {
        return ProductVariant::query()->where('organization_id', $organization->getKey())->where('sku', $sku)->firstOrFail();
    }

    public function test_a_simple_product_still_gets_its_main_image_on_its_single_implicit_variant(): void
    {
        [$owner, $organization, , , $integration] = $this->wooContext();
        $url = 'https://shop.test/wp-content/uploads/cable.jpg';

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'CABLE-1', 'images' => [['src' => $url]]]),
        ]);
        $this->runSync($integration, $owner);

        $product = Product::where('organization_id', $organization->getKey())->firstOrFail();
        $this->assertSame($url, $product->image_url);
        $this->assertSame($url, $this->variantByExternalSku($organization, 'CABLE-1')->image_url);
    }

    public function test_each_variation_gets_its_own_woo_variation_image_not_the_parent_image(): void
    {
        [$owner, $organization, , , $integration] = $this->wooContext();
        $mainImage = 'https://shop.test/uploads/main-black.jpg';
        $blackImage = 'https://shop.test/uploads/black.jpg';
        $whiteImage = 'https://shop.test/uploads/white.jpg';
        $blueImage = 'https://shop.test/uploads/blue.jpg';

        $parent = $this->wooProductPayload(['id' => 200, 'type' => 'variable', 'sku' => 'TSHIRT', 'images' => [['src' => $mainImage]]]);
        $variations = [200 => [
            $this->wooVariationPayload(['id' => 201, 'sku' => 'TSHIRT-BLACK', 'image' => ['src' => $blackImage]]),
            $this->wooVariationPayload(['id' => 202, 'sku' => 'TSHIRT-WHITE', 'image' => ['src' => $whiteImage]]),
            $this->wooVariationPayload(['id' => 203, 'sku' => 'TSHIRT-BLUE', 'image' => ['src' => $blueImage]]),
        ]];

        $this->fakeWooStore(products: [$parent], variations: $variations);
        $this->runSync($integration, $owner);

        $product = Product::where('organization_id', $organization->getKey())->firstOrFail();
        $this->assertSame($mainImage, $product->image_url);
        $this->assertSame($blackImage, $this->variantByExternalSku($organization, 'TSHIRT-BLACK')->image_url);
        $this->assertSame($whiteImage, $this->variantByExternalSku($organization, 'TSHIRT-WHITE')->image_url);
        $this->assertSame($blueImage, $this->variantByExternalSku($organization, 'TSHIRT-BLUE')->image_url);

        // The historical bug: every variant collapsing onto the same URL.
        $distinct = ProductVariant::where('product_id', $product->getKey())->pluck('image_url')->unique();
        $this->assertCount(3, $distinct, 'each variant must keep its own distinct image');
    }

    public function test_a_variation_without_its_own_image_falls_back_to_the_parent_main_image(): void
    {
        [$owner, $organization, , , $integration] = $this->wooContext();
        $mainImage = 'https://shop.test/uploads/main.jpg';
        $blackImage = 'https://shop.test/uploads/black.jpg';

        $parent = $this->wooProductPayload(['id' => 210, 'type' => 'variable', 'sku' => 'HOOD', 'images' => [['src' => $mainImage]]]);
        $variations = [210 => [
            $this->wooVariationPayload(['id' => 211, 'sku' => 'HOOD-BLACK', 'image' => ['src' => $blackImage]]),
            // White variation carries NO image at all (wooVariationPayload's
            // default fixture has no 'image' key unless explicitly overridden).
            $this->wooVariationPayload(['id' => 212, 'sku' => 'HOOD-WHITE']),
        ]];

        $this->fakeWooStore(products: [$parent], variations: $variations);
        $this->runSync($integration, $owner);

        $this->assertSame($blackImage, $this->variantByExternalSku($organization, 'HOOD-BLACK')->image_url);
        $this->assertSame($mainImage, $this->variantByExternalSku($organization, 'HOOD-WHITE')->image_url);
    }

    public function test_a_resync_updates_the_variant_image_when_woo_changes_it(): void
    {
        [$owner, $organization, , , $integration] = $this->wooContext();
        $v1 = 'https://shop.test/uploads/white-v1.jpg';
        $v2 = 'https://shop.test/uploads/white-v2.jpg';

        $parent = $this->wooProductPayload(['id' => 220, 'type' => 'variable', 'sku' => 'CAP']);
        $variations = [220 => [$this->wooVariationPayload(['id' => 221, 'sku' => 'CAP-WHITE', 'image' => ['src' => $v1]])]];
        $this->fakeWooStore(products: [$parent], variations: $variations);
        $this->runSync($integration, $owner);
        $this->assertSame($v1, $this->variantByExternalSku($organization, 'CAP-WHITE')->image_url);

        $variations[220][0]['image']['src'] = $v2;
        $this->fakeWooStore(products: [$parent], variations: $variations);
        $this->runSync($integration, $owner);

        $this->assertSame($v2, $this->variantByExternalSku($organization, 'CAP-WHITE')->image_url);
    }

    public function test_a_resync_falls_back_to_the_parent_image_once_woo_removes_the_variation_image(): void
    {
        [$owner, $organization, , , $integration] = $this->wooContext();
        $mainImage = 'https://shop.test/uploads/main.jpg';
        $whiteImage = 'https://shop.test/uploads/white.jpg';

        $parent = $this->wooProductPayload(['id' => 230, 'type' => 'variable', 'sku' => 'SCARF', 'images' => [['src' => $mainImage]]]);
        $variations = [230 => [$this->wooVariationPayload(['id' => 231, 'sku' => 'SCARF-WHITE', 'image' => ['src' => $whiteImage]])]];
        $this->fakeWooStore(products: [$parent], variations: $variations);
        $this->runSync($integration, $owner);
        $this->assertSame($whiteImage, $this->variantByExternalSku($organization, 'SCARF-WHITE')->image_url);

        // Merchant removes the variation-specific image in WooCommerce.
        unset($variations[230][0]['image']);
        $this->fakeWooStore(products: [$parent], variations: $variations);
        $this->runSync($integration, $owner);

        $this->assertSame($mainImage, $this->variantByExternalSku($organization, 'SCARF-WHITE')->image_url, 'stale variation image must not survive removal');
    }

    public function test_variant_images_stay_correctly_associated_with_their_own_woo_variation_id_across_resyncs(): void
    {
        [$owner, $organization, , , $integration] = $this->wooContext();
        $blackImage = 'https://shop.test/uploads/black.jpg';
        $whiteImage = 'https://shop.test/uploads/white.jpg';

        $parent = $this->wooProductPayload(['id' => 240, 'type' => 'variable', 'sku' => 'POLO']);
        $variations = [240 => [
            $this->wooVariationPayload(['id' => 241, 'sku' => 'POLO-BLACK', 'image' => ['src' => $blackImage]]),
            $this->wooVariationPayload(['id' => 242, 'sku' => 'POLO-WHITE', 'image' => ['src' => $whiteImage]]),
        ]];
        $this->fakeWooStore(products: [$parent], variations: $variations);
        $this->runSync($integration, $owner);
        $this->fakeWooStore(products: [$parent], variations: $variations);
        $this->runSync($integration, $owner);

        $this->assertSame($blackImage, $this->variantByExternalSku($organization, 'POLO-BLACK')->image_url);
        $this->assertSame($whiteImage, $this->variantByExternalSku($organization, 'POLO-WHITE')->image_url);
    }

    public function test_a_second_organizations_variant_image_sync_never_touches_the_first(): void
    {
        [$ownerA, $orgA, , , $integrationA] = $this->wooContext();
        [$ownerB, $orgB, , , $integrationB] = $this->wooContext();
        $imageA = 'https://shop.test/uploads/org-a-black.jpg';
        $imageB = 'https://shop.test/uploads/org-b-black.jpg';

        $parent = $this->wooProductPayload(['id' => 300, 'type' => 'variable', 'sku' => 'SHARED-TSHIRT']);
        $this->fakeWooStore(
            products: [$parent],
            variations: [300 => [$this->wooVariationPayload(['id' => 301, 'sku' => 'SHARED-TSHIRT-A', 'image' => ['src' => $imageA]])]],
        );
        $this->runSync($integrationA, $ownerA);

        $this->fakeWooStore(
            products: [$parent],
            variations: [300 => [$this->wooVariationPayload(['id' => 301, 'sku' => 'SHARED-TSHIRT-B', 'image' => ['src' => $imageB]])]],
        );
        $this->runSync($integrationB, $ownerB);

        $this->assertSame($imageA, $this->variantByExternalSku($orgA, 'SHARED-TSHIRT-A')->image_url);
        $this->assertSame($imageB, $this->variantByExternalSku($orgB, 'SHARED-TSHIRT-B')->image_url);
        $this->assertSame(0, ProductVariant::where('organization_id', $orgA->getKey())->where('image_url', $imageB)->count());
    }

    public function test_variant_image_synchronization_never_downloads_any_image_binary(): void
    {
        [$owner, , , , $integration] = $this->wooContext();
        $blackImage = 'https://shop.test/uploads/black-original.jpg';

        $parent = $this->wooProductPayload(['id' => 250, 'type' => 'variable', 'sku' => 'JACKET']);
        $variations = [250 => [$this->wooVariationPayload(['id' => 251, 'sku' => 'JACKET-BLACK', 'image' => ['src' => $blackImage]])]];
        $this->fakeWooStore(products: [$parent], variations: $variations);

        $this->runSync($integration, $owner);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'black-original.jpg'));
    }
}
