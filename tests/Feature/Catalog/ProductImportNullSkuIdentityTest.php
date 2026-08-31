<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Models\ProductChannelIdentifier;
use App\Models\ProductImport;
use App\Models\ProductImportRow;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\Support\CatalogTestCase;

class ProductImportNullSkuIdentityTest extends CatalogTestCase
{
    public function test_preview_normalizes_blank_identifiers_to_null_and_requires_a_real_identifier(): void
    {
        $owner = User::factory()->create();
        $this->createOrganization($owner);
        $import = $this->stage($owner, "Name,External ID,SKU,Reference,Price\nLinked Product,WC-1001,   ,   ,10.00\nMissing Identity,   ,   ,   ,11.00");

        $this->preview($owner, $import, ['name' => 0, 'external_product_id' => 1, 'sku' => 2, 'reference' => 3, 'sale_price' => 4]);

        $linkedRow = ProductImportRow::query()->where('product_import_id', $import->id)->where('row_number', 2)->firstOrFail();
        $missingRow = ProductImportRow::query()->where('product_import_id', $import->id)->where('row_number', 3)->firstOrFail();

        $this->assertSame('ready', $linkedRow->status);
        $this->assertSame('WC-1001', data_get($linkedRow->normalized_data, 'external_product_id'));
        $this->assertNull(data_get($linkedRow->normalized_data, 'sku'));
        $this->assertNull(data_get($linkedRow->normalized_data, 'reference'));

        $this->assertSame('error', $missingRow->status);
        $this->assertSame('missing_product_identifier', $missingRow->error_code);
        $this->assertNull(data_get($missingRow->normalized_data, 'external_product_id'));
        $this->assertNull(data_get($missingRow->normalized_data, 'sku'));
        $this->assertNull(data_get($missingRow->normalized_data, 'reference'));
    }

    public function test_confirm_imports_distinct_external_ids_with_null_skus_without_generating_fake_skus(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $import = $this->stage($owner, "Name,External ID,SKU,Reference,Price\nMic A,WC-2001,   ,   ,10.00\nMic B,WC-2002,, ,11.00");

        $this->preview($owner, $import, ['name' => 0, 'external_product_id' => 1, 'sku' => 2, 'reference' => 3, 'sale_price' => 4]);
        $this->actingAs($owner)->post(route('catalog.product-imports.confirm', $import))->assertRedirect();

        $this->assertSame(2, Product::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(2, ProductVariant::query()->where('organization_id', $organization->id)->whereNull('sku')->count());
        $this->assertSame(0, ProductVariant::query()->where('organization_id', $organization->id)->whereNotNull('sku')->count());
        $this->assertDatabaseHas('product_channel_identifiers', ['organization_id' => $organization->id, 'source' => 'woocommerce', 'external_product_id' => 'WC-2001']);
        $this->assertDatabaseHas('product_channel_identifiers', ['organization_id' => $organization->id, 'source' => 'woocommerce', 'external_product_id' => 'WC-2002']);
    }

    public function test_existing_null_sku_and_null_reference_records_do_not_block_new_external_identity(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);

        $existing = $this->createProduct($organization, 'Existing Null Identity Holder', 'KEEP-ME');
        $existingVariant = $existing->variants()->firstOrFail();
        $existingVariant->sku = null;
        $existingVariant->reference = null;
        $existingVariant->barcode = null;
        $existingVariant->save();

        $import = $this->stage($owner, "Name,External ID,SKU,Reference,Price\nNew Product,WC-3001,   ,   ,12.50");

        $this->preview($owner, $import, ['name' => 0, 'external_product_id' => 1, 'sku' => 2, 'reference' => 3, 'sale_price' => 4]);

        $row = ProductImportRow::query()->where('product_import_id', $import->id)->where('row_number', 2)->firstOrFail();
        $this->assertSame('ready', $row->status);

        $this->actingAs($owner)->post(route('catalog.product-imports.confirm', $import))->assertRedirect();

        $this->assertSame(2, Product::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(2, ProductVariant::query()->where('organization_id', $organization->id)->whereNull('sku')->count());
        $this->assertDatabaseHas('product_channel_identifiers', ['organization_id' => $organization->id, 'source' => 'woocommerce', 'external_product_id' => 'WC-3001']);
    }

    public function test_non_empty_sku_still_matches_duplicates_but_null_sku_rows_reimport_by_external_id(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->createProduct($organization, 'Existing SKU Product', 'DUP-100');

        $duplicateSkuImport = $this->stage($owner, "Name,External ID,SKU,Price\nDuplicate SKU,WC-4001,DUP-100,10.00");
        $this->preview($owner, $duplicateSkuImport, ['name' => 0, 'external_product_id' => 1, 'sku' => 2, 'sale_price' => 3]);

        $duplicateRow = ProductImportRow::query()->where('product_import_id', $duplicateSkuImport->id)->where('row_number', 2)->firstOrFail();
        $this->assertSame('skipped', $duplicateRow->status);

        $csv = "Name,External ID,SKU,Reference,Price\nNo SKU A,WC-5001,   ,   ,10.00\nNo SKU B,WC-5002,   ,   ,11.00";

        $first = $this->stage($owner, $csv);
        $this->preview($owner, $first, ['name' => 0, 'external_product_id' => 1, 'sku' => 2, 'reference' => 3, 'sale_price' => 4]);
        $this->actingAs($owner)->post(route('catalog.product-imports.confirm', $first))->assertRedirect();

        $second = $this->stage($owner, $csv);
        $this->preview($owner, $second, ['name' => 0, 'external_product_id' => 1, 'sku' => 2, 'reference' => 3, 'sale_price' => 4]);

        $statuses = ProductImportRow::query()->where('product_import_id', $second->id)->orderBy('row_number')->pluck('status')->all();
        $this->assertSame(['skipped', 'skipped'], $statuses);

        $this->actingAs($owner)->post(route('catalog.product-imports.confirm', $second))->assertRedirect();

        $this->assertSame(3, Product::query()->where('organization_id', $organization->id)->count());
        $this->assertDatabaseHas('product_imports', ['id' => $second->id, 'imported_count' => 0, 'skipped_count' => 2]);
    }

    public function test_external_ids_with_null_skus_remain_scoped_to_their_own_organization_and_source(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA);
        $organizationB = $this->createOrganization($ownerB);

        $csv = "Name,External ID,SKU,Reference,Price\nShared External,WC-9001,   ,   ,15.00";

        $importA = $this->stage($ownerA, $csv);
        $this->preview($ownerA, $importA, ['name' => 0, 'external_product_id' => 1, 'sku' => 2, 'reference' => 3, 'sale_price' => 4]);
        $this->actingAs($ownerA)->post(route('catalog.product-imports.confirm', $importA))->assertRedirect();

        $importB = $this->stage($ownerB, $csv);
        $this->preview($ownerB, $importB, ['name' => 0, 'external_product_id' => 1, 'sku' => 2, 'reference' => 3, 'sale_price' => 4]);
        $this->actingAs($ownerB)->post(route('catalog.product-imports.confirm', $importB))->assertRedirect();

        $this->assertDatabaseHas('product_channel_identifiers', ['organization_id' => $organizationA->id, 'source' => 'woocommerce', 'external_product_id' => 'WC-9001']);
        $this->assertDatabaseHas('product_channel_identifiers', ['organization_id' => $organizationB->id, 'source' => 'woocommerce', 'external_product_id' => 'WC-9001']);
        $this->assertSame(1, ProductChannelIdentifier::query()->where('organization_id', $organizationA->id)->where('source', 'woocommerce')->where('external_product_id', 'WC-9001')->count());
        $this->assertSame(1, ProductChannelIdentifier::query()->where('organization_id', $organizationB->id)->where('source', 'woocommerce')->where('external_product_id', 'WC-9001')->count());
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
