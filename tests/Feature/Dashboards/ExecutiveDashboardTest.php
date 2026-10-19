<?php

namespace Tests\Feature\Dashboards;

use App\Actions\Payments\RefundPaymentAction;
use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\User;
use App\Services\Dashboards\ExecutiveDashboardService;
use App\Services\Finance\FinancePeriod;
use App\Support\Decimal;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DocumentTestCase;

class ExecutiveDashboardTest extends DocumentTestCase
{
    public function test_main_dashboard_uses_authoritative_period_store_and_payment_data(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $otherStore = $this->createStore($organization, $owner, 'Other store');
        $account = $this->createFinancialAccount($organization);
        $warehouse = $this->createWarehouse($organization);
        $product = $this->createProduct($organization, 'Produit vedette', null, ['default_sale_price' => '1000.0000']);
        $this->openStock($owner, $organization, $warehouse, $product->variants->first(), '5.0000');

        $order = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-10-05']);
        $this->addCatalogLine($owner, $order, $product->variants->first(), $warehouse);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $this->recordPayment($owner, $order, $account, '400.0000', ['payment_date' => '2026-10-10']);

        $otherOrder = $this->createDraftOrder($owner, $organization, $otherStore, null, ['sale_date' => '2026-10-06']);
        $this->addCustomLine($owner, $otherOrder, ['unit_price_excl_tax' => '9000.0000']);
        app(ConfirmSalesOrderAction::class)->execute($owner, $otherOrder);

        $dashboard = app(ExecutiveDashboardService::class)->main(
            $organization,
            FinancePeriod::fromMonth('2026-10'),
            $store,
        );

        $this->assertDecimalEquals('1000.0000', $dashboard['primary']['net_sales']['value']);
        $this->assertDecimalEquals('400.0000', $dashboard['primary']['net_collections']['value']);
        $this->assertSame(1, $dashboard['primary']['orders']['value']);
        $this->assertDecimalEquals('600.0000', $dashboard['primary']['receivables']['value']);
        $this->assertSame(['paid' => 0, 'partially_paid' => 1, 'unpaid' => 0], $dashboard['charts']['order_states']);
        $this->assertSame('Produit vedette', $dashboard['charts']['top_products'][0]['label']);
        $this->assertDecimalEquals('1000.0000', $dashboard['charts']['top_products'][0]['value']);

        $salesDay = collect($dashboard['charts']['sales_vs_collections'])->firstWhere('date', '2026-10-05');
        $paymentDay = collect($dashboard['charts']['sales_vs_collections'])->firstWhere('date', '2026-10-10');
        $this->assertDecimalEquals('1000.0000', $salesDay['sales']);
        $this->assertDecimalEquals('0.0000', $salesDay['collections']);
        $this->assertDecimalEquals('400.0000', $paymentDay['collections']);
    }

    public function test_finance_dashboard_cash_flow_and_receivables_use_real_ledger_dates(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $account = $this->createFinancialAccount($organization);
        $order = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-10-03']);
        $this->addCustomLine($owner, $order, ['unit_price_excl_tax' => '750.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $this->recordPayment($owner, $order, $account, '250.0000', ['payment_date' => '2026-10-12']);

        $dashboard = app(ExecutiveDashboardService::class)->finance(
            $organization,
            FinancePeriod::fromMonth('2026-10'),
            $store,
        );

        $this->assertDecimalEquals('250.0000', $dashboard['primary']['net_collections']);
        $this->assertDecimalEquals('500.0000', $dashboard['primary']['receivables']);
        $this->assertSame(1, $dashboard['charts']['receivables']['counts']['partially_paid']);
        $this->assertDecimalEquals('500.0000', $dashboard['charts']['receivables']['amounts']['partially_paid']);
        $cashDay = collect($dashboard['charts']['cash_flow'])->firstWhere('date', '2026-10-12');
        $this->assertDecimalEquals('250.0000', $cashDay['incoming']);
        $this->assertDecimalEquals('0.0000', $cashDay['outgoing']);
    }

    public function test_empty_dashboards_return_explicit_zero_series_and_unconfigured_treasury(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);

        $dashboard = app(ExecutiveDashboardService::class)->finance(
            $organization,
            FinancePeriod::fromMonth('2026-10'),
            $store,
        );

        $this->assertNull($dashboard['primary']['treasury_balance']);
        $this->assertNull($dashboard['primary']['treasury_projected']);
        $this->assertCount(31, $dashboard['charts']['sales_vs_collections']);
        $this->assertTrue(collect($dashboard['charts']['sales_vs_collections'])->every(
            fn (array $row) => Decimal::compare($row['sales'], '0.0000') === 0
                && Decimal::compare($row['collections'], '0.0000') === 0
        ));
        $this->assertSame([], $dashboard['charts']['obligations']);
    }

    public function test_cash_flow_uses_posted_movements_refunds_and_excludes_reversals(): void
    {
        $this->travelTo('2026-10-14');
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $account = $this->createFinancialAccount($organization);

        $refundedOrder = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-10-02']);
        $this->addCustomLine($owner, $refundedOrder, ['unit_price_excl_tax' => '200.0000']);
        $refundedOrder = app(ConfirmSalesOrderAction::class)->execute($owner, $refundedOrder)->fresh();
        $refundedPayment = $this->recordPayment($owner, $refundedOrder, $account, '200.0000', ['payment_date' => '2026-10-14']);
        app(RefundPaymentAction::class)->execute($owner, $refundedPayment, $refundedOrder, 'Remboursement test');

        $reversedOrder = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-10-03']);
        $this->addCustomLine($owner, $reversedOrder, ['unit_price_excl_tax' => '100.0000']);
        $reversedOrder = app(ConfirmSalesOrderAction::class)->execute($owner, $reversedOrder)->fresh();
        $reversedPayment = $this->recordPayment($owner, $reversedOrder, $account, '100.0000', ['payment_date' => '2026-10-14']);
        app(ReversePaymentAction::class)->execute($owner, $reversedPayment, 'Annulation test');

        $dashboard = app(ExecutiveDashboardService::class)->finance(
            $organization,
            FinancePeriod::fromMonth('2026-10'),
            $store,
        );
        $day = collect($dashboard['charts']['cash_flow'])->firstWhere('date', '2026-10-14');
        $trendDay = collect($dashboard['charts']['sales_vs_collections'])->firstWhere('date', '2026-10-14');

        $this->assertDecimalEquals('200.0000', $day['incoming']);
        $this->assertDecimalEquals('200.0000', $day['outgoing']);
        $this->assertDecimalEquals('0.0000', $trendDay['collections']);
    }

    public function test_platform_hides_executive_financial_data_without_all_required_permissions(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->addOrganizationMember($organization, $member, ['sales_orders.view']);
        $this->addStoreMember($store, $member);
        $this->activate($member, $organization, $store);

        $this->actingAs($member)->get(route('platform.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Platform/Index')
            ->where('executiveDashboard', null));
    }

    private function assertDecimalEquals(string $expected, string $actual): void
    {
        $this->assertSame(0, Decimal::compare($actual, $expected));
    }
}
