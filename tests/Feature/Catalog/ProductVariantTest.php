<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\Support\CatalogTestCase;

class ProductVariantTest extends CatalogTestCase
{
    public function test_variant_is_always_created_for_route_product_and_active_organization(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $product = $this->createProduct($organization);
        $payload = $this->productPayload()['variant'];
        $payload['sku'] = 'SECOND-SKU';
        $payload['organization_id'] = 999999;
        $payload['product_id'] = 999999;

        $this->actingAs($owner)->post(route('catalog.variants.store', $product), $payload)->assertRedirect();
        $this->assertDatabaseHas('product_variants', ['organization_id' => $organization->id, 'product_id' => $product->id, 'sku' => 'SECOND-SKU']);
    }

    public function test_cross_tenant_product_variant_association_is_rejected_by_binding(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $productB = $this->createProduct($organizationB);
        $this->activate($ownerA, $organizationA);

        $this->actingAs($ownerA)->post(route('catalog.variants.store', $productB), $this->productPayload()['variant'])->assertNotFound();
    }

    public function test_cross_tenant_tax_rate_is_rejected_for_variant(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $productA = $this->createProduct($organizationA);
        $taxB = $this->createTaxRate($organizationB);
        $payload = $this->productPayload()['variant'];
        $payload['sku'] = 'TAX-CHECK';
        $payload['tax_rate_id'] = $taxB->id;

        $this->actingAs($ownerA)->postJson(route('catalog.variants.store', $productA), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('tax_rate_id');
    }
}
