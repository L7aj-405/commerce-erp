<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ProductImportCorrectionTestCase;

class ProductImportStockWorkflowTest extends ProductImportCorrectionTestCase
{
    public function test_warehouse_is_required_when_quantity_is_imported_as_opening_stock(): void
    {
        $owner = User::factory()->create();
        $this->createOrganization($owner);
        $import = $this->stageBusinessFile($owner, '3001,,Stock Product,,STOCK-1,10.00,,instock,4,Brand');

        $this->actingAs($owner)->put(route('catalog.product-imports.preview', $import), [
            'mapping' => $this->businessMapping(),
            'defaults' => ['stock_mode' => 'import'],
        ])->assertSessionHasErrors('defaults.warehouse_id');

        $this->assertDatabaseHas('product_imports', ['id' => $import->id, 'status' => 'uploaded']);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_foreign_warehouse_cannot_be_selected_as_stock_destination(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $foreignWarehouse = $this->createWarehouse($organizationB, 'Foreign');
        $this->activate($ownerA, $organizationA);
        $import = $this->stageBusinessFile($ownerA, '3002,,Local Product,,LOCAL-1,10.00,,instock,4,Brand');

        $this->actingAs($ownerA)->put(route('catalog.product-imports.preview', $import), [
            'mapping' => $this->businessMapping(),
            'defaults' => ['stock_mode' => 'import', 'warehouse_id' => $foreignWarehouse->id],
        ])->assertSessionHasErrors('defaults.warehouse_id');

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_stock_import_requires_inventory_opening_permission(): void
    {
        $owner = User::factory()->create();
        $importer = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization);
        $this->addOrganizationMember($organization, $importer, ['products.import']);
        $this->activate($importer, $organization);
        $import = $this->stageBusinessFile($importer, '3003,,Denied Stock,,DENIED-1,10.00,,instock,4,Brand');

        $this->actingAs($importer)->put(route('catalog.product-imports.preview', $import), [
            'mapping' => $this->businessMapping(),
            'defaults' => ['stock_mode' => 'import', 'warehouse_id' => $warehouse->id],
        ])->assertForbidden();

        $this->assertDatabaseHas('product_imports', ['id' => $import->id, 'status' => 'uploaded']);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_revoked_stock_permission_is_rechecked_before_confirmation_state_changes(): void
    {
        $owner = User::factory()->create();
        $importer = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization);
        $membership = $this->addOrganizationMember($organization, $importer, ['products.import', 'inventory.opening']);
        $this->activate($importer, $organization);
        $import = $this->stageBusinessFile($importer, '3004,,Revoked Stock,,REVOKED-1,10.00,,instock,4,Brand');
        $this->previewBusinessFile($importer, $import, ['stock_mode' => 'import', 'warehouse_id' => $warehouse->id]);
        $membership->role->permissions()->detach($this->permission('inventory.opening')->id);

        $this->actingAs($importer)->post(route('catalog.product-imports.confirm', $import))->assertForbidden();

        $this->assertDatabaseHas('product_imports', ['id' => $import->id, 'status' => 'previewed']);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_repeated_external_id_variations_create_one_product_with_variant_opening_stock(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization, 'Showroom');
        $rows = implode("\n", [
            '4001,,Microphone X,Black,MIC-BLK,1200.00,990.00,instock,8,Shure',
            '4001,,Microphone X,White,MIC-WHT,1200.00,,outofstock,3,Shure',
        ]);
        $import = $this->stageBusinessFile($owner, $rows);

        $this->previewBusinessFile($owner, $import, ['stock_mode' => 'import', 'warehouse_id' => $warehouse->id]);
        $this->confirmBusinessFile($owner, $import);

        $product = Product::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(2, $product->variants()->count());
        foreach (['MIC-BLK' => '8.0000', 'MIC-WHT' => '3.0000'] as $sku => $quantity) {
            $variant = $product->variants()->where('sku', $sku)->firstOrFail();
            $this->assertDatabaseHas('inventory_movements', [
                'organization_id' => $organization->id,
                'warehouse_id' => $warehouse->id,
                'product_variant_id' => $variant->id,
                'movement_type' => 'opening',
                'quantity' => $quantity,
            ]);
            $this->assertDatabaseHas('inventory_balances', [
                'organization_id' => $organization->id,
                'warehouse_id' => $warehouse->id,
                'product_variant_id' => $variant->id,
                'on_hand' => $quantity,
            ]);
        }
        $this->assertDatabaseHas('product_imports', [
            'id' => $import->id,
            'created_product_count' => 1,
            'created_variant_count' => 2,
            'initialized_stock_count' => 2,
        ]);
    }

    public function test_external_stock_status_is_metadata_and_does_not_override_inventory_truth(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization);
        $import = $this->stageBusinessFile($owner, '5001,,Metadata Only,,META-1,15.00,,instock,0,Brand');

        $this->previewBusinessFile($owner, $import, ['stock_mode' => 'import', 'warehouse_id' => $warehouse->id]);
        $this->confirmBusinessFile($owner, $import);

        $product = Product::query()->where('organization_id', $organization->id)->firstOrFail();
        $variant = $product->variants()->firstOrFail();
        $this->assertDatabaseHas('product_channel_identifiers', [
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'external_product_id' => '5001',
            'external_stock_status' => 'instock',
        ]);
        $this->assertDatabaseMissing('inventory_movements', ['product_variant_id' => $variant->id]);
        $this->assertDatabaseMissing('inventory_balances', ['product_variant_id' => $variant->id]);
    }

    public function test_reimport_skips_existing_external_product_without_duplicating_catalog_or_stock(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization);
        $row = '6001,,Reimport Product,,REIMPORT-1,25.00,,instock,7,Brand';
        $first = $this->stageBusinessFile($owner, $row, 'first.csv');
        $this->previewBusinessFile($owner, $first, ['stock_mode' => 'import', 'warehouse_id' => $warehouse->id]);
        $this->confirmBusinessFile($owner, $first);

        $second = $this->stageBusinessFile($owner, $row, 'second.csv');
        $this->previewBusinessFile($owner, $second, ['stock_mode' => 'import', 'warehouse_id' => $warehouse->id]);
        $this->confirmBusinessFile($owner, $second);

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('product_imports', [
            'id' => $second->id,
            'created_product_count' => 0,
            'initialized_stock_count' => 0,
            'skipped_count' => 1,
        ]);
    }

    public function test_catalog_tables_do_not_gain_direct_stock_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('products', 'stock'));
        $this->assertFalse(Schema::hasColumn('product_variants', 'stock'));
        $this->assertFalse(Schema::hasColumn('products', 'quantity'));
        $this->assertFalse(Schema::hasColumn('product_variants', 'quantity'));
    }
}
