<?php

namespace Tests\Feature\Finance;

use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Tests\Support\SalesTestCase;

class SalesLineFinancialsTest extends SalesTestCase
{
    public function test_ht_100_at_20_percent_quantity_3_produces_300_ht_60_tax_360_ttc(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->financeContext('100.0000', '20.0000');
        $order = $this->createDraftOrder($owner, $organization, $store);

        $line = $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '3.0000']);

        $this->assertSame('100.0000', $line->unit_price_excl_tax);
        $this->assertSame('120.0000', $line->unit_price_incl_tax);
        $this->assertSame('300.0000', $line->subtotal_excl_tax);
        $this->assertSame('0.0000', $line->discount_amount);
        $this->assertSame('300.0000', $line->taxable_amount);
        $this->assertSame('60.0000', $line->tax_amount);
        $this->assertSame('360.0000', $line->total_incl_tax);

        $order->refresh();
        $this->assertSame('300.0000', $order->subtotal_excl_tax);
        $this->assertSame('60.0000', $order->tax_total);
        $this->assertSame('360.0000', $order->total_incl_tax);
    }

    public function test_per_line_percentage_discount_reduces_taxable_base_before_tax(): void
    {
        // Qty 2 · PU HT 500 · TVA 20% · remise 10%
        [$owner, $organization, $store, $warehouse, $variant] = $this->financeContext('500.0000', '20.0000');
        $order = $this->createDraftOrder($owner, $organization, $store);

        $line = $this->addCatalogLine($owner, $order, $variant, $warehouse, [
            'quantity' => '2.0000',
            'discount_type' => 'percentage',
            'discount_value' => '10.0000',
        ]);

        $this->assertSame('1000.0000', $line->subtotal_excl_tax);   // gross HT
        $this->assertSame('100.0000', $line->discount_amount);      // discount HT
        $this->assertSame('900.0000', $line->taxable_amount);       // net HT
        $this->assertSame('180.0000', $line->tax_amount);           // TVA
        $this->assertSame('1080.0000', $line->total_incl_tax);      // TTC
    }

    public function test_zero_rated_line_has_equal_ht_and_ttc_unit_price(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->financeContext('149.9900', null);
        $order = $this->createDraftOrder($owner, $organization, $store);

        $line = $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '1.0000']);

        $this->assertSame('149.9900', $line->unit_price_excl_tax);
        $this->assertSame('149.9900', $line->unit_price_incl_tax);
        $this->assertSame('0.0000', $line->tax_amount);
        $this->assertSame('149.9900', $line->total_incl_tax);
    }

    /** @return array{User, Organization, Store, Warehouse, ProductVariant} */
    private function financeContext(string $priceHt, ?string $rate): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $tax = $rate !== null ? $this->createTaxRate($organization, "TVA {$rate}", $rate) : null;
        $variant = $this->createProduct($organization, 'Finance Product', 'FIN-1', [
            'default_sale_price' => $priceHt,
            'tax_rate_id' => $tax?->getKey(),
        ])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '50.0000');

        return [$owner, $organization, $store, $warehouse, $variant];
    }
}
