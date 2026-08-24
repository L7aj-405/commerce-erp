<?php

namespace Tests\Feature\Security\TenantRedTeam;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\TaxRate;
use App\Models\UnitOfMeasure;

class CatalogCrossTenantMutationTest extends TenantRedTeamTestCase
{
    public function test_forged_organization_ids_cannot_override_server_derived_tenant_on_creation(): void
    {
        $this->actingAs($this->userA)->post(route('catalog.brands.store'), [
            'name' => 'Forged Brand', 'slug' => 'forged-brand', 'status' => 'active',
            'organization_id' => $this->organizationC->id, 'owner_id' => $this->userB->id,
        ])->assertRedirect();
        $this->actingAs($this->userA)->post(route('catalog.categories.store'), [
            'name' => 'Forged Category', 'slug' => 'forged-category', 'parent_id' => null, 'status' => 'active',
            'organization_id' => $this->organizationC->id,
        ])->assertRedirect();
        $this->actingAs($this->userA)->post(route('catalog.units.store'), [
            'name' => 'Forged Unit', 'symbol' => 'forged-unit', 'status' => 'active',
            'organization_id' => $this->organizationC->id,
        ])->assertRedirect();
        $this->actingAs($this->userA)->post(route('catalog.tax-rates.store'), [
            'name' => 'Forged Tax', 'rate' => '5.0000', 'status' => 'active',
            'organization_id' => $this->organizationC->id,
        ])->assertRedirect();
        $this->actingAs($this->userA)->post(route('stores.store'), [
            'name' => 'Forged Store', 'code' => 'FORGED-STORE',
            'organization_id' => $this->organizationC->id, 'owner_id' => $this->userB->id,
        ])->assertRedirect();

        foreach ([Brand::class, Category::class, UnitOfMeasure::class, TaxRate::class, Store::class] as $model) {
            $this->assertSame(1, $model::query()->where('organization_id', $this->organizationA->id)->where('name', 'like', 'Forged%')->count());
            $this->assertSame(0, $model::query()->whereIn('organization_id', [$this->organizationB->id, $this->organizationC->id])->where('name', 'like', 'Forged%')->count());
        }
    }

    public function test_product_creation_ignores_privileged_relationship_and_tenant_fields(): void
    {
        $payload = $this->productPayload([
            'name' => 'Server Scoped Product',
            'brand_id' => $this->brandA->id,
            'category_id' => $this->categoryA->id,
            'unit_id' => $this->unitA->id,
            'organization_id' => $this->organizationC->id,
            'product_id' => $this->productC->id,
            'user_id' => $this->userB->id,
            'owner_id' => $this->userB->id,
            'role_id' => 999999,
            'permission_id' => 999999,
            'membership_id' => 999999,
            'store_id' => $this->storeC->id,
            'variant' => [
                'sku' => 'SERVER-SCOPED-SKU',
                'tax_rate_id' => $this->taxA->id,
                'organization_id' => $this->organizationC->id,
                'product_id' => $this->productC->id,
                'store_id' => $this->storeC->id,
            ],
        ]);

        $this->actingAs($this->userA)
            ->post(route('catalog.products.store'), $payload)
            ->assertRedirect();

        $product = Product::query()->where('name', 'Server Scoped Product')->firstOrFail();
        $variant = ProductVariant::query()->where('sku', 'SERVER-SCOPED-SKU')->firstOrFail();

        $this->assertSame($this->organizationA->id, $product->organization_id);
        $this->assertSame($this->organizationA->id, $variant->organization_id);
        $this->assertSame($product->id, $variant->product_id);
        $this->assertSame($this->brandA->id, $product->brand_id);
        $this->assertSame($this->taxA->id, $variant->tax_rate_id);
        $this->assertSame($this->storeA->id, $this->userA->fresh()->active_store_id);
    }

    public function test_product_update_cannot_overwrite_ownership_or_privileged_identifiers(): void
    {
        $this->actingAs($this->userA)->patch(route('catalog.products.update', $this->productA), [
            'name' => 'Safely Updated Product A',
            'description' => null,
            'brand_id' => $this->brandA->id,
            'category_id' => $this->categoryA->id,
            'unit_id' => $this->unitA->id,
            'status' => 'active',
            'organization_id' => $this->organizationC->id,
            'product_id' => $this->productC->id,
            'user_id' => $this->userB->id,
            'owner_id' => $this->userB->id,
            'role_id' => 999999,
            'permission_id' => 999999,
            'membership_id' => 999999,
            'store_id' => $this->storeC->id,
        ])->assertRedirect();

        $this->productA->refresh();
        $this->assertSame($this->organizationA->id, $this->productA->organization_id);
        $this->assertSame('Safely Updated Product A', $this->productA->name);
        $this->assertSame($this->brandA->id, $this->productA->brand_id);
        $this->assertDatabaseHas('product_variants', [
            'id' => $this->variantA->id,
            'organization_id' => $this->organizationA->id,
            'product_id' => $this->productA->id,
        ]);
    }

    public function test_variant_creation_uses_route_product_and_ignores_forged_tenant_fields(): void
    {
        $payload = $this->productPayload()['variant'];
        $payload['sku'] = 'ROUTE-PRODUCT-WINS';
        $payload['tax_rate_id'] = $this->taxA->id;
        $payload['organization_id'] = $this->organizationB->id;
        $payload['product_id'] = $this->productB->id;
        $payload['store_id'] = $this->storeB->id;
        $payload['status'] = 'active';

        $this->actingAs($this->userA)
            ->post(route('catalog.variants.store', $this->productA), $payload)
            ->assertRedirect();

        $variant = ProductVariant::query()->where('sku', 'ROUTE-PRODUCT-WINS')->firstOrFail();
        $this->assertSame($this->organizationA->id, $variant->organization_id);
        $this->assertSame($this->productA->id, $variant->product_id);
        $this->assertSame($this->taxA->id, $variant->tax_rate_id);
    }
}
