<?php

namespace Tests\Feature\Catalog;

use App\Models\ProductCostImport;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\InventoryTestCase;

class ProductCostManagementTest extends InventoryTestCase
{
    public function test_cost_page_requires_its_own_permission_and_does_not_follow_product_visibility(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $member, ['products.view', 'products.update']);
        $this->activate($member, $organization);
        $product = $this->createProduct($organization, 'Confidential cost', 'PRIVATE-COST', ['purchase_price' => '9876.5432']);

        $this->actingAs($member)->get(route('catalog.product-costs.index'))->assertForbidden();
        $this->actingAs($member)->get(route('catalog.products.show', $product))->assertOk()->assertDontSee('9876.5432');
        $this->actingAs($member)->get(route('catalog.products.edit', $product))->assertOk()->assertDontSee('9876.5432');
        $this->actingAs($owner)->get(route('catalog.product-costs.index'))->assertOk();
    }

    public function test_default_filters_show_only_in_stock_variants_missing_a_cost(): void
    {
        [$owner, $organization, $warehouse] = $this->context();
        $missing = $this->createProduct($organization, 'Missing cost', 'MISS')->variants->firstOrFail();
        $priced = $this->createProduct($organization, 'Priced', 'PRICE', ['purchase_price' => '15.0000'])->variants->firstOrFail();
        $out = $this->createProduct($organization, 'No stock', 'OUT')->variants->firstOrFail();
        $this->openStock($owner, $organization, $warehouse, $missing, '5');
        $this->openStock($owner, $organization, $warehouse, $priced, '2');

        $this->actingAs($owner)->get(route('catalog.product-costs.index'))->assertInertia(fn (Assert $page) => $page
            ->where('filters.stock', 'in_stock')->where('filters.cost', 'missing')
            ->has('variants.data', 1)->where('variants.data.0.id', $missing->id)
            ->where('coverage.in_stock', 2)->where('coverage.with_cost', 1)->where('coverage.missing_cost', 1));
        $this->assertNotEquals($out->id, $missing->id);
    }

    public function test_brand_category_and_stock_filters_are_server_side(): void
    {
        [$owner, $organization, $warehouse] = $this->context();
        $brand = $this->createBrand($organization, 'Target brand');
        $category = $this->createCategory($organization, 'Target category');
        $target = $this->createProduct($organization, 'Target', 'TARGET', ['brand_id' => $brand->id, 'category_id' => $category->id])->variants->firstOrFail();
        $this->createProduct($organization, 'Other', 'OTHER');
        $this->openStock($owner, $organization, $warehouse, $target, '3');

        $this->actingAs($owner)->get(route('catalog.product-costs.index', ['stock' => 'in_stock', 'cost' => 'all', 'brand' => $brand->id, 'category' => $category->id]))
            ->assertInertia(fn (Assert $page) => $page->has('variants.data', 1)->where('variants.data.0.id', $target->id));
        $this->actingAs($owner)->get(route('catalog.product-costs.index', ['stock' => 'out_of_stock', 'cost' => 'all']))
            ->assertInertia(fn (Assert $page) => $page->where('variants.total', 1));
    }

    public function test_export_contains_only_filtered_variants_and_authoritative_ids(): void
    {
        [$owner, $organization, $warehouse] = $this->context();
        $target = $this->createProduct($organization, 'Export me', 'EXPORT')->variants->firstOrFail();
        $this->createProduct($organization, 'Exclude me', 'EXCLUDE', ['purchase_price' => '20.0000']);
        $this->openStock($owner, $organization, $warehouse, $target, '4');

        $response = $this->actingAs($owner)->get(route('catalog.product-costs.export', ['stock' => 'in_stock', 'cost' => 'missing']));
        $response->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $reader = new Reader;
        $reader->open($path);
        $values = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values[] = array_map(fn ($cell) => $cell->getValue(), $row->getCells());
            }
        }
        $reader->close();

        $this->assertSame('Internal Variant ID', $values[0][0]);
        $this->assertEquals($target->id, $values[1][0]);
        $this->assertEquals($target->product_id, $values[1][1]);
        $this->assertCount(2, $values);
        $this->assertDatabaseHas('audit_logs', ['event' => 'product_cost.exported', 'organization_id' => $organization->id]);
    }

    public function test_preview_classifies_valid_blank_invalid_unknown_foreign_and_duplicate_rows(): void
    {
        [$owner, $organization] = $this->context(false);
        $variant = $this->createProduct($organization, 'Local', 'LOCAL', ['purchase_price' => '10.0000'])->variants->firstOrFail();
        $blank = $this->createProduct($organization, 'Blank', 'BLANK', ['purchase_price' => '8.0000'])->variants->firstOrFail();
        $otherOwner = User::factory()->create();
        $other = $this->createOrganization($otherOwner);
        $foreign = $this->createProduct($other, 'Foreign', 'FOREIGN')->variants->firstOrFail();
        $csv = $this->headers()."\n"
            .$this->line($variant->id, $variant->product_id, 'Local', 'LOCAL', '20.0000')."\n"
            .$this->line($variant->id, $variant->product_id, 'Local', 'LOCAL', '20.0000')."\n"
            .$this->line(999999, 999999, 'Unknown', 'UNKNOWN', '4.0000')."\n"
            .$this->line($foreign->id, $foreign->product_id, 'Foreign', 'FOREIGN', '7.0000')."\n"
            .$this->line('', '', 'Bad', 'BAD', '-2')."\n"
            .$this->line($blank->id, $blank->product_id, 'Blank', 'BLANK', '');

        $import = $this->stage($owner, $csv);
        $this->assertSame(2, $import->duplicate_count);
        $this->assertSame(2, $import->not_found_count);
        $this->assertSame(1, $import->invalid_count);
        $this->assertSame(1, $import->unchanged_count);
        $this->assertSame(0, $import->ready_count);
        $this->assertDatabaseHas('product_cost_import_rows', ['product_cost_import_id' => $import->id, 'status' => 'duplicate']);
        $this->assertSame('10.0000', $variant->fresh()->purchase_price);
    }

    public function test_valid_confirmation_changes_only_purchase_price_and_is_audited(): void
    {
        [$owner, $organization, $warehouse] = $this->context();
        $tax = $this->createTaxRate($organization);
        $variant = $this->createProduct($organization, 'Safe update', 'SAFE', [
            'reference' => 'REF-SAFE', 'barcode' => '6111111111111', 'purchase_price' => '10.0000',
            'default_sale_price' => '150.0000', 'tax_rate_id' => $tax->id,
        ])->variants->firstOrFail();
        $this->openStock($owner, $organization, $warehouse, $variant, '8');
        $before = $variant->only(['default_sale_price', 'tax_rate_id', 'sku', 'reference', 'barcode']);
        $import = $this->stage($owner, $this->headers()."\n".$this->line($variant->id, $variant->product_id, 'Safe update', 'SAFE', '42.1250'));

        $this->actingAs($owner)->post(route('catalog.product-costs.imports.confirm', $import))->assertRedirect();

        $variant->refresh();
        $this->assertSame('42.1250', $variant->purchase_price);
        $this->assertSame($before, $variant->only(array_keys($before)));
        $this->assertSame('8.0000', $this->balance($organization, $warehouse, $variant)->on_hand);
        $this->assertDatabaseHas('product_cost_imports', ['id' => $import->id, 'status' => 'completed', 'updated_count' => 1]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'product_cost.import_confirmed', 'organization_id' => $organization->id]);
    }

    public function test_manual_update_is_tenant_scoped_and_changes_only_cost(): void
    {
        $ownerA = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA);
        $ownerB = User::factory()->create();
        $organizationB = $this->createOrganization($ownerB);
        $variantB = $this->createProduct($organizationB, 'B', 'B-SKU', ['purchase_price' => '1.0000'])->variants->firstOrFail();

        $this->actingAs($ownerA)->patch(route('catalog.product-costs.update', $variantB->id), ['purchase_price' => '9.0000'])->assertNotFound();
        $this->assertSame('1.0000', $variantB->fresh()->purchase_price);
        $this->assertNotEquals($organizationA->id, $organizationB->id);
    }

    public function test_custom_roles_are_not_granted_cost_permissions_implicitly(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $member, ['products.view']);

        $this->assertFalse($member->hasPermission($organization, 'product_cost.view'));
        $this->assertTrue($owner->hasPermission($organization, 'product_cost.import'));
    }

    public function test_a_large_preview_is_staged_in_bounded_batches_without_catalog_mutation(): void
    {
        [$owner, $organization] = $this->context(false);
        $lines = [];
        for ($index = 1; $index <= 500; $index++) {
            $variant = $this->createProduct($organization, "Bulk {$index}", "BULK-{$index}")->variants->firstOrFail();
            $lines[] = $this->line($variant->id, $variant->product_id, "Bulk {$index}", "BULK-{$index}", '12.5000');
        }

        $import = $this->stage($owner, $this->headers()."\n".implode("\n", $lines));

        $this->assertSame(500, $import->total_rows);
        $this->assertSame(500, $import->ready_count);
        $this->assertDatabaseCount('product_cost_import_rows', 500);
        $this->assertSame(0, ProductVariant::query()->where('organization_id', $organization->id)->whereNotNull('purchase_price')->count());
    }

    /** @return array{User, \App\Models\Organization, \App\Models\Warehouse}|array{User, \App\Models\Organization} */
    private function context(bool $warehouse = true): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        return $warehouse ? [$owner, $organization, $this->createWarehouse($organization)] : [$owner, $organization];
    }

    private function stage(User $owner, string $csv): ProductCostImport
    {
        $this->actingAs($owner)->post(route('catalog.product-costs.imports.store'), [
            'file' => UploadedFile::fake()->createWithContent('purchase-prices.csv', $csv),
        ])->assertRedirect();

        return ProductCostImport::query()->latest('id')->firstOrFail();
    }

    private function headers(): string
    {
        return 'Internal Variant ID,Internal Product ID,Product,Variant,SKU,Reference,Barcode,Brand,Category,Stock,Current Purchase Price HT,New Purchase Price HT';
    }

    private function line(int|string $variant, int|string $product, string $name, string $sku, string $price): string
    {
        return implode(',', [$variant, $product, $name, 'Main', $sku, '', '', '', '', '1', '', $price]);
    }
}
