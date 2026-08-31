<?php

namespace Tests\Feature\Catalog;

use App\Models\ProductImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\Support\CatalogTestCase;

class ProductImportAuthorizationTest extends CatalogTestCase
{
    public function test_user_without_import_permission_is_denied_before_staging(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $viewer, ['products.view']);
        $this->activate($viewer, $organization);

        $this->actingAs($viewer)->post(route('catalog.product-imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('products.csv', "Name,SKU,Price\nDenied,DEN-1,10"),
        ])->assertForbidden();

        $this->assertDatabaseCount('product_imports', 0);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_browser_cannot_supply_tenant_fields(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $this->activate($ownerA, $organizationA);

        $this->actingAs($ownerA)->post(route('catalog.product-imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('products.csv', "Name,SKU,Price\nForged,FORGE-1,10"),
            'organization_id' => $organizationB->id,
            'store_id' => 999999,
        ])->assertSessionHasErrors(['organization_id', 'store_id']);

        $this->assertDatabaseCount('product_imports', 0);
    }

    public function test_import_route_binding_hides_another_organization_import(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $this->activate($ownerB, $organizationB);
        $this->actingAs($ownerB)->post(route('catalog.product-imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('products.csv', "Name,SKU,Price\nSecret,SEC-1,10"),
        ])->assertRedirect();
        $foreignImport = ProductImport::query()->where('organization_id', $organizationB->id)->firstOrFail();

        $this->activate($ownerA, $organizationA);
        $this->actingAs($ownerA)->get(route('catalog.product-imports.show', $foreignImport))->assertNotFound();
        $this->actingAs($ownerA)->post(route('catalog.product-imports.confirm', $foreignImport))->assertNotFound();

        $this->assertDatabaseHas('product_imports', ['id' => $foreignImport->id, 'status' => 'uploaded']);
        $this->assertDatabaseMissing('products', ['organization_id' => $organizationA->id, 'name' => 'Secret']);
    }

    public function test_preview_rejects_cross_tenant_reference_defaults(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $foreignBrand = $this->createBrand($organizationB, 'Foreign');
        $this->activate($ownerA, $organizationA);
        $this->actingAs($ownerA)->post(route('catalog.product-imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('products.csv', "Name,SKU,Price\nLocal,LOC-1,10"),
        ])->assertRedirect();
        $import = ProductImport::query()->where('organization_id', $organizationA->id)->firstOrFail();

        $this->actingAs($ownerA)->put(route('catalog.product-imports.preview', $import), [
            'mapping' => ['name' => 0, 'sku' => 1, 'sale_price' => 2],
            'defaults' => ['brand_id' => $foreignBrand->id],
        ])->assertSessionHasErrors('defaults.brand_id');

        $this->assertDatabaseHas('product_imports', ['id' => $import->id, 'status' => 'uploaded']);
        $this->assertDatabaseCount('products', 0);
    }
}
