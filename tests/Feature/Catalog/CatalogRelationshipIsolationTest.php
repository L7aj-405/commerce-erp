<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\Support\CatalogTestCase;

class CatalogRelationshipIsolationTest extends CatalogTestCase
{
    public function test_brand_category_and_unit_from_other_tenant_cannot_be_assigned_to_product(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $brandB = $this->createBrand($organizationB);
        $categoryB = $this->createCategory($organizationB);
        $unitB = $this->createUnit($organizationB);

        foreach ([
            ['brand_id' => $brandB->id, 'field' => 'brand_id'],
            ['category_id' => $categoryB->id, 'field' => 'category_id'],
            ['unit_id' => $unitB->id, 'field' => 'unit_id'],
        ] as $attempt) {
            $field = $attempt['field'];
            $this->actingAs($ownerA)->postJson(route('catalog.products.store'), $this->productPayload([$field => $attempt[$field], 'variant' => ['sku' => 'SKU-'.$field]]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseMissing('products', ['organization_id' => $organizationA->id, 'name' => 'New Product']);
    }
}
