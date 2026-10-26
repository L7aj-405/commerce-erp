<?php

namespace Tests\Feature\Mcp;

use App\Models\McpIdempotencyRecord;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Mcp\McpConnectionCredentials;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Products\ProductWriteTools;
use App\Services\ProductPriceResolver;
use Tests\Support\CatalogTestCase;

/** MCP-W6.2: create_product / create_variant replay before uniqueness; canonical "at least one sale price" rule. */
class McpProductCreateIdempotencyTest extends CatalogTestCase
{
    private const SCOPES = ['products.view', 'products.create', 'product_cost.manage'];

    /** @return array{Organization, User, TaxRate} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $organization->settings = ['allow_mcp_connections' => true];
        $organization->save();
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');

        app()->forgetScopedInstances();
        $issued = app(McpConnectionCredentials::class)->issue($owner, $organization, 'Agent', self::SCOPES, null);
        app(McpExecutionContext::class)->bind($issued['connection']->load(['organization', 'user', 'scopeRecords']));

        return [$organization->fresh(), $owner, $tax];
    }

    /** @return array<string, mixed> */
    private function productData(TaxRate $tax, array $variant = []): array
    {
        return [
            'name' => 'ZZ MCP Test Product', 'status' => 'active',
            'variant' => ['sku' => 'ZZ-MCP-001', 'purchase_price' => '100.0000', 'public_price_ttc' => '150.0000', 'tax_rate_id' => (int) $tax->getKey(), ...$variant],
        ];
    }

    /** @return array<string, mixed> */
    private function mcpCreateProduct(array $data, string $key): array
    {
        return app(ProductWriteTools::class)->createProduct($data, $key)->structuredContent;
    }

    /** @return array<string, mixed> */
    private function mcpCreateVariant(int $productId, array $data, string $key): array
    {
        return app(ProductWriteTools::class)->createVariant($productId, $data, $key)->structuredContent;
    }

    public function test_exact_create_product_retry_replays_the_original_result_without_failing_on_its_own_sku(): void
    {
        [$organization, , $tax] = $this->fixture();
        $data = $this->productData($tax);

        $created = $this->mcpCreateProduct($data, 'mcp-workflow-product-2026-10-26');
        $this->assertTrue($created['ok'], json_encode($created));
        $replay = $this->mcpCreateProduct($data, 'mcp-workflow-product-2026-10-26');

        $this->assertTrue($replay['ok'], 'A retry must not surface "variant.sku has already been taken": '.json_encode($replay));
        $this->assertFalse($created['data']['idempotent_replay']);
        $this->assertTrue($replay['data']['idempotent_replay']);
        $this->assertSame($created['data']['target'], $replay['data']['target']);
        $this->assertSame($created['data']['variant_ids'], $replay['data']['variant_ids']);
        $this->assertSame(1, Product::query()->where('organization_id', $organization->getKey())->count());
        $this->assertSame(1, ProductVariant::query()->where('organization_id', $organization->getKey())->where('sku', 'ZZ-MCP-001')->count());
    }

    public function test_same_key_with_a_changed_payload_conflicts_and_a_new_key_with_a_taken_sku_still_fails_uniqueness(): void
    {
        [$organization, , $tax] = $this->fixture();
        $this->assertTrue($this->mcpCreateProduct($this->productData($tax), 'key-1')['ok']);

        $conflict = $this->mcpCreateProduct($this->productData($tax, ['public_price_ttc' => '160.0000']), 'key-1');
        $this->assertSame('MCP_IDEMPOTENCY_CONFLICT', $conflict['error']['code']);

        $duplicate = $this->mcpCreateProduct([...$this->productData($tax), 'name' => 'Autre produit'], 'key-2');
        $this->assertSame('MCP_VALIDATION_FAILED', $duplicate['error']['code']);
        $this->assertArrayHasKey('variant.sku', $duplicate['error']['details']['fields']);

        $this->assertSame(1, Product::query()->where('organization_id', $organization->getKey())->count(), 'Neither the conflict nor the duplicate mutated anything.');
        $this->assertSame(1, McpIdempotencyRecord::query()->where('tool', 'create_product')->count());
        $this->assertSame('MCP_IDEMPOTENCY_CONFLICT', $this->mcpCreateProduct([...$this->productData($tax), 'name' => 'Autre produit'], 'key-1')['error']['code'],
            'Key-1 stays bound to its original request.');
    }

    public function test_a_failed_create_records_nothing_so_the_key_is_not_bound_to_a_partial_operation(): void
    {
        [$organization, , $tax] = $this->fixture();

        $invalid = $this->mcpCreateProduct($this->productData($tax, ['public_price_ttc' => '-1']), 'key-f');
        $this->assertSame('MCP_VALIDATION_FAILED', $invalid['error']['code']);
        $this->assertSame(0, McpIdempotencyRecord::query()->count());
        $this->assertSame(0, Product::query()->where('organization_id', $organization->getKey())->count());

        // Uniqueness failing inside the idempotent transaction also leaves no record and no row.
        $this->mcpCreateProduct([...$this->productData($tax, ['sku' => 'TAKEN']), 'name' => 'Premier'], 'key-a');
        $this->mcpCreateProduct([...$this->productData($tax, ['sku' => 'TAKEN']), 'name' => 'Second'], 'key-b');
        $this->assertFalse(McpIdempotencyRecord::query()->where('idempotency_key', 'key-b')->exists());
        $this->assertSame(1, Product::query()->where('organization_id', $organization->getKey())->count());

        $this->assertTrue($this->mcpCreateProduct($this->productData($tax), 'key-f')['ok'], 'A key whose request failed can be used for a valid request.');
    }

    public function test_create_variant_exact_retry_replays_and_a_new_key_with_a_taken_sku_fails(): void
    {
        [, , $tax] = $this->fixture();
        $productId = $this->mcpCreateProduct($this->productData($tax), 'p-1')['data']['target']['id'];
        $variant = ['sku' => 'ZZ-MCP-001-XL', 'label' => 'XL', 'regular_sale_price' => '150.0000', 'status' => 'active'];

        $created = $this->mcpCreateVariant($productId, $variant, 'v-1');
        $replay = $this->mcpCreateVariant($productId, $variant, 'v-1');
        $this->assertTrue($replay['ok'], json_encode($replay));
        $this->assertTrue($replay['data']['idempotent_replay']);
        $this->assertSame($created['data']['target'], $replay['data']['target']);
        $this->assertSame(1, ProductVariant::query()->where('sku', 'ZZ-MCP-001-XL')->count());

        $this->assertSame('MCP_IDEMPOTENCY_CONFLICT', $this->mcpCreateVariant($productId, [...$variant, 'label' => 'XXL'], 'v-1')['error']['code']);
        $duplicate = $this->mcpCreateVariant($productId, $variant, 'v-2');
        $this->assertSame('MCP_VALIDATION_FAILED', $duplicate['error']['code']);
        $this->assertArrayHasKey('sku', $duplicate['error']['details']['fields']);
    }

    public function test_any_single_sale_price_source_is_accepted_and_none_is_rejected_with_clear_field_errors(): void
    {
        [$organization, , $tax] = $this->fixture();

        foreach (['public_price_ttc', 'regular_sale_price', 'default_sale_price'] as $index => $field) {
            $data = $this->productData($tax, ['sku' => 'ZZ-ONE-'.$index]);
            unset($data['variant']['public_price_ttc']);
            $data['variant'][$field] = '150.0000';
            $result = $this->mcpCreateProduct($data, 'single-'.$field);
            $this->assertTrue($result['ok'], "{$field} alone is a valid sale price source: ".json_encode($result));
        }

        $all = $this->productData($tax, ['sku' => 'ZZ-ALL', 'regular_sale_price' => '150.0000', 'default_sale_price' => '150.0000']);
        $this->assertTrue($this->mcpCreateProduct($all, 'all-three')['ok']);

        $none = $this->productData($tax, ['sku' => 'ZZ-NONE']);
        unset($none['variant']['public_price_ttc']);
        $rejected = $this->mcpCreateProduct($none, 'none');
        $this->assertSame('MCP_VALIDATION_FAILED', $rejected['error']['code']);
        $this->assertEqualsCanonicalizing(['variant.public_price_ttc', 'variant.regular_sale_price', 'variant.default_sale_price'], array_keys($rejected['error']['details']['fields']));

        $promo = $this->mcpCreateProduct($this->productData($tax, ['sku' => 'ZZ-PROMO', 'regular_sale_price' => '150.0000', 'promotional_sale_price' => '170.0000']), 'promo');
        $this->assertSame(['variant.promotional_sale_price'], array_keys($promo['error']['details']['fields']), 'Promotional price is compared with the nested regular price.');
        $this->assertTrue($this->mcpCreateProduct($this->productData($tax, ['sku' => 'ZZ-PROMO', 'regular_sale_price' => '150.0000', 'promotional_sale_price' => '120.0000']), 'promo-ok')['ok']);

        $this->assertSame(5, Product::query()->where('organization_id', $organization->getKey())->count());
    }

    public function test_accepted_public_ttc_price_is_stored_as_exact_decimals_and_resolves_ht_from_the_20_percent_tax(): void
    {
        [, , $tax] = $this->fixture();
        $result = $this->mcpCreateProduct($this->productData($tax), 'norm');
        $variant = ProductVariant::query()->findOrFail($result['data']['variant_ids'][0]);

        $this->assertSame(
            ['purchase_price' => '100.0000', 'public_price_ttc' => '150.0000', 'regular_sale_price' => '150.0000', 'default_sale_price' => '150.0000', 'unit_price_ht' => null],
            ['purchase_price' => (string) $variant->purchase_price, 'public_price_ttc' => (string) $variant->public_price_ttc,
                'regular_sale_price' => (string) $variant->regular_sale_price, 'default_sale_price' => (string) $variant->default_sale_price, 'unit_price_ht' => $variant->unit_price_ht],
        );

        $resolved = app(ProductPriceResolver::class)->resolveWith($variant, null);
        $this->assertSame(['125.0000', '150.0000', 'product'], [$resolved['unit_price_ht'], $resolved['unit_price_ttc'], $resolved['tax_source']]);
    }

    public function test_web_product_form_accepts_its_single_public_ttc_price(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);

        $this->actingAs($owner)->post(route('catalog.products.store'), $this->productPayload([
            'variant' => ['sku' => 'WEB-TTC', 'default_sale_price' => null, 'public_price_ttc' => '120.0000'],
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('120.0000', (string) ProductVariant::query()->where('organization_id', $organization->getKey())->where('sku', 'WEB-TTC')->value('public_price_ttc'));
    }
}
