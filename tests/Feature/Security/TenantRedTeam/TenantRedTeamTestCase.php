<?php

namespace Tests\Feature\Security\TenantRedTeam;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\TaxRate;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Support\CatalogTestCase;

abstract class TenantRedTeamTestCase extends CatalogTestCase
{
    protected User $userA;

    protected User $userB;

    protected Organization $organizationA;

    protected Organization $organizationB;

    protected Organization $organizationC;

    protected Store $storeA;

    protected Store $storeB;

    protected Store $storeC;

    protected Brand $brandA;

    protected Brand $brandB;

    protected Brand $brandC;

    protected Category $categoryA;

    protected Category $categoryB;

    protected Category $categoryC;

    protected UnitOfMeasure $unitA;

    protected UnitOfMeasure $unitB;

    protected UnitOfMeasure $unitC;

    protected TaxRate $taxA;

    protected TaxRate $taxB;

    protected TaxRate $taxC;

    protected Product $productA;

    protected Product $productB;

    protected Product $productC;

    protected ProductVariant $variantA;

    protected ProductVariant $variantB;

    protected ProductVariant $variantC;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create(['email' => 'red-team-a@example.test']);
        $this->userB = User::factory()->create(['email' => 'red-team-b@example.test']);

        $this->organizationA = $this->createOrganization($this->userA, 'RedTeam Organization A');
        $this->organizationB = $this->createOrganization($this->userA, 'RedTeam Organization B');
        $this->organizationC = $this->createOrganization($this->userB, 'RedTeam Organization C');

        $this->storeA = $this->createStore($this->organizationA, $this->userA, 'RedTeam Store A');
        $this->storeA->update(['code' => 'RED-A']);
        $this->storeB = $this->createStore($this->organizationB, $this->userA, 'RedTeam Store B');
        $this->storeB->update(['code' => 'RED-B']);
        $this->storeC = $this->createStore($this->organizationC, $this->userB, 'RedTeam Store C');
        $this->storeC->update(['code' => 'RED-C']);

        $this->brandA = $this->createBrand($this->organizationA, 'RedTeam Brand A');
        $this->brandB = $this->createBrand($this->organizationB, 'RedTeam Brand B');
        $this->brandC = $this->createBrand($this->organizationC, 'RedTeam Brand C');

        $this->categoryA = $this->createCategory($this->organizationA, 'RedTeam Category A');
        $this->categoryB = $this->createCategory($this->organizationB, 'RedTeam Category B');
        $this->categoryC = $this->createCategory($this->organizationC, 'RedTeam Category C');

        $this->unitA = $this->createUnit($this->organizationA, 'red-a');
        $this->unitA->name = 'RedTeam Unit A';
        $this->unitA->save();
        $this->unitB = $this->createUnit($this->organizationB, 'red-b');
        $this->unitB->name = 'RedTeam Unit B';
        $this->unitB->save();
        $this->unitC = $this->createUnit($this->organizationC, 'red-c');
        $this->unitC->name = 'RedTeam Unit C';
        $this->unitC->save();

        $this->taxA = $this->createTaxRate($this->organizationA, 'RedTeam Tax A', '10.0000');
        $this->taxB = $this->createTaxRate($this->organizationB, 'RedTeam Tax B', '20.0000');
        $this->taxC = $this->createTaxRate($this->organizationC, 'RedTeam Tax C', '30.0000');

        $this->productA = $this->createProduct($this->organizationA, 'RedTeam Product A', 'RED-SKU-A', [
            'brand_id' => $this->brandA->id,
            'category_id' => $this->categoryA->id,
            'unit_id' => $this->unitA->id,
            'tax_rate_id' => $this->taxA->id,
            'reference' => 'RED-REF-A',
            'barcode' => '1000000000001',
        ]);
        $this->productB = $this->createProduct($this->organizationB, 'RedTeam Product B', 'RED-SKU-B', [
            'brand_id' => $this->brandB->id,
            'category_id' => $this->categoryB->id,
            'unit_id' => $this->unitB->id,
            'tax_rate_id' => $this->taxB->id,
            'reference' => 'RED-REF-B',
            'barcode' => '2000000000002',
        ]);
        $this->productC = $this->createProduct($this->organizationC, 'RedTeam Product C', 'RED-SKU-C', [
            'brand_id' => $this->brandC->id,
            'category_id' => $this->categoryC->id,
            'unit_id' => $this->unitC->id,
            'tax_rate_id' => $this->taxC->id,
            'reference' => 'RED-REF-C',
            'barcode' => '3000000000003',
        ]);

        $this->variantA = $this->productA->variants()->firstOrFail();
        $this->variantB = $this->productB->variants()->firstOrFail();
        $this->variantC = $this->productC->variants()->firstOrFail();

        $this->activate($this->userA, $this->organizationA, $this->storeA);
        $this->activate($this->userB, $this->organizationC, $this->storeC);
    }

    /** @param list<string> $secrets */
    protected function assertResponseDoesNotContain(TestResponse $response, array $secrets): void
    {
        $body = $response->getContent();

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }
}
