<?php

namespace Tests\Feature\Security;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Tests\Support\CatalogTestCase;

class CatalogMassAssignmentTest extends CatalogTestCase
{
    public function test_catalog_models_are_fully_guarded(): void
    {
        foreach ([new Product, new ProductVariant, new Brand] as $model) {
            try {
                $model->fill(['organization_id' => 1, 'product_id' => 1, 'brand_id' => 1, 'tax_rate_id' => 1, 'status' => 'active']);
                $this->fail('Catalog mass assignment should be rejected.');
            } catch (MassAssignmentException) {
                $this->assertSame([], $model->getAttributes());
            }
        }
    }

    public function test_forged_tenant_and_relationship_fields_cannot_override_product_ownership(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $brandA = $this->createBrand($organizationA, 'A Brand');
        $brandB = $this->createBrand($organizationB, 'B Brand');

        $this->actingAs($ownerA)->postJson(route('catalog.products.store'), $this->productPayload(['organization_id' => $organizationB->id, 'brand_id' => $brandB->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('brand_id');
        $this->actingAs($ownerA)->post(route('catalog.products.store'), $this->productPayload(['organization_id' => $organizationB->id, 'brand_id' => $brandA->id, 'variant' => ['product_id' => 999999]]))->assertRedirect();

        $this->assertDatabaseHas('products', ['organization_id' => $organizationA->id, 'brand_id' => $brandA->id, 'name' => 'New Product']);
        $this->assertDatabaseMissing('products', ['organization_id' => $organizationB->id, 'name' => 'New Product']);
    }

    public function test_forged_tax_rate_id_from_other_tenant_is_rejected(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $taxB = $this->createTaxRate($organizationB);
        $this->actingAs($ownerA)->postJson(route('catalog.products.store'), $this->productPayload(['variant' => ['tax_rate_id' => $taxB->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors('variant.tax_rate_id');
        $this->assertDatabaseMissing('products', ['organization_id' => $organizationA->id, 'name' => 'New Product']);
    }
}
