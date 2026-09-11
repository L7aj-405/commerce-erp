<?php

namespace Tests\Feature\Integrations\WooCommerce;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductChannelIdentifier;
use App\Models\ProductVariant;
use App\Models\WooCommerceCategoryMapping;
use App\Models\WooCommerceIntegration;
use App\Models\WooCommerceSyncRun;
use Illuminate\Support\Facades\Http;
use Tests\Support\WooCommerceTestCase;

class ProductSyncTest extends WooCommerceTestCase
{
    public function test_a_simple_product_synced_twice_creates_exactly_one_product_variant_and_mapping(): void
    {
        [$owner, $organization, , , $integration] = $this->wooContext();
        $product = $this->wooProductPayload(['id' => 101, 'sku' => 'WOO-101', 'name' => 'Câble HDMI']);

        $this->fakeWooStore(products: [$product]);
        $first = $this->runSync($integration, $owner);
        $this->fakeWooStore(products: [$product]);
        $second = $this->runSync($integration, $owner);

        $this->assertSame(WooCommerceSyncRun::STATUS_COMPLETED, $first->status);
        $this->assertSame(1, $first->products_created);
        $this->assertSame(0, $second->products_created);
        $this->assertSame(1, $second->products_updated);

        $this->assertSame(1, Product::where('organization_id', $organization->getKey())->count());
        $this->assertSame(1, ProductVariant::where('organization_id', $organization->getKey())->count());
        $this->assertSame(1, ProductChannelIdentifier::query()
            ->where('organization_id', $organization->getKey())
            ->where('source', WooCommerceIntegration::CHANNEL)
            ->count());

        $erp = Product::where('organization_id', $organization->getKey())->firstOrFail();
        $this->assertSame('Câble HDMI', $erp->name);
        $this->assertSame(1, $integration->fresh()->synced_product_count);
    }

    public function test_two_products_with_empty_skus_become_two_distinct_products(): void
    {
        [$owner, $organization, , , $integration] = $this->wooContext();

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 301, 'sku' => '', 'name' => 'Sans SKU A']),
            $this->wooProductPayload(['id' => 302, 'sku' => '   ', 'name' => 'Sans SKU B']),
        ]);

        $run = $this->runSync($integration, $owner);

        $this->assertSame(2, $run->products_created);
        $this->assertSame(2, Product::where('organization_id', $organization->getKey())->count());

        // Empty / whitespace SKUs are stored as NULL, never "" and never fabricated.
        $this->assertSame(2, ProductVariant::where('organization_id', $organization->getKey())->whereNull('sku')->count());
        $this->assertSame(0, ProductVariant::where('organization_id', $organization->getKey())->where('sku', '')->count());
    }

    public function test_a_variable_product_maps_to_one_product_with_one_variant_per_variation(): void
    {
        [$owner, $organization, , , $integration] = $this->wooContext();

        $parent = $this->wooProductPayload(['id' => 200, 'type' => 'variable', 'sku' => 'TSHIRT', 'name' => 'T-Shirt']);
        $variations = [
            200 => [
                $this->wooVariationPayload(['id' => 201, 'sku' => 'TSHIRT-BM', 'attributes' => [['name' => 'Color', 'option' => 'Black'], ['name' => 'Size', 'option' => 'M']]]),
                $this->wooVariationPayload(['id' => 202, 'sku' => 'TSHIRT-BL', 'attributes' => [['name' => 'Color', 'option' => 'Black'], ['name' => 'Size', 'option' => 'L']]]),
            ],
        ];

        $this->fakeWooStore(products: [$parent], variations: $variations);
        $this->runSync($integration, $owner);
        $this->fakeWooStore(products: [$parent], variations: $variations);
        $this->runSync($integration, $owner);

        $this->assertSame(1, Product::where('organization_id', $organization->getKey())->count());
        $product = Product::where('organization_id', $organization->getKey())->firstOrFail();
        $this->assertSame(2, $product->variants()->count());

        $labels = $product->variants()->pluck('label')->sort()->values()->all();
        $this->assertSame(['Black / L', 'Black / M'], $labels);

        // One product-level mapping + one mapping per variation, integration-scoped, no dupes.
        $mappings = ProductChannelIdentifier::query()
            ->where('woocommerce_integration_id', $integration->getKey())
            ->where('source', WooCommerceIntegration::CHANNEL)
            ->get();
        $this->assertCount(3, $mappings);
        $this->assertEqualsCanonicalizing(
            ['200', '201', '202'],
            $mappings->pluck('external_product_id')->all(),
        );
        $this->assertSame(
            ['201', '202'],
            $mappings->whereNotNull('remote_variation_id')->pluck('remote_variation_id')->sort()->values()->all(),
        );
    }

    public function test_image_url_is_stored_verbatim_without_any_server_side_fetch(): void
    {
        [$owner, $organization, , , $integration] = $this->wooContext();
        $url = 'https://shop.test/wp-content/uploads/2026/09/photo-original.jpg';

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'images' => [['src' => $url]]]),
        ]);

        $this->runSync($integration, $owner);

        $this->assertSame($url, Product::where('organization_id', $organization->getKey())->value('image_url'));
        // Only wc/v3 endpoints were hit — never the image host itself.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'photo-original.jpg'));
    }

    public function test_categories_are_matched_by_remote_id_and_never_duplicated(): void
    {
        [$owner, $organization, , , $integration] = $this->wooContext();

        $categories = [$this->wooCategoryPayload(15, 'Accessoires')];
        $products = [
            $this->wooProductPayload(['id' => 101, 'sku' => 'A-1', 'categories' => [['id' => 15, 'name' => 'Accessoires']]]),
            $this->wooProductPayload(['id' => 102, 'sku' => 'A-2', 'categories' => [['id' => 15, 'name' => 'Accessoires']]]),
        ];

        $this->fakeWooStore(products: $products, categories: $categories);
        $this->runSync($integration, $owner);
        $this->fakeWooStore(products: $products, categories: $categories);
        $this->runSync($integration, $owner);

        $this->assertSame(1, Category::where('organization_id', $organization->getKey())
            ->whereRaw('LOWER(name) = ?', ['accessoires'])->count());
        $this->assertSame(1, WooCommerceCategoryMapping::query()
            ->where('woocommerce_integration_id', $integration->getKey())
            ->where('remote_category_id', 15)
            ->count());
    }

    public function test_a_partial_failure_completes_with_errors_and_keeps_the_good_products(): void
    {
        [$owner, $organization, , , $integration] = $this->wooContext();

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 101, 'sku' => 'OK-1', 'name' => 'Bon produit 1']),
            $this->wooProductPayload(['id' => 102, 'sku' => 'BAD', 'name' => 'Prix cassé', 'regular_price' => 'not-a-price', 'price' => 'not-a-price']),
            $this->wooProductPayload(['id' => 103, 'sku' => 'OK-2', 'name' => 'Bon produit 2']),
        ]);

        $run = $this->runSync($integration, $owner);

        $this->assertSame(WooCommerceSyncRun::STATUS_COMPLETED_WITH_ERRORS, $run->status);
        $this->assertSame(3, $run->products_read);
        $this->assertSame(2, $run->products_created);
        $this->assertSame(1, $run->products_failed);
        $this->assertSame(2, Product::where('organization_id', $organization->getKey())->count());

        $this->assertNotEmpty($run->errors);
        $failure = collect($run->errors)->firstWhere('remote_id', '102');
        $this->assertNotNull($failure, 'Expected a recorded error for Woo product #102.');
        $this->assertStringContainsString('prix', mb_strtolower($failure['message']));
    }

    public function test_regular_and_sale_prices_are_distinguished(): void
    {
        [$owner, $organization, , , $integration] = $this->wooContext();

        $this->fakeWooStore(products: [
            $this->wooProductPayload([
                'id' => 101, 'sku' => 'PROMO-1',
                'regular_price' => '200.00', 'sale_price' => '150.00', 'price' => '150.00', 'on_sale' => true,
            ]),
        ], pricesIncludeTax: true);

        $this->runSync($integration, $owner);

        $variant = ProductVariant::where('organization_id', $organization->getKey())->firstOrFail();
        $this->assertSame('200.0000', $variant->regular_sale_price);
        $this->assertSame('150.0000', $variant->promotional_sale_price);
        $this->assertSame('150.0000', $variant->public_price_ttc);
    }
}
