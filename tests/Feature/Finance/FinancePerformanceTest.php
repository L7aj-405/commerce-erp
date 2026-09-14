<?php

namespace Tests\Feature\Finance;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\User;
use App\Services\Finance\FinanceInvoiceReadModel;
use App\Services\Finance\FinancePeriod;
use Illuminate\Support\Facades\DB;
use Tests\Support\DocumentTestCase;

/**
 * Proves the read model batches its paid/outstanding/fully_paid_at
 * enrichment instead of querying once per invoice
 * (SalesOrderPaymentCalculator::paidAmount() in a loop would be exactly the
 * N+1 pattern this exists to avoid). The query count for enriching a batch
 * must not grow with the batch size.
 */
class FinancePerformanceTest extends DocumentTestCase
{
    public function test_enriching_many_invoices_with_payment_summaries_uses_a_bounded_number_of_queries(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $account = $this->createFinancialAccount($organization);

        $invoices = collect();
        for ($i = 0; $i < 12; $i++) {
            $order = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => now()->toDateString()]);
            $this->addCustomLine($owner, $order, ['unit_price_excl_tax' => '100.0000']);
            $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
            $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
            $this->recordPayment($owner, $order, $account, '40.0000');
            $invoices->push($invoice);
        }

        $readModel = app(FinanceInvoiceReadModel::class);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $small = $readModel->withPaymentSummaries($invoices->take(3));
        $queriesForThree = count(DB::getQueryLog());

        DB::flushQueryLog();
        $large = $readModel->withPaymentSummaries($invoices);
        $queriesForTwelve = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(3, $small);
        $this->assertCount(12, $large);
        // Exactly 2 aggregate queries regardless of batch size (paid totals +
        // ordered allocations) — the count must NOT scale with invoice count.
        $this->assertSame($queriesForThree, $queriesForTwelve);
        $this->assertLessThanOrEqual(2, $queriesForTwelve);
    }

    public function test_facturation_drilldown_page_query_count_does_not_grow_with_invoice_count(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);

        $create = function (int $count) use ($owner, $organization, $store) {
            for ($i = 0; $i < $count; $i++) {
                $order = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => now()->toDateString()]);
                $this->addCustomLine($owner, $order, ['unit_price_excl_tax' => '50.0000']);
                $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
                $this->issueInvoice($owner, $this->createInvoice($owner, $order));
            }
        };

        $create(2);
        $this->activate($owner, $organization);
        $period = FinancePeriod::fromMonth(now()->format('Y-m'));

        DB::enableQueryLog();
        DB::flushQueryLog();
        app(FinanceInvoiceReadModel::class)->issuedDuring($organization, $period, null, perPage: 50);
        $queriesForTwo = count(DB::getQueryLog());

        $create(8); // 10 total now
        DB::flushQueryLog();
        app(FinanceInvoiceReadModel::class)->issuedDuring($organization, $period, null, perPage: 50);
        $queriesForTen = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($queriesForTwo, $queriesForTen);
    }
}
