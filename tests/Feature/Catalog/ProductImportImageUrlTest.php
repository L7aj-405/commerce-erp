<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ProductImportCorrectionTestCase;

class ProductImportImageUrlTest extends ProductImportCorrectionTestCase
{
    public function test_valid_http_image_url_is_linked_without_any_server_side_request(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $import = $this->stageBusinessFile($owner, '2001,https://example.com/catalog/product.png,Linked Product,,LINK-1,50.00,,instock,0,Brand');

        $this->previewBusinessFile($owner, $import);
        $this->confirmBusinessFile($owner, $import);

        Http::assertNothingSent();
        $this->assertDatabaseHas('products', [
            'organization_id' => $organization->id,
            'name' => 'Linked Product',
            'image_url' => 'https://example.com/catalog/product.png',
        ]);
        $this->assertDatabaseHas('product_imports', [
            'id' => $import->id,
            'linked_image_url_count' => 1,
            'invalid_or_missing_image_url_count' => 0,
        ]);
    }

    public function test_invalid_image_url_becomes_a_warning_and_does_not_block_catalog_import(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $import = $this->stageBusinessFile($owner, '2002,file:///etc/passwd,Product Without Image,,NO-IMG,60.00,,outofstock,0,Brand');

        $this->previewBusinessFile($owner, $import);
        $this->assertDatabaseHas('product_import_rows', ['product_import_id' => $import->id, 'status' => 'warning']);
        $this->confirmBusinessFile($owner, $import);

        Http::assertNothingSent();
        $product = Product::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertNull($product->image_url);
        $this->assertDatabaseHas('product_imports', [
            'id' => $import->id,
            'created_product_count' => 1,
            'linked_image_url_count' => 0,
            'invalid_or_missing_image_url_count' => 1,
        ]);
    }

    public function test_missing_image_url_is_counted_without_creating_media_storage(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $import = $this->stageBusinessFile($owner, '2003,,No Image,,MISSING-IMG,70.00,,instock,0,Brand');

        $this->previewBusinessFile($owner, $import);
        $this->confirmBusinessFile($owner, $import);

        $this->assertDatabaseHas('products', ['organization_id' => $organization->id, 'image_url' => null]);
        $this->assertDatabaseHas('product_imports', [
            'id' => $import->id,
            'linked_image_url_count' => 0,
            'invalid_or_missing_image_url_count' => 1,
        ]);
        $this->assertFalse(Schema::hasTable('product_media'));
    }

    public function test_excessively_long_image_url_is_not_persisted(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $url = 'https://example.com/'.str_repeat('a', 2050);
        $import = $this->stageBusinessFile($owner, "2004,{$url},Long URL,,LONG-IMG,80.00,,instock,0,Brand");

        $this->previewBusinessFile($owner, $import);
        $this->confirmBusinessFile($owner, $import);

        $this->assertDatabaseHas('products', ['organization_id' => $organization->id, 'image_url' => null]);
        $this->assertDatabaseHas('product_imports', [
            'id' => $import->id,
            'linked_image_url_count' => 0,
            'invalid_or_missing_image_url_count' => 1,
        ]);
    }

    public function test_manual_product_image_url_also_requires_http_or_https(): void
    {
        $owner = User::factory()->create();
        $this->createOrganization($owner);

        $this->actingAs($owner)->post(route('catalog.products.store'), $this->productPayload([
            'image_url' => 'file:///tmp/product.png',
        ]))->assertSessionHasErrors('image_url');

        $this->assertDatabaseCount('products', 0);
    }
}
