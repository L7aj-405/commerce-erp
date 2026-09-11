<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Support\Decimal;
use Tests\Support\SalesTestCase;

class RoundingConsistencyTest extends SalesTestCase
{
    public function test_order_header_totals_are_the_exact_sum_of_the_line_snapshots_for_fractional_inputs(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $tax1999 = $this->createTaxRate($organization, 'TVA 19,99', '19.9900');
        $tax55 = $this->createTaxRate($organization, 'TVA 5,5', '5.5000');
        $variantA = $this->createProduct($organization, 'Fractional A', 'ROUND-A', ['default_sale_price' => '99.9900', 'tax_rate_id' => $tax1999->getKey()])->variants->first();
        $variantB = $this->createProduct($organization, 'Fractional B', 'ROUND-B', ['default_sale_price' => '33.3300', 'tax_rate_id' => $tax55->getKey()])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variantA, '20.0000');
        $this->openStock($owner, $organization, $warehouse, $variantB, '20.0000');

        $order = $this->createDraftOrder($owner, $organization, $store);
        $lineA = $this->addCatalogLine($owner, $order, $variantA, $warehouse, ['quantity' => '3.0000']);
        $lineB = $this->addCatalogLine($owner, $order, $variantB, $warehouse, ['quantity' => '1.0000']);
        $order->refresh();

        // 99.99 × 19.99% snapshot.
        $this->assertSame('299.9700', $lineA->subtotal_excl_tax);
        $this->assertSame('59.9640', $lineA->tax_amount);
        $this->assertSame('359.9340', $lineA->total_incl_tax);

        $sumSubtotal = Decimal::add($lineA->subtotal_excl_tax, $lineB->subtotal_excl_tax);
        $sumTax = Decimal::add($lineA->tax_amount, $lineB->tax_amount);
        $sumTotal = Decimal::add($lineA->total_incl_tax, $lineB->total_incl_tax);

        // The header is the exact 4-decimal sum of its line snapshots — no independent re-rounding.
        $this->assertSame($sumSubtotal, $order->subtotal_excl_tax);
        $this->assertSame($sumTax, $order->tax_total);
        $this->assertSame($sumTotal, $order->total_incl_tax);
        $this->assertSame(
            $order->total_incl_tax,
            Decimal::add(Decimal::subtract($order->subtotal_excl_tax, $order->discount_total), $order->tax_total),
        );
    }
}
