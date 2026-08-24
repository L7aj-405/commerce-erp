<?php

namespace Tests\Feature\Security\TenantRedTeam;

class CatalogSearchMetadataLeakageTest extends TenantRedTeamTestCase
{
    public function test_exact_foreign_names_skus_references_barcodes_and_brand_names_return_no_results(): void
    {
        $searches = [
            'RedTeam Product B',
            'RED-SKU-B',
            'RED-REF-B',
            '2000000000002',
            'RedTeam Brand B',
            'RedTeam Product C',
            'RED-SKU-C',
            'RED-REF-C',
            '3000000000003',
            'RedTeam Brand C',
        ];

        foreach ($searches as $search) {
            $this->actingAs($this->userA)
                ->withHeader('X-Inertia', 'true')
                ->get(route('catalog.products.index', ['search' => $search]))
                ->assertOk()
                ->assertJsonPath('props.filters.search', $search)
                ->assertJsonPath('props.products.total', 0)
                ->assertJsonCount(0, 'props.products.data')
                ->assertJsonCount(1, 'props.brands')
                ->assertJsonPath('props.brands.0.id', $this->brandA->id)
                ->assertJsonMissing(['id' => $this->productB->id, 'name' => 'RedTeam Product B'])
                ->assertJsonMissing(['id' => $this->productC->id, 'name' => 'RedTeam Product C'])
                ->assertJsonMissing(['sku' => 'RED-SKU-B'])
                ->assertJsonMissing(['reference' => 'RED-REF-B'])
                ->assertJsonMissing(['barcode' => '2000000000002'])
                ->assertJsonMissing(['sku' => 'RED-SKU-C'])
                ->assertJsonMissing(['reference' => 'RED-REF-C'])
                ->assertJsonMissing(['barcode' => '3000000000003'])
                ->assertJsonMissing(['id' => $this->brandB->id, 'name' => 'RedTeam Brand B'])
                ->assertJsonMissing(['id' => $this->brandC->id, 'name' => 'RedTeam Brand C']);
        }
    }

    public function test_foreign_brand_and_category_filters_are_rejected_without_foreign_name_disclosure(): void
    {
        foreach ([
            ['brand', $this->brandB->id, 'brand'],
            ['brand', $this->brandC->id, 'brand'],
            ['category', $this->categoryB->id, 'category'],
            ['category', $this->categoryC->id, 'category'],
        ] as [$queryKey, $foreignId, $errorKey]) {
            $response = $this->actingAs($this->userA)
                ->getJson(route('catalog.products.index', [$queryKey => $foreignId]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($errorKey);

            $this->assertResponseDoesNotContain($response, [
                'RedTeam Brand B', 'RedTeam Brand C',
                'RedTeam Category B', 'RedTeam Category C',
                'RedTeam Organization B', 'RedTeam Organization C',
            ]);
        }
    }

    public function test_pagination_totals_and_later_pages_exclude_other_organizations(): void
    {
        foreach (range(2, 10) as $number) {
            $this->createProduct($this->organizationA, "A Pagination Product {$number}", "A-PAGE-{$number}");
        }
        foreach (range(2, 20) as $number) {
            $this->createProduct($this->organizationB, "B Pagination Secret {$number}", "B-PAGE-{$number}");
            $this->createProduct($this->organizationC, "C Pagination Secret {$number}", "C-PAGE-{$number}");
        }

        $pageOne = $this->actingAs($this->userA)
            ->withHeader('X-Inertia', 'true')
            ->get(route('catalog.products.index', ['page' => 1]))
            ->assertOk()
            ->assertJsonPath('props.products.total', 10)
            ->assertJsonPath('props.products.last_page', 1)
            ->assertJsonCount(10, 'props.products.data');

        $pageTwo = $this->actingAs($this->userA)
            ->withHeader('X-Inertia', 'true')
            ->get(route('catalog.products.index', ['page' => 2]))
            ->assertOk()
            ->assertJsonPath('props.products.total', 10)
            ->assertJsonPath('props.products.last_page', 1)
            ->assertJsonCount(0, 'props.products.data');

        foreach ([$pageOne, $pageTwo] as $response) {
            $this->assertResponseDoesNotContain($response, [
                'B Pagination Secret', 'B-PAGE-',
                'C Pagination Secret', 'C-PAGE-',
            ]);
        }
    }

    public function test_search_filters_echo_only_attacker_supplied_values_and_not_foreign_records(): void
    {
        $response = $this->actingAs($this->userA)
            ->withHeader('X-Inertia', 'true')
            ->get(route('catalog.products.index', ['search' => 'RED-SKU-C', 'status' => 'inactive']))
            ->assertOk()
            ->assertJsonPath('props.filters.search', 'RED-SKU-C')
            ->assertJsonPath('props.filters.status', 'inactive')
            ->assertJsonPath('props.products.total', 0);

        $this->assertResponseDoesNotContain($response, [
            'RedTeam Product C', 'RedTeam Brand C', 'RedTeam Organization C',
            'RED-REF-C', '3000000000003',
        ]);
    }
}
