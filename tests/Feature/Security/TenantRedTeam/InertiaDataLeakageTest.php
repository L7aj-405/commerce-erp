<?php

namespace Tests\Feature\Security\TenantRedTeam;

class InertiaDataLeakageTest extends TenantRedTeamTestCase
{
    public function test_product_index_props_contain_only_active_organization_data(): void
    {
        $response = $this->actingAs($this->userA)
            ->withHeader('X-Inertia', 'true')
            ->get(route('catalog.products.index'))
            ->assertOk()
            ->assertJsonCount(1, 'props.products.data')
            ->assertJsonPath('props.products.data.0.id', $this->productA->id)
            ->assertJsonCount(1, 'props.brands')
            ->assertJsonPath('props.brands.0.id', $this->brandA->id)
            ->assertJsonCount(1, 'props.categories')
            ->assertJsonPath('props.categories.0.id', $this->categoryA->id);

        $this->assertResponseDoesNotContain($response, $this->foreignCatalogSecrets());
    }

    public function test_product_form_dropdowns_exclude_foreign_and_inactive_reference_data(): void
    {
        $inactiveBrand = $this->createBrand($this->organizationA, 'Inactive A Brand');
        $inactiveBrand->status = 'inactive';
        $inactiveBrand->save();
        $inactiveCategory = $this->createCategory($this->organizationA, 'Inactive A Category');
        $inactiveCategory->status = 'inactive';
        $inactiveCategory->save();
        $inactiveUnit = $this->createUnit($this->organizationA, 'inactive-a');
        $inactiveUnit->name = 'Inactive A Unit';
        $inactiveUnit->status = 'inactive';
        $inactiveUnit->save();
        $inactiveTax = $this->createTaxRate($this->organizationA, 'Inactive A Tax');
        $inactiveTax->status = 'inactive';
        $inactiveTax->save();

        $response = $this->actingAs($this->userA)
            ->withHeader('X-Inertia', 'true')
            ->get(route('catalog.products.create'))
            ->assertOk()
            ->assertJsonCount(1, 'props.brands')
            ->assertJsonPath('props.brands.0.id', $this->brandA->id)
            ->assertJsonCount(1, 'props.categories')
            ->assertJsonPath('props.categories.0.id', $this->categoryA->id)
            ->assertJsonCount(1, 'props.units')
            ->assertJsonPath('props.units.0.id', $this->unitA->id)
            ->assertJsonCount(1, 'props.taxRates')
            ->assertJsonPath('props.taxRates.0.id', $this->taxA->id);

        $this->assertResponseDoesNotContain($response, [
            ...$this->foreignCatalogSecrets(),
            'Inactive A Brand', 'Inactive A Category', 'Inactive A Unit', 'Inactive A Tax',
        ]);
    }

    public function test_reference_data_pages_do_not_serialize_other_tenants(): void
    {
        foreach ([
            [route('catalog.brands.index'), $this->brandA->id],
            [route('catalog.categories.index'), $this->categoryA->id],
            [route('catalog.units.index'), $this->unitA->id],
            [route('catalog.tax-rates.index'), $this->taxA->id],
        ] as [$route, $expectedId]) {
            $response = $this->actingAs($this->userA)
                ->withHeader('X-Inertia', 'true')
                ->get($route)
                ->assertOk()
                ->assertJsonCount(1, 'props.records')
                ->assertJsonPath('props.records.0.id', $expectedId);

            $this->assertResponseDoesNotContain($response, $this->foreignCatalogSecrets());
        }
    }

    public function test_platform_props_include_accessible_organization_switches_but_no_unrelated_tenant_data(): void
    {
        $response = $this->actingAs($this->userA)
            ->withHeader('X-Inertia', 'true')
            ->get(route('platform.index'))
            ->assertOk()
            ->assertJsonCount(2, 'props.organizations')
            ->assertJsonPath('props.organizations.0.id', $this->organizationA->id)
            ->assertJsonPath('props.organizations.1.id', $this->organizationB->id)
            ->assertJsonCount(1, 'props.stores')
            ->assertJsonPath('props.stores.0.id', $this->storeA->id)
            ->assertJsonPath('props.tenant.organization.id', $this->organizationA->id)
            ->assertJsonPath('props.tenant.store.id', $this->storeA->id);

        $this->assertResponseDoesNotContain($response, [
            'RedTeam Organization C', 'RedTeam Store B', 'RedTeam Store C',
            'RED-B', 'RED-C', 'product.created', 'product_variant.created',
        ]);
    }

    /** @return list<string> */
    private function foreignCatalogSecrets(): array
    {
        return [
            'RedTeam Product B', 'RedTeam Brand B', 'RedTeam Category B', 'RedTeam Unit B', 'RedTeam Tax B',
            'RED-SKU-B', 'RED-REF-B', '2000000000002',
            'RedTeam Product C', 'RedTeam Brand C', 'RedTeam Category C', 'RedTeam Unit C', 'RedTeam Tax C',
            'RED-SKU-C', 'RED-REF-C', '3000000000003',
        ];
    }
}
