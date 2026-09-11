<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Tests\Support\SalesTestCase;

class OrderSnapshotImmutabilityTest extends SalesTestCase
{
    public function test_changing_product_price_and_tax_rate_does_not_alter_an_existing_order_line(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $tax20 = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $tax10 = $this->createTaxRate($organization, 'TVA 10', '10.0000');
        $variant = $this->createProduct($organization, 'Snapshot Product', 'SNAP-1', [
            'default_sale_price' => '100.0000',
            'tax_rate_id' => $tax20->getKey(),
        ])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '20.0000');

        $order = $this->createDraftOrder($owner, $organization, $store);
        $line = $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '2.0000']);

        // Mutate the catalogue AFTER the order line exists.
        $variant->forceFill(['default_sale_price' => '150.0000', 'regular_sale_price' => '150.0000', 'tax_rate_id' => $tax10->getKey()])->save();
        $tax20->forceFill(['rate' => '7.0000'])->save();

        $line->refresh();
        $this->assertSame('100.0000', $line->unit_price_excl_tax);
        $this->assertSame('120.0000', $line->unit_price_incl_tax);
        $this->assertSame('20.0000', $line->tax_rate);
        $this->assertSame('TVA 20', $line->tax_name);
        $this->assertSame('200.0000', $line->subtotal_excl_tax);
        $this->assertSame('40.0000', $line->tax_amount);
        $this->assertSame('240.0000', $line->total_incl_tax);

        $order->refresh();
        $this->assertSame('240.0000', $order->total_incl_tax);
    }
}
