<?php

namespace Tests\Feature\Security\TenantRedTeam;

use App\Models\ProductImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\Support\CatalogTestCase;

class ProductImportTenantAttackTest extends CatalogTestCase
{
    public function test_forged_foreign_import_identifier_is_hidden_before_preview_or_mutation(): void
    {
        $attacker = User::factory()->create();
        $victim = User::factory()->create();
        $attackerOrganization = $this->createOrganization($attacker, 'Attacker');
        $victimOrganization = $this->createOrganization($victim, 'Victim');
        $this->activate($victim, $victimOrganization);
        $this->actingAs($victim)->post(route('catalog.product-imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('victim.csv', "Name,SKU,Price\nVictim product,VIC-1,999.99"),
        ])->assertRedirect();
        $victimImport = ProductImport::query()->where('organization_id', $victimOrganization->id)->firstOrFail();

        $this->activate($attacker, $attackerOrganization);
        $this->actingAs($attacker)->put(route('catalog.product-imports.preview', $victimImport), [
            'mapping' => ['name' => 0, 'sku' => 1, 'sale_price' => 2],
        ])->assertNotFound();
        $this->actingAs($attacker)->post(route('catalog.product-imports.confirm', $victimImport), [
            'organization_id' => $attackerOrganization->id,
        ])->assertNotFound();

        $this->assertDatabaseHas('product_imports', ['id' => $victimImport->id, 'organization_id' => $victimOrganization->id, 'status' => 'uploaded']);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_system_inventory_and_identifier_columns_cannot_mass_assign_domain_state(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $foreignOrganization = $this->createOrganization(User::factory()->create(), 'Foreign');
        $this->activate($owner, $organization);
        $csv = "Name,SKU,Price,id,organization_id,store_id,status,inventory_balance,reserved_quantity,created_by_user_id\nSafe product,SAFE-1,15.00,987654,{$foreignOrganization->id},999,inactive,500,400,999";
        $this->actingAs($owner)->post(route('catalog.product-imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('../../<script>catalog.csv', $csv),
        ])->assertRedirect();
        $import = ProductImport::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->assertStringNotContainsString('/', $import->original_file_name);
        $this->assertStringNotContainsString('\\', $import->original_file_name);
        $this->actingAs($owner)->put(route('catalog.product-imports.preview', $import), [
            'mapping' => ['name' => 0, 'sku' => 1, 'sale_price' => 2, 'stock_quantity' => 7],
            'defaults' => [],
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('catalog.product-imports.confirm', $import))->assertRedirect();

        $this->assertDatabaseHas('products', ['organization_id' => $organization->id, 'name' => 'Safe product', 'status' => 'active']);
        $this->assertDatabaseMissing('products', ['id' => 987654]);
        $this->assertDatabaseMissing('products', ['organization_id' => $foreignOrganization->id, 'name' => 'Safe product']);
        $this->assertDatabaseCount('inventory_balances', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }
}
