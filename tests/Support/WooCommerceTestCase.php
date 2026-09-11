<?php

namespace Tests\Support;

use App\Actions\WooCommerce\SyncWooCommerceProductsAction;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WooCommerceIntegration;
use App\Models\WooCommerceSyncRun;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Shared scaffolding for the WooCommerce integration feature tests.
 *
 * Every outbound WooCommerce call is faked with {@see Http::fake()} — no test
 * touches the network. The default secret is deliberately distinctive so a test
 * can assert it never leaks into an Inertia payload, a log line or an exception.
 */
abstract class WooCommerceTestCase extends InventoryTestCase
{
    protected string $secret = 'cs_super_secret_do_not_leak_1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the client's transient-retry loop from sleeping in tests.
        config(['woocommerce.retry_times' => 1, 'woocommerce.retry_backoff_ms' => 0]);
    }

    /**
     * Owner + organization + active store + warehouse + a saved integration.
     *
     * A store-default tax rate is attached so the standard WooCommerce tax class
     * resolves cleanly; pass $withDefaultTax: false to exercise the "no tax
     * configured" warning path.
     *
     * @return array{0: User, 1: Organization, 2: Store, 3: Warehouse, 4: WooCommerceIntegration}
     */
    protected function wooContext(bool $syncStock = false, bool $withDefaultTax = true): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $this->activate($owner, $organization, $store);

        if ($withDefaultTax) {
            $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
            $store->forceFill(['default_tax_rate_id' => $tax->getKey()])->save();
            $store->refresh();
        }

        $integration = $this->createIntegration($organization, [
            'default_store_id' => $store->getKey(),
            'default_warehouse_id' => $syncStock ? $warehouse->getKey() : null,
            'sync_stock' => $syncStock,
        ]);

        return [$owner, $organization, $store, $warehouse, $integration];
    }

    protected function createIntegration(Organization $organization, array $overrides = []): WooCommerceIntegration
    {
        $integration = new WooCommerceIntegration;
        $integration->organization_id = $organization->getKey();
        $integration->name = 'Boutique de test';
        $integration->store_url = $overrides['store_url'] ?? 'https://shop.test';
        $integration->consumer_key = 'ck_test_key';
        $integration->consumer_secret = $overrides['consumer_secret'] ?? $this->secret;
        $integration->sync_stock = false;
        $integration->synced_product_count = 0;
        $integration->forceFill(array_diff_key($overrides, array_flip(['store_url', 'consumer_secret'])));
        $integration->save();

        return $integration->fresh();
    }

    protected function runSync(WooCommerceIntegration $integration, User $actor, string $mode = 'full'): WooCommerceSyncRun
    {
        return app(SyncWooCommerceProductsAction::class)->execute($integration->fresh(), $actor, $mode);
    }

    /* ---------------------------------------------------------------------
     |  HTTP fakes
     * ------------------------------------------------------------------ */

    /**
     * Fake a whole WooCommerce store for the wc/v3 endpoints the sync touches.
     *
     * @param  list<array<string, mixed>>  $products  raw Woo product payloads
     * @param  array<int, list<array<string, mixed>>>  $variations  keyed by parent product id
     * @param  list<array<string, mixed>>  $categories  raw Woo category payloads
     */
    protected function fakeWooStore(
        array $products = [],
        array $variations = [],
        array $categories = [],
        ?bool $pricesIncludeTax = true,
    ): void {
        Http::fake([
            '*/wp-json/wc/v3/products/categories*' => Http::response($categories, 200, $this->wpHeaders(count($categories))),

            '*/wp-json/wc/v3/products/*/variations*' => function (Request $request) use ($variations) {
                preg_match('#/products/(\d+)/variations#', $request->url(), $matches);
                $list = $variations[(int) ($matches[1] ?? 0)] ?? [];

                return Http::response($list, 200, $this->wpHeaders(count($list)));
            },

            '*/wp-json/wc/v3/settings/general/woocommerce_prices_include_tax*' => $pricesIncludeTax === null
                ? Http::response('', 404)
                : Http::response(['id' => 'woocommerce_prices_include_tax', 'value' => $pricesIncludeTax ? 'yes' : 'no'], 200),

            '*/wp-json/wc/v3/products*' => Http::response($products, 200, $this->wpHeaders(count($products))),

            '*' => Http::response([], 200, $this->wpHeaders(0)),
        ]);
    }

    /** @return array<string, int> */
    private function wpHeaders(int $total): array
    {
        return ['X-WP-Total' => $total, 'X-WP-TotalPages' => 1];
    }

    /* ---------------------------------------------------------------------
     |  Raw Woo payload builders
     * ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    protected function wooProductPayload(array $overrides = []): array
    {
        return array_replace([
            'id' => 101,
            'type' => 'simple',
            'status' => 'publish',
            'name' => 'Woo Widget',
            'slug' => 'woo-widget',
            'permalink' => 'https://shop.test/product/woo-widget',
            'sku' => 'WOO-101',
            'description' => 'A widget synchronised from WooCommerce.',
            'short_description' => 'Short.',
            'regular_price' => '120.00',
            'sale_price' => '',
            'price' => '120.00',
            'tax_status' => 'taxable',
            'tax_class' => '',
            'manage_stock' => false,
            'stock_quantity' => null,
            'stock_status' => 'instock',
            'images' => [['src' => 'https://shop.test/wp-content/uploads/widget.jpg']],
            'categories' => [],
            'tags' => [],
            'meta_data' => [],
            'date_modified_gmt' => '2026-09-01T10:00:00',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    protected function wooVariationPayload(array $overrides = []): array
    {
        return array_replace([
            'id' => 201,
            'status' => 'publish',
            'sku' => 'WOO-201',
            'regular_price' => '120.00',
            'sale_price' => '',
            'price' => '120.00',
            'tax_class' => '',
            'manage_stock' => false,
            'stock_quantity' => null,
            'stock_status' => 'instock',
            'attributes' => [['name' => 'Color', 'option' => 'Black']],
            'meta_data' => [],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    protected function wooCategoryPayload(int $id, string $name, int $parent = 0): array
    {
        return ['id' => $id, 'name' => $name, 'slug' => Str::slug($name), 'parent' => $parent];
    }
}
