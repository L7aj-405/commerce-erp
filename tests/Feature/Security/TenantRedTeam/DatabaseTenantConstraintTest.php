<?php

namespace Tests\Feature\Security\TenantRedTeam;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreMembership;
use Illuminate\Database\QueryException;

class DatabaseTenantConstraintTest extends TenantRedTeamTestCase
{
    public function test_database_rejects_cross_tenant_product_reference_relationships(): void
    {
        foreach ([
            ['brand_id' => $this->brandB->id],
            ['default_category_id' => $this->categoryB->id],
            ['default_unit_id' => $this->unitB->id],
        ] as $foreignRelationship) {
            $this->assertDatabaseConstraintViolation(function () use ($foreignRelationship): void {
                $product = new Product;
                $product->organization_id = $this->organizationA->id;
                $product->name = 'Constraint Attack Product';
                $product->status = 'active';

                foreach ($foreignRelationship as $field => $value) {
                    $product->{$field} = $value;
                }

                $product->save();
            });
        }

        $this->assertDatabaseMissing('products', ['name' => 'Constraint Attack Product']);
    }

    public function test_database_rejects_cross_tenant_variant_category_and_store_membership_relationships(): void
    {
        $this->assertDatabaseConstraintViolation(function (): void {
            $variant = new ProductVariant;
            $variant->organization_id = $this->organizationA->id;
            $variant->product_id = $this->productB->id;
            $variant->sku = 'BAD-PRODUCT-RELATION';
            $variant->default_sale_price = '1.0000';
            $variant->status = 'active';
            $variant->save();
        });

        $this->assertDatabaseConstraintViolation(function (): void {
            $variant = new ProductVariant;
            $variant->organization_id = $this->organizationA->id;
            $variant->product_id = $this->productA->id;
            $variant->tax_rate_id = $this->taxB->id;
            $variant->sku = 'BAD-TAX-RELATION';
            $variant->default_sale_price = '1.0000';
            $variant->status = 'active';
            $variant->save();
        });

        $this->assertDatabaseConstraintViolation(function (): void {
            $category = new Category;
            $category->organization_id = $this->organizationA->id;
            $category->parent_id = $this->categoryB->id;
            $category->name = 'Bad Cross Tenant Parent';
            $category->slug = 'bad-cross-tenant-parent';
            $category->status = 'active';
            $category->save();
        });

        $this->assertDatabaseConstraintViolation(function (): void {
            $membership = new StoreMembership;
            $membership->organization_id = $this->organizationA->id;
            $membership->store_id = $this->storeB->id;
            $membership->user_id = $this->userA->id;
            $membership->save();
        });

        $this->assertDatabaseConstraintViolation(function (): void {
            $membership = new StoreMembership;
            $membership->organization_id = $this->organizationA->id;
            $membership->store_id = $this->storeA->id;
            $membership->user_id = $this->userB->id;
            $membership->save();
        });
    }

    public function test_sku_and_barcode_are_tenant_unique_while_reference_matches_schema_behavior(): void
    {
        $this->createProduct($this->organizationA, 'Shared Identifier A', 'SH-SM58', [
            'barcode' => '9999999999999',
            'reference' => 'NON-UNIQUE-REFERENCE',
        ]);
        $this->createProduct($this->organizationB, 'Shared Identifier B', 'SH-SM58', [
            'barcode' => '9999999999999',
            'reference' => 'NON-UNIQUE-REFERENCE',
        ]);

        $this->actingAs($this->userA)
            ->postJson(route('catalog.products.store'), $this->productPayload([
                'name' => 'Duplicate SKU A',
                'variant' => ['sku' => 'SH-SM58', 'barcode' => '9999999999998'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variant.sku');
        $this->actingAs($this->userA)
            ->postJson(route('catalog.products.store'), $this->productPayload([
                'name' => 'Duplicate Barcode A',
                'variant' => ['sku' => 'UNIQUE-SKU-A', 'barcode' => '9999999999999'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variant.barcode');

        $this->createProduct($this->organizationA, 'Repeated Reference A', 'REFERENCE-OK', [
            'reference' => 'NON-UNIQUE-REFERENCE',
        ]);

        $this->assertSame(3, ProductVariant::query()->where('reference', 'NON-UNIQUE-REFERENCE')->count());
        $this->assertDatabaseMissing('products', ['name' => 'Duplicate SKU A']);
        $this->assertDatabaseMissing('products', ['name' => 'Duplicate Barcode A']);
    }

    private function assertDatabaseConstraintViolation(callable $attack): void
    {
        try {
            $attack();
            $this->fail('The database accepted a cross-tenant relationship.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
