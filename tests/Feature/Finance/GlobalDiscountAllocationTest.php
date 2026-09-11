<?php

namespace Tests\Feature\Finance;

use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PosDraftCheckoutCalculator;
use Tests\Support\PosTestCase;

class GlobalDiscountAllocationTest extends PosTestCase
{
    public function test_fixed_global_discount_reduces_the_taxable_base_before_tax_single_rate(): void
    {
        [$owner, $organization, $store, $warehouse, $cash, $variantA, $variantB] = $this->context('20.0000', '20.0000');
        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $this->addCatalogLine($owner, $draft, $variantA, $warehouse, ['quantity' => '2.0000']); // HT 200 · TVA 40
        $this->addCatalogLine($owner, $draft, $variantB, $warehouse, ['quantity' => '1.0000']); // HT 200 · TVA 40

        $draft->pos_global_discount_type = 'fixed';
        $draft->pos_global_discount_value = '40.0000';
        $draft->save();

        $breakdown = app(PosDraftCheckoutCalculator::class)->breakdown($draft->fresh());
        $this->assertSame('400.0000', $breakdown['gross_excl_tax']);
        $this->assertSame('40.0000', $breakdown['global_discount_amount']);
        $this->assertSame('360.0000', $breakdown['net_excl_tax']);
        $this->assertSame('72.0000', $breakdown['tax_total']);
        $this->assertSame('432.0000', $breakdown['total_incl_tax']);

        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posDraftCheckoutPayload($draft, [
            $this->posPayment($cash, '432.0000', ['cash_received' => '432.0000']),
        ]))->assertRedirect(route('pos.index'));

        $order = SalesOrder::query()->firstOrFail();
        $this->assertSame('400.0000', $order->subtotal_excl_tax);
        $this->assertSame('40.0000', $order->discount_total);
        $this->assertSame('72.0000', $order->tax_total);
        $this->assertSame('432.0000', $order->total_incl_tax);
        $this->assertSame('paid', $order->payment_status->value);
    }

    public function test_percentage_global_discount_is_taken_off_the_net_ht_base(): void
    {
        [$owner, $organization, $store, $warehouse, , $variantA, $variantB] = $this->context('20.0000', '20.0000');
        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $this->addCatalogLine($owner, $draft, $variantA, $warehouse, ['quantity' => '2.0000']);
        $this->addCatalogLine($owner, $draft, $variantB, $warehouse, ['quantity' => '1.0000']);

        $draft->pos_global_discount_type = 'percentage';
        $draft->pos_global_discount_value = '10.0000';
        $draft->save();

        $breakdown = app(PosDraftCheckoutCalculator::class)->breakdown($draft->fresh());
        $this->assertSame('40.0000', $breakdown['global_discount_amount']); // 10% of 400 HT
        $this->assertSame('360.0000', $breakdown['net_excl_tax']);
        $this->assertSame('72.0000', $breakdown['tax_total']);
        $this->assertSame('432.0000', $breakdown['total_incl_tax']);
    }

    public function test_global_discount_allocates_across_lines_with_different_tax_rates(): void
    {
        [$owner, $organization, $store, $warehouse, , $variantA, $variantB] = $this->context('20.0000', '10.0000');
        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $this->addCatalogLine($owner, $draft, $variantA, $warehouse, ['quantity' => '2.0000']); // HT 200 @ 20%
        $this->addCatalogLine($owner, $draft, $variantB, $warehouse, ['quantity' => '1.0000']); // HT 200 @ 10%

        $draft->pos_global_discount_type = 'fixed';
        $draft->pos_global_discount_value = '40.0000';
        $draft->save();

        // 40 HT allocated pro-rata by taxable amount (200 / 200) -> 20 each.
        // A: 180 HT @ 20% = 36 tax ; B: 180 HT @ 10% = 18 tax.
        $breakdown = app(PosDraftCheckoutCalculator::class)->breakdown($draft->fresh());
        $this->assertSame('360.0000', $breakdown['net_excl_tax']);
        $this->assertSame('54.0000', $breakdown['tax_total']);
        $this->assertSame('414.0000', $breakdown['total_incl_tax']);
    }

    /** @return array{User, Organization, Store, Warehouse, FinancialAccount, ProductVariant, ProductVariant} */
    private function context(string $rateA, string $rateB): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $cash = $this->createPosAccount($organization, 'cash', 'POS-CASH', 'POS Cash');
        $taxA = $this->createTaxRate($organization, "TVA A {$rateA}", $rateA);
        $taxB = $this->createTaxRate($organization, "TVA B {$rateB}", $rateB);
        $variantA = $this->createProduct($organization, 'Product A', 'DISC-A', ['default_sale_price' => '100.0000', 'tax_rate_id' => $taxA->getKey()])->variants->first();
        $variantB = $this->createProduct($organization, 'Product B', 'DISC-B', ['default_sale_price' => '200.0000', 'tax_rate_id' => $taxB->getKey()])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variantA, '20.0000');
        $this->openStock($owner, $organization, $warehouse, $variantB, '20.0000');

        return [$owner, $organization, $store, $warehouse, $cash, $variantA, $variantB];
    }
}
