<?php

namespace Tests\Feature\Finance;

use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\User;
use App\Services\Finance\FinanceCaEncaisseService;
use App\Services\Finance\FinanceMonthlyReportService;
use App\Services\Finance\FinancePeriod;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DocumentTestCase;

/**
 * "CA encaissé" business rule: a row belongs to the month of
 * Payment.payment_date only — never sale_date or invoice_date. This is the
 * business's real operational "CA" table, distinct from the existing Ventes
 * (sale-date-based) KPI, which this suite never touches or redefines.
 */
class FinanceCaEncaisseServiceTest extends DocumentTestCase
{
    public function test_july_sale_paid_in_august_counts_only_in_august_ca_encaisse(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $order = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-07-31']);
        $this->addCustomLine($owner, $order, ['unit_price_excl_tax' => '11853.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-07-31']));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '11853.0000', ['payment_date' => '2026-08-04']);

        $ventes = app(FinanceMonthlyReportService::class);
        $caEncaisse = app(FinanceCaEncaisseService::class);
        $july = FinancePeriod::fromMonth('2026-07');
        $august = FinancePeriod::fromMonth('2026-08');

        // Ventes (sale_date) is untouched — July still shows the sale.
        $this->assertSame(0, Decimal::compare($ventes->ventesTotal($organization, $july, null), '11853.0000'));

        // CA encaissé follows payment_date instead.
        $this->assertSame(0, Decimal::compare($caEncaisse->total($organization, $july, null), '0.0000'));
        $this->assertSame(0, Decimal::compare($caEncaisse->total($organization, $august, null), '11853.0000'));
        $this->assertCount(0, $caEncaisse->rows($organization, $july, null)->items());

        $row = $caEncaisse->rows($organization, $august, null)->items()[0];
        $this->assertSame('Paiement comptant', $row['status_label']);
        $this->assertSame($invoice->invoice_number, $row['reference']);
        $this->assertSame('invoice', $row['reference_type']);
    }

    public function test_partial_payment_across_two_months_each_shows_only_its_own_amount(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '10000.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '3000.0000', ['payment_date' => '2026-09-15']);
        $this->recordPayment($owner, $order, $account, '7000.0000', ['payment_date' => '2026-10-05']);

        $caEncaisse = app(FinanceCaEncaisseService::class);
        $september = FinancePeriod::fromMonth('2026-09');
        $october = FinancePeriod::fromMonth('2026-10');

        $this->assertSame(0, Decimal::compare($caEncaisse->total($organization, $september, null), '3000.0000'));
        $this->assertSame(0, Decimal::compare($caEncaisse->total($organization, $october, null), '7000.0000'));

        $septemberRow = $caEncaisse->rows($organization, $september, null)->items()[0];
        $octoberRow = $caEncaisse->rows($organization, $october, null)->items()[0];

        $this->assertSame(0, Decimal::compare($septemberRow['amount'], '3000.0000'));
        $this->assertSame(0, Decimal::compare($octoberRow['amount'], '7000.0000'));
        // First installment (does not cover the total) vs. the closing one.
        $this->assertSame('Paiement partiel', $septemberRow['status_label']);
        $this->assertSame('Solde / Reliquat', $octoberRow['status_label']);
    }

    public function test_payment_before_invoice_appears_as_avance_sur_commande_without_requiring_an_invoice(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $order = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-09-01']);
        $this->addCustomLine($owner, $order, ['unit_price_excl_tax' => '2000.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '2000.0000', ['payment_date' => '2026-09-03']);
        // Deliberately: no invoice at all.

        $caEncaisse = app(FinanceCaEncaisseService::class);
        $period = FinancePeriod::fromMonth('2026-09');

        $this->assertSame(0, Decimal::compare($caEncaisse->total($organization, $period, null), '2000.0000'));
        $row = $caEncaisse->rows($organization, $period, null)->items()[0];
        $this->assertSame('Avance sur commande', $row['status_label']);
        $this->assertSame('order', $row['reference_type']);
        $this->assertSame($order->order_number, $row['reference']);
        $this->assertNull($row['invoice_id']);
    }

    public function test_reversed_payment_excluded_from_ca_encaisse(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(total: '500.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $payment = $this->recordPayment($owner, $order, $account, '500.0000', ['payment_date' => '2026-09-10']);
        app(ReversePaymentAction::class)->execute($owner, $payment, 'Erreur de caisse');

        $caEncaisse = app(FinanceCaEncaisseService::class);
        $period = FinancePeriod::fromMonth('2026-09');

        $this->assertSame(0, Decimal::compare($caEncaisse->total($organization, $period, null), '0.0000'));
        $this->assertCount(0, $caEncaisse->rows($organization, $period, null)->items());
    }

    public function test_different_sales_orders_never_leak_amount_or_designation_into_each_other(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $account = $this->createFinancialAccount($organization);

        $orderA = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-09-01']);
        $this->addCustomLine($owner, $orderA, ['description' => 'Produit A', 'unit_price_excl_tax' => '100.0000']);
        $orderA = app(ConfirmSalesOrderAction::class)->execute($owner, $orderA)->fresh();
        $this->issueInvoice($owner, $this->createInvoice($owner, $orderA));
        $this->recordPayment($owner, $orderA, $account, '100.0000', ['payment_date' => '2026-09-05']);

        $orderB = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-09-02']);
        $this->addCustomLine($owner, $orderB, ['description' => 'Produit B', 'unit_price_excl_tax' => '200.0000']);
        $orderB = app(ConfirmSalesOrderAction::class)->execute($owner, $orderB)->fresh();
        $this->issueInvoice($owner, $this->createInvoice($owner, $orderB));
        $this->recordPayment($owner, $orderB, $account, '200.0000', ['payment_date' => '2026-09-06']);

        $caEncaisse = app(FinanceCaEncaisseService::class);
        $period = FinancePeriod::fromMonth('2026-09');
        $rows = collect($caEncaisse->rows($organization, $period, null)->items())->keyBy('sales_order_id');

        $this->assertSame(0, Decimal::compare($rows[$orderA->id]['amount'], '100.0000'));
        $this->assertSame(0, Decimal::compare($rows[$orderB->id]['amount'], '200.0000'));
        $this->assertStringContainsString('Produit A', $rows[$orderA->id]['lines'][0]['designation']);
        $this->assertStringContainsString('Produit B', $rows[$orderB->id]['lines'][0]['designation']);
    }

    public function test_total_equals_the_sum_of_every_qualifying_row(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $account = $this->createFinancialAccount($organization);

        $expected = '0.0000';
        for ($i = 0; $i < 5; $i++) {
            $order = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-09-01']);
            $this->addCustomLine($owner, $order, ['unit_price_excl_tax' => '150.0000']);
            $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
            $this->issueInvoice($owner, $this->createInvoice($owner, $order));
            $this->recordPayment($owner, $order, $account, '150.0000', ['payment_date' => '2026-09-10']);
            $expected = Decimal::add($expected, '150.0000');
        }

        $caEncaisse = app(FinanceCaEncaisseService::class);
        $period = FinancePeriod::fromMonth('2026-09');
        $rowsSum = collect($caEncaisse->rows($organization, $period, null, perPage: 50)->items())
            ->reduce(fn (string $carry, array $row) => Decimal::add($carry, $row['amount']), '0.0000');

        $this->assertSame(0, Decimal::compare($caEncaisse->total($organization, $period, null), $expected));
        $this->assertSame(0, Decimal::compare($rowsSum, $expected));
    }

    public function test_store_filtering_and_organization_isolation_over_http(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA);
        $organizationB = $this->createOrganization($ownerB);
        $storeOneA = $this->createStore($organizationA, $ownerA, 'Showroom Casa');
        $storeTwoA = $this->createStore($organizationA, $ownerA, 'Showroom Rabat');
        $storeB = $this->createStore($organizationB, $ownerB);
        $account = $this->createFinancialAccount($organizationA);

        $orderOne = $this->createDraftOrder($ownerA, $organizationA, $storeOneA, null, ['sale_date' => '2026-09-01']);
        $this->addCustomLine($ownerA, $orderOne, ['unit_price_excl_tax' => '300.0000']);
        $orderOne = app(ConfirmSalesOrderAction::class)->execute($ownerA, $orderOne)->fresh();
        $this->issueInvoice($ownerA, $this->createInvoice($ownerA, $orderOne));
        $this->recordPayment($ownerA, $orderOne, $account, '300.0000', ['payment_date' => '2026-09-05']);

        $orderTwo = $this->createDraftOrder($ownerA, $organizationA, $storeTwoA, null, ['sale_date' => '2026-09-01']);
        $this->addCustomLine($ownerA, $orderTwo, ['unit_price_excl_tax' => '700.0000']);
        $orderTwo = app(ConfirmSalesOrderAction::class)->execute($ownerA, $orderTwo)->fresh();
        $this->issueInvoice($ownerA, $this->createInvoice($ownerA, $orderTwo));
        $this->recordPayment($ownerA, $orderTwo, $account, '700.0000', ['payment_date' => '2026-09-06']);

        $this->activate($ownerA, $organizationA, $storeOneA);

        // All stores (organization-wide, independent of the active store).
        $this->actingAs($ownerA)->get(route('finance.ca-encaisse', ['month' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('total', '1000.0000'));

        // Explicit store filter.
        $this->actingAs($ownerA)->get(route('finance.ca-encaisse', ['month' => '2026-09', 'store_id' => $storeTwoA->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('total', '700.0000'));

        // A store id from another organization 404s instead of leaking existence.
        $this->actingAs($ownerA)->get(route('finance.ca-encaisse', ['store_id' => $storeB->id]))->assertNotFound();
    }

    public function test_rows_and_designation_lookups_use_a_bounded_number_of_queries(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $account = $this->createFinancialAccount($organization);

        $create = function (int $count) use ($owner, $organization, $store, $account) {
            for ($i = 0; $i < $count; $i++) {
                $order = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-09-01']);
                $this->addCustomLine($owner, $order, ['unit_price_excl_tax' => '50.0000']);
                $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
                $this->issueInvoice($owner, $this->createInvoice($owner, $order));
                $this->recordPayment($owner, $order, $account, '50.0000', ['payment_date' => '2026-09-15']);
            }
        };

        $caEncaisse = app(FinanceCaEncaisseService::class);
        $period = FinancePeriod::fromMonth('2026-09');

        $create(2);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $caEncaisse->rows($organization, $period, null, perPage: 50);
        $queriesForTwo = count(DB::getQueryLog());

        $create(8); // 10 total now
        DB::flushQueryLog();
        $caEncaisse->rows($organization, $period, null, perPage: 50);
        $queriesForTen = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($queriesForTwo, $queriesForTen);
    }
}
