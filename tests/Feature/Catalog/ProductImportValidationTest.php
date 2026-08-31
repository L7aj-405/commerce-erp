<?php

namespace Tests\Feature\Catalog;

use App\Models\ProductImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\Support\CatalogTestCase;

class ProductImportValidationTest extends CatalogTestCase
{
    public function test_common_headers_are_suggested_and_sku_mapping_is_optional_when_another_identifier_exists(): void
    {
        $owner = User::factory()->create();
        $this->createOrganization($owner);
        $import = $this->stage($owner, "product_name,external_id,regular_price,ean\nProduct,EXT-100,10.00,6111111111111");

        $actual = collect($import->mapping)
    ->only(['name', 'external_product_id', 'sale_price', 'barcode'])
    ->all();

$expected = [
    'name' => 0,
    'external_product_id' => 1,
    'sale_price' => 2,
    'barcode' => 3,
];

ksort($actual);
ksort($expected);

$this->assertSame($expected, $actual);
        $this->actingAs($owner)->put(route('catalog.product-imports.preview', $import), [
            'mapping' => ['name' => 0, 'external_product_id' => 1, 'sku' => null, 'sale_price' => 2],
            'defaults' => [],
        ])->assertRedirect();

        $this->assertDatabaseHas('product_imports', ['id' => $import->id, 'status' => 'previewed']);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_missing_product_name_and_optional_barcode_are_handled_per_row(): void
    {
        $owner = User::factory()->create();
        $this->createOrganization($owner);
        $import = $this->stage($owner, "Name,SKU,Price,Barcode\n,NO-NAME,10.00,1234567890123\nValid,HAS-BARCODE,11.00,6111111111111");

        $this->preview($owner, $import, ['name' => 0, 'sku' => 1, 'sale_price' => 2, 'barcode' => 3]);

        $this->assertDatabaseHas('product_imports', ['id' => $import->id, 'error_count' => 1, 'ready_count' => 1]);
        $this->actingAs($owner)->post(route('catalog.product-imports.confirm', $import))->assertRedirect();
        $this->assertDatabaseHas('product_variants', ['sku' => 'HAS-BARCODE', 'barcode' => '6111111111111']);
        $this->assertDatabaseMissing('product_variants', ['sku' => 'NO-NAME']);
    }

    public function test_non_spreadsheet_content_renamed_xlsx_is_rejected(): void
    {
        $owner = User::factory()->create();
        $this->createOrganization($owner);

        $this->actingAs($owner)->post(route('catalog.product-imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('not-really.xlsx', 'plain text'),
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('product_imports', 0);
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
