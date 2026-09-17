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
use App\Support\Decimal;
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

    // --- Regression coverage: authoritative allocation must be exact decimal
    //     arithmetic, never float, and must always sum to exactly the global
    //     discount — including the edge cases §B3 calls out explicitly. ---

    public function test_a_discount_equal_to_the_full_subtotal_allocates_exactly_with_no_residual(): void
    {
        // Historically the float-based proportional pass under-allocated the
        // first two lines just enough that the last line's own subtotal
        // ceiling couldn't absorb the true remainder — this is the exact
        // shape of bug that produced 99.9834 instead of 99.9900.
        [$owner, $organization, $store, $warehouse] = $this->flatContext();
        $tax = $this->createTaxRate($organization, 'TVA 0', '0.0000');
        $variant = $this->createProduct($organization, 'Article', 'DISC-EQ', ['default_sale_price' => '33.33', 'tax_rate_id' => $tax->getKey()])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');

        // Three SEPARATE lines (not one line of quantity 3): the bug only
        // shows up when the non-last lines' proportional share gets rounded
        // down, leaving the last line's own subtotal too small to absorb the
        // true remainder.
        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $this->addCatalogLine($owner, $draft, $variant, $warehouse, ['quantity' => '1.0000']);
        $this->addCatalogLine($owner, $draft, $variant, $warehouse, ['quantity' => '1.0000']);
        $this->addCatalogLine($owner, $draft, $variant, $warehouse, ['quantity' => '1.0000']);

        $draft->pos_global_discount_type = 'fixed';
        $draft->pos_global_discount_value = '99.9900'; // exactly 3 x 33.33
        $draft->save();

        $breakdown = app(PosDraftCheckoutCalculator::class)->breakdown($draft->fresh());
        $this->assertSame('99.9900', $breakdown['global_discount_amount']);
        $this->assertSame('0.0000', $breakdown['net_excl_tax']);
        $this->assertSame('0.0000', $breakdown['total_incl_tax']);
    }

    public function test_seven_lines_split_a_discount_with_an_exact_sum_and_deterministic_residual(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->flatContext();
        $tax = $this->createTaxRate($organization, 'TVA 0', '0.0000');
        $variant = $this->createProduct($organization, 'Article', 'DISC-7', ['default_sale_price' => '10.00', 'tax_rate_id' => $tax->getKey()])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '20.0000');

        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        foreach (range(1, 7) as $i) {
            $this->addCatalogLine($owner, $draft, $variant, $warehouse, ['quantity' => '1.0000']);
        }

        $draft->pos_global_discount_type = 'fixed';
        $draft->pos_global_discount_value = '50.0000'; // 50 / 70 taxable does not divide evenly
        $draft->save();

        $distribution = app(PosDraftCheckoutCalculator::class)->distributedLineDiscounts($draft->fresh());
        $sum = array_reduce($distribution, fn (string $carry, array $item) => Decimal::add($carry, $item['discount_value']), '0.0000');
        $this->assertSame('50.0000', $sum);
    }

    public function test_a_single_line_absorbs_the_entire_discount_exactly(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->flatContext();
        $tax = $this->createTaxRate($organization, 'TVA 0', '0.0000');
        $variant = $this->createProduct($organization, 'Article', 'DISC-1', ['default_sale_price' => '500.00', 'tax_rate_id' => $tax->getKey()])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '5.0000');

        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $this->addCatalogLine($owner, $draft, $variant, $warehouse, ['quantity' => '1.0000']);

        $draft->pos_global_discount_type = 'fixed';
        $draft->pos_global_discount_value = '123.4567';
        $draft->save();

        $breakdown = app(PosDraftCheckoutCalculator::class)->breakdown($draft->fresh());
        $this->assertSame('123.4567', $breakdown['global_discount_amount']);
        $this->assertSame('376.5433', $breakdown['net_excl_tax']);
    }

    public function test_tiny_decimal_amounts_still_sum_exactly(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->flatContext();
        $tax = $this->createTaxRate($organization, 'TVA 0', '0.0000');
        $a = $this->createProduct($organization, 'A', 'TINY-A', ['default_sale_price' => '0.0001', 'tax_rate_id' => $tax->getKey()])->variants->first();
        $b = $this->createProduct($organization, 'B', 'TINY-B', ['default_sale_price' => '0.0002', 'tax_rate_id' => $tax->getKey()])->variants->first();
        $c = $this->createProduct($organization, 'C', 'TINY-C', ['default_sale_price' => '0.0003', 'tax_rate_id' => $tax->getKey()])->variants->first();
        foreach ([$a, $b, $c] as $variant) {
            $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');
        }

        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $this->addCatalogLine($owner, $draft, $a, $warehouse);
        $this->addCatalogLine($owner, $draft, $b, $warehouse);
        $this->addCatalogLine($owner, $draft, $c, $warehouse);

        $draft->pos_global_discount_type = 'fixed';
        $draft->pos_global_discount_value = '0.0004';
        $draft->save();

        $breakdown = app(PosDraftCheckoutCalculator::class)->breakdown($draft->fresh());
        $this->assertSame('0.0004', $breakdown['global_discount_amount']);
        $this->assertSame('0.0002', $breakdown['net_excl_tax']); // 0.0006 gross - 0.0004 discount
    }

    public function test_the_authoritative_allocation_source_contains_no_float_arithmetic(): void
    {
        $source = file_get_contents(app_path('Services/PosDraftCheckoutCalculator.php'));
        $this->assertStringNotContainsString('(float)', $source);
        $this->assertStringNotContainsString('(double)', $source);
        $this->assertStringNotContainsString('floatval(', $source);
    }

    /** @return array{User, Organization, Store, Warehouse} */
    private function flatContext(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $this->activate($owner, $organization, $store);

        return [$owner, $organization, $store, $warehouse];
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
