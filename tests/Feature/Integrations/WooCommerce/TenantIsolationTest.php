<?php

namespace Tests\Feature\Integrations\WooCommerce;

use App\Models\Product;
use App\Models\ProductChannelIdentifier;
use App\Models\WooCommerceIntegration;
use Tests\Support\WooCommerceTestCase;

class TenantIsolationTest extends WooCommerceTestCase
{
    public function test_a_sync_only_ever_reads_and_writes_its_own_organizations_catalogue(): void
    {
        [, $orgA, , , $integrationA] = $this->wooContext();
        [$ownerB, $orgB, , , $integrationB] = $this->wooContext();

        // Org A already owns a product whose SKU collides with an Org B Woo product.
        $existingA = $this->createProduct($orgA, 'Produit A', 'SHARED-SKU');

        $this->fakeWooStore(products: [
            $this->wooProductPayload(['id' => 900, 'sku' => 'SHARED-SKU', 'name' => 'Produit Woo de B']),
        ]);
        $this->runSync($integrationB, $ownerB);

        // Org A is untouched: same name, no WooCommerce mapping leaked onto it.
        $this->assertSame('Produit A', $existingA->fresh()->name);
        $this->assertSame(0, ProductChannelIdentifier::query()
            ->where('organization_id', $orgA->getKey())
            ->where('source', WooCommerceIntegration::CHANNEL)
            ->count());
        unset($integrationA);

        // Org B got its own brand-new product + mapping.
        $this->assertSame(1, Product::where('organization_id', $orgB->getKey())->count());
        $this->assertSame(1, ProductChannelIdentifier::query()
            ->where('organization_id', $orgB->getKey())
            ->where('woocommerce_integration_id', $integrationB->getKey())
            ->where('external_product_id', '900')
            ->count());
    }

    public function test_a_user_cannot_drive_another_organizations_integration_over_http(): void
    {
        [$ownerA] = $this->wooContext();
        [, , , , $integrationB] = $this->wooContext();

        $this->actingAs($ownerA)
            ->get(route('integrations.woocommerce.status', $integrationB))
            ->assertNotFound();

        $this->actingAs($ownerA)
            ->post(route('integrations.woocommerce.sync', $integrationB), ['mode' => 'full'])
            ->assertNotFound();

        $this->actingAs($ownerA)
            ->patch(route('integrations.woocommerce.update', $integrationB), [
                'name' => 'Hijacked',
                'store_url' => 'https://evil.test',
                'consumer_key' => 'ck_x',
            ])
            ->assertNotFound();
    }
}
