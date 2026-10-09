<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Enums\SalesOrderDiscountType;
use App\Models\CustomerReturnLine;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\SaleLineMarginCalculator;
use App\Services\SalesLineCalculator;
use App\Services\SalesMarginReportService;
use Tests\Support\SalesTestCase;

class SalesMarginSnapshotTest extends SalesTestCase
{
    public function test_confirmation_snapshots_cost_and_later_catalog_change_does_not_rewrite_margin(): void
    {
        [$owner, , , , $variant, $order] = $this->catalogOrder('100.0000', '180.0000', '2.0000');

        app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        $line = $order->lines()->sole()->fresh();

        $this->assertSame('100.0000', $line->purchase_price_snapshot);
        $this->assertSame('200.0000', $line->cost_total_snapshot);
        $this->assertSame('160.0000', $line->margin_amount_snapshot);
        $this->assertSame('44.4444', $line->margin_rate_snapshot);
        $this->assertSame('available', $line->cost_status);

        $variant->purchase_price = '130.0000';
        $variant->save();

        $this->assertSame('100.0000', $line->fresh()->purchase_price_snapshot);
        $this->assertSame('160.0000', $line->fresh()->margin_amount_snapshot);
    }

    public function test_margin_uses_authoritative_net_ht_after_fixed_and_percentage_discounts(): void
    {
        [$owner, $organization, $store, $warehouse, $variant, $fixed] = $this->catalogOrder('120', '200', '1', [
            'discount_type' => 'fixed',
            'discount_value' => '30',
        ]);
        app(ConfirmSalesOrderAction::class)->execute($owner, $fixed);
        $this->assertSame('50.0000', $fixed->lines()->sole()->margin_amount_snapshot);

        $percentage = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $percentage, $variant, $warehouse, [
            'quantity' => '1',
            'discount_type' => 'percentage',
            'discount_value' => '10',
        ]);
        app(ConfirmSalesOrderAction::class)->execute($owner, $percentage);
        $this->assertSame('60.0000', $percentage->lines()->sole()->margin_amount_snapshot);
    }

    public function test_missing_cost_does_not_block_sale_or_become_zero_while_zero_cost_is_valid(): void
    {
        [$owner, $organization, $store, $warehouse, , $missing] = $this->catalogOrder(null, '100', '1');
        app(ConfirmSalesOrderAction::class)->execute($owner, $missing);
        $missingLine = $missing->lines()->sole();
        $this->assertSame('confirmed', $missing->fresh()->status->value);
        $this->assertSame('missing', $missingLine->cost_status);
        $this->assertNull($missingLine->purchase_price_snapshot);
        $this->assertNull($missingLine->margin_amount_snapshot);

        $zeroVariant = $this->createProduct($organization, 'Free acquisition', 'FREE-COST', [
            'purchase_price' => '0.0000',
            'default_sale_price' => '80.0000',
        ])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $zeroVariant, '2');
        $zero = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $zero, $zeroVariant, $warehouse);
        app(ConfirmSalesOrderAction::class)->execute($owner, $zero);
        $this->assertSame('available', $zero->lines()->sole()->cost_status);
        $this->assertSame('80.0000', $zero->lines()->sole()->margin_amount_snapshot);
    }

    public function test_calculator_supports_positive_zero_negative_and_tax_exclusive_margin(): void
    {
        $calculator = app(SaleLineMarginCalculator::class);
        $this->assertSame('50.0000', $calculator->calculate('170', '1', '120')['margin_amount_snapshot']);
        $this->assertSame('0.0000', $calculator->calculate('120', '1', '120')['margin_amount_snapshot']);
        $this->assertSame('-40.0000', $calculator->calculate('160', '2', '100')['margin_amount_snapshot']);

        $sale = app(SalesLineCalculator::class)->calculate('2', '100', '20', SalesOrderDiscountType::None, '0');
        $margin = $calculator->calculate($sale['taxable_amount'], $sale['quantity'], '60');
        $this->assertSame('80.0000', $margin['margin_amount_snapshot']);
        $this->assertSame('200.0000', $sale['taxable_amount']);
        $this->assertSame('240.0000', $sale['total_incl_tax']);
    }

    public function test_custom_decimal_line_has_unavailable_margin_without_changing_quantity_rules(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCustomLine($owner, $order, ['quantity' => '1.5000', 'unit_price_excl_tax' => '100']);

        app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        $line = $order->lines()->sole();
        $this->assertSame('1.5000', $line->quantity);
        $this->assertSame('unavailable', $line->cost_status);
        $this->assertNull($line->margin_amount_snapshot);
    }

    public function test_return_effect_uses_original_cost_snapshot_and_not_current_catalog_cost(): void
    {
        [$owner, , , , $variant, $order] = $this->catalogOrder('100', '160', '2');
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        $source = $order->lines()->sole();
        $variant->purchase_price = '130.0000';
        $variant->save();

        $returnLine = new CustomerReturnLine;
        $returnLine->quantity = '1.0000';
        $returnLine->taxable_amount = '160.0000';
        $returnLine->setRelation('salesOrderLine', $source);
        $effect = app(SalesMarginReportService::class)->returnLineEffect($returnLine);

        $this->assertSame('100.0000', $effect['cost_total']);
        $this->assertSame('60.0000', $effect['gross_margin_amount']);
    }

    public function test_legacy_lines_are_not_backfilled_and_margin_visibility_is_permission_guarded(): void
    {
        [$owner, $organization, $store, , $variant, $order] = $this->catalogOrder('50', '100', '1');
        app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        $line = $order->lines()->sole();
        $line->purchase_price_snapshot = null;
        $line->cost_total_snapshot = null;
        $line->margin_amount_snapshot = null;
        $line->margin_rate_snapshot = null;
        $line->cost_status = null;
        $line->save();
        $variant->purchase_price = '99.0000';
        $variant->save();

        $this->assertSame(1, app(SalesMarginReportService::class)->forOrder($order->fresh())['missing_cost_line_count']);
        $this->assertNull($line->fresh()->purchase_price_snapshot);

        $sales = User::factory()->create();
        $this->addDefaultSalesEmployee($organization, $store, $sales);
        $this->actingAs($sales)->get(route('sales.orders.show', $order))
            ->assertOk()
            ->assertDontSee('purchase_price_snapshot')
            ->assertDontSee('margin_amount_snapshot');

        $this->activate($owner, $organization, $store);
        $this->actingAs($owner)->get(route('sales.orders.show', $order))
            ->assertOk()
            ->assertSee('marginSummary');
    }

    public function test_period_reporting_is_organization_and_store_scoped(): void
    {
        [$ownerA, $organizationA, $storeA, , , $orderA] = $this->catalogOrder('40', '100', '1');
        app(ConfirmSalesOrderAction::class)->execute($ownerA, $orderA);
        [$ownerB, $organizationB, , , , $orderB] = $this->catalogOrder('1', '1000', '1');
        app(ConfirmSalesOrderAction::class)->execute($ownerB, $orderB);

        $summary = app(SalesMarginReportService::class)->forPeriod(
            $organizationA,
            '2026-08-01',
            '2026-08-31',
            $storeA,
        );

        $this->assertSame('100.0000', $summary['net_revenue_excl_tax']);
        $this->assertSame('40.0000', $summary['cost_total']);
        $this->assertSame('60.0000', $summary['gross_margin_amount']);
        $this->assertSame($organizationB->getKey(), $orderB->organization_id);
    }

    /** @return array{User, mixed, mixed, mixed, ProductVariant, mixed} */
    private function catalogOrder(?string $cost, string $salePrice, string $quantity, array $lineOverrides = []): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'Margin Product', null, [
            'purchase_price' => $cost,
            'default_sale_price' => $salePrice,
        ])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '20');
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, array_replace([
            'quantity' => $quantity,
        ], $lineOverrides));

        return [$owner, $organization, $store, $warehouse, $variant, $order];
    }
}
