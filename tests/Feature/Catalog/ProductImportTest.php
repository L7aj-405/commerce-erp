<?php

namespace Tests\Feature\Catalog;

use App\Models\ProductImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Support\CatalogTestCase;

class ProductImportTest extends CatalogTestCase
{
    public function test_csv_preview_does_not_mutate_catalog_and_confirmation_imports_exact_prices(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $import = $this->stage($owner, "Name,SKU,Regular price,Stock\nMixer,MX-01,1200.50,7\nCable,CB-01,1200,3");

        $this->preview($owner, $import, ['name' => 0, 'sku' => 1, 'sale_price' => 2, 'stock_quantity' => 3]);

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseHas('product_imports', ['id' => $import->id, 'stock_detected' => true, 'status' => 'previewed']);

        $this->actingAs($owner)->post(route('catalog.product-imports.confirm', $import))->assertRedirect();

        $this->assertDatabaseHas('product_variants', ['organization_id' => $organization->id, 'sku' => 'MX-01', 'default_sale_price' => '1200.5000']);
        $this->assertDatabaseHas('product_variants', ['organization_id' => $organization->id, 'sku' => 'CB-01', 'default_sale_price' => '1200.0000']);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'event' => 'catalog.products_imported']);
    }

    public function test_semicolon_csv_reuses_or_creates_tenant_reference_data(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $existingBrand = $this->createBrand($organization, 'Acme Pro');
        $import = $this->stage($owner, "Nom;SKU;Prix;Marque;Catégorie\nMicro;MIC-1;1200,50; Acme   Pro ;Audio");

        $this->preview($owner, $import, ['name' => 0, 'sku' => 1, 'sale_price' => 2, 'brand' => 3, 'category' => 4]);
        $this->actingAs($owner)->post(route('catalog.product-imports.confirm', $import))->assertRedirect();

        $this->assertDatabaseCount('brands', 1);
        $this->assertDatabaseHas('products', ['organization_id' => $organization->id, 'brand_id' => $existingBrand->id]);
        $this->assertDatabaseHas('categories', ['organization_id' => $organization->id, 'name' => 'Audio']);
    }

    public function test_duplicate_identifiers_are_skipped_without_mutation(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->createProduct($organization, 'Existing', 'DUP-1');
        $import = $this->stage($owner, "Name,SKU,Price\nDuplicate,DUP-1,10.00");

        $this->preview($owner, $import, ['name' => 0, 'sku' => 1, 'sale_price' => 2]);
        $this->assertDatabaseHas('product_import_rows', ['product_import_id' => $import->id, 'status' => 'skipped']);
        $this->actingAs($owner)->post(route('catalog.product-imports.confirm', $import))->assertRedirect();

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('product_imports', ['id' => $import->id, 'imported_count' => 0, 'skipped_count' => 1]);
    }

    public function test_invalid_and_ambiguous_prices_are_reported_before_confirmation(): void
    {
        $owner = User::factory()->create();
        $this->createOrganization($owner);
        $import = $this->stage($owner, "Name,SKU,Price\nAmbiguous,AMB-1,\"1,200\"\nInvalid,BAD-1,free");

        $this->preview($owner, $import, ['name' => 0, 'sku' => 1, 'sale_price' => 2]);

        $this->assertDatabaseHas('product_imports', ['id' => $import->id, 'error_count' => 2]);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_woocommerce_variable_rows_create_one_product_with_variants(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $csv = "Name,Type,SKU,Regular price,Parent,Variation\nT-Shirt,variable,TSHIRT,,,\n,variation,TS-RED,25.00,TSHIRT,Red\n,variation,TS-BLUE,27.50,TSHIRT,Blue";
        $import = $this->stage($owner, $csv);

        $this->preview($owner, $import, ['name' => 0, 'product_type' => 1, 'sku' => 2, 'sale_price' => 3, 'parent_sku' => 4, 'variant_label' => 5]);
        $this->actingAs($owner)->post(route('catalog.product-imports.confirm', $import))->assertRedirect();

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 2);
        $this->assertDatabaseHas('products', ['organization_id' => $organization->id, 'name' => 'T-Shirt']);
        $this->assertDatabaseHas('product_variants', ['organization_id' => $organization->id, 'sku' => 'TS-RED', 'label' => 'Red']);
        $this->assertDatabaseHas('product_variants', ['organization_id' => $organization->id, 'sku' => 'TS-BLUE', 'label' => 'Blue']);
    }

    public function test_xlsx_upload_is_staged_without_persisting_the_source_file(): void
    {
        $owner = User::factory()->create();
        $this->createOrganization($owner);
        $path = tempnam(sys_get_temp_dir(), 'catalog-import-');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Name', 'SKU', 'Price']));
        $writer->addRow(Row::fromValues(['Spreadsheet Product', 'XLSX-1', '99.95']));
        $writer->close();

        try {
            $file = new UploadedFile($path, 'products.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
            $this->actingAs($owner)->post(route('catalog.product-imports.store'), ['file' => $file])->assertRedirect();
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseHas('product_imports', ['source_format' => 'xlsx', 'total_rows' => 1]);
        $this->assertDatabaseHas('product_import_rows', ['row_number' => 2, 'status' => 'uploaded']);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_reuploading_the_same_identifiers_does_not_duplicate_catalog_products(): void
    {
        $owner = User::factory()->create();
        $this->createOrganization($owner);
        $csv = "Name,SKU,Price\nIdempotent product,IDEMP-1,19.99";

        $first = $this->stage($owner, $csv);
        $this->preview($owner, $first, ['name' => 0, 'sku' => 1, 'sale_price' => 2]);
        $this->actingAs($owner)->post(route('catalog.product-imports.confirm', $first))->assertRedirect();

        $second = $this->stage($owner, $csv);
        $this->preview($owner, $second, ['name' => 0, 'sku' => 1, 'sale_price' => 2]);
        $this->actingAs($owner)->post(route('catalog.product-imports.confirm', $second))->assertRedirect();

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('product_imports', ['id' => $second->id, 'imported_count' => 0, 'skipped_count' => 1]);
    }

    /** @param array<string, int|null> $mapping */
    private function preview(User $user, ProductImport $import, array $mapping): void
    {
        $this->actingAs($user)->put(route('catalog.product-imports.preview', $import), [
            'mapping' => $mapping,
            'defaults' => [],
        ])->assertRedirect();
    }

    private function stage(User $user, string $contents): ProductImport
    {
        $this->actingAs($user)->post(route('catalog.product-imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('products.csv', $contents),
        ])->assertRedirect();

        return ProductImport::query()->latest('id')->firstOrFail();
    }
}
