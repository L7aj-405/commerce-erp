<?php

namespace Tests\Feature\Security\TenantRedTeam;

use App\Models\Product;

class CatalogRelationshipAttackTest extends TenantRedTeamTestCase
{
    public function test_foreign_brand_category_and_unit_ids_are_rejected_on_product_creation(): void
    {
        $attempts = [
            ['brand_id', $this->brandB->id, 'REL-BRAND-B'],
            ['brand_id', $this->brandC->id, 'REL-BRAND-C'],
            ['category_id', $this->categoryB->id, 'REL-CATEGORY-B'],
            ['category_id', $this->categoryC->id, 'REL-CATEGORY-C'],
            ['unit_id', $this->unitB->id, 'REL-UNIT-B'],
            ['unit_id', $this->unitC->id, 'REL-UNIT-C'],
        ];

        foreach ($attempts as [$field, $foreignId, $sku]) {
            $this->actingAs($this->userA)
                ->postJson(route('catalog.products.store'), $this->productPayload([
                    'name' => "Relationship Attack {$sku}",
                    $field => $foreignId,
                    'variant' => ['sku' => $sku],
                ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertSame(0, Product::query()->where('name', 'like', 'Relationship Attack%')->count());
    }

    public function test_foreign_relationship_ids_are_rejected_on_product_update_without_mutation(): void
    {
        foreach ([
            ['brand_id', $this->brandB->id],
            ['category_id', $this->categoryB->id],
            ['unit_id', $this->unitB->id],
        ] as [$field, $foreignId]) {
            $payload = [
                'name' => 'Attempted Relationship Mutation',
                'description' => null,
                'brand_id' => $this->brandA->id,
                'category_id' => $this->categoryA->id,
                'unit_id' => $this->unitA->id,
                'status' => 'active',
            ];
            $payload[$field] = $foreignId;

            $this->actingAs($this->userA)
                ->patchJson(route('catalog.products.update', $this->productA), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->productA->refresh();
        $this->assertSame('RedTeam Product A', $this->productA->name);
        $this->assertSame($this->brandA->id, $this->productA->brand_id);
        $this->assertSame($this->categoryA->id, $this->productA->default_category_id);
        $this->assertSame($this->unitA->id, $this->productA->default_unit_id);
    }

    public function test_foreign_tax_rate_and_product_ids_are_rejected_for_variants(): void
    {
        foreach ([$this->taxB, $this->taxC] as $foreignTax) {
            $payload = $this->productPayload()['variant'];
            $payload['sku'] = 'FOREIGN-TAX-'.$foreignTax->id;
            $payload['tax_rate_id'] = $foreignTax->id;

            $this->actingAs($this->userA)
                ->postJson(route('catalog.variants.store', $this->productA), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('tax_rate_id');
        }

        $this->actingAs($this->userA)
            ->postJson(route('catalog.variants.store', $this->productB), $this->productPayload([
                'variant' => ['sku' => 'FOREIGN-PRODUCT'],
            ])['variant'])
            ->assertNotFound();

        $this->assertDatabaseMissing('product_variants', ['sku' => 'FOREIGN-PRODUCT']);
    }

    public function test_cross_tenant_and_self_parent_category_attacks_are_rejected_without_name_leakage(): void
    {
        $crossTenantResponse = $this->actingAs($this->userA)
            ->postJson(route('catalog.categories.store'), [
                'name' => 'Cross Tenant Child',
                'slug' => 'cross-tenant-child',
                'parent_id' => $this->categoryB->id,
                'status' => 'active',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->assertResponseDoesNotContain($crossTenantResponse, [
            'RedTeam Category B',
            'RedTeam Organization B',
        ]);

        $this->actingAs($this->userA)
            ->patchJson(route('catalog.categories.update', $this->categoryA), [
                'name' => $this->categoryA->name,
                'slug' => $this->categoryA->slug,
                'parent_id' => $this->categoryA->id,
                'status' => 'active',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_multi_node_category_cycle_is_rejected(): void
    {
        $categoryOne = $this->createCategory($this->organizationA, 'Cycle One');
        $categoryTwo = $this->createCategory($this->organizationA, 'Cycle Two');

        $this->actingAs($this->userA)
            ->patch(route('catalog.categories.update', $categoryOne), [
                'name' => $categoryOne->name,
                'slug' => $categoryOne->slug,
                'parent_id' => $categoryTwo->id,
                'status' => 'active',
            ])
            ->assertRedirect();

        $this->actingAs($this->userA)
            ->patchJson(route('catalog.categories.update', $categoryTwo), [
                'name' => $categoryTwo->name,
                'slug' => $categoryTwo->slug,
                'parent_id' => $categoryOne->id,
                'status' => 'active',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->assertNull($categoryTwo->fresh()->parent_id);
    }

    public function test_deep_descendant_category_cycle_is_rejected(): void
    {
        $root = $this->createCategory($this->organizationA, 'Cycle Root');
        $middle = $this->createCategory($this->organizationA, 'Cycle Middle', $root);
        $leaf = $this->createCategory($this->organizationA, 'Cycle Leaf', $middle);

        $this->actingAs($this->userA)
            ->patchJson(route('catalog.categories.update', $root), [
                'name' => $root->name,
                'slug' => $root->slug,
                'parent_id' => $leaf->id,
                'status' => 'active',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->assertNull($root->fresh()->parent_id);
    }
}
