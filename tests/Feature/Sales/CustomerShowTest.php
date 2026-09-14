<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DocumentTestCase;

/**
 * New Customer Show page: a cheap financial summary over a single
 * customer's own orders/invoices/payments — never a per-row calculator, and
 * never leaking another customer's or another organization's figures.
 */
class CustomerShowTest extends DocumentTestCase
{
    public function test_summary_reflects_only_this_customers_own_sales_invoices_and_payments(): void
    {
        [$owner, $organization, , $order, $customer] = $this->documentFixture(total: '1000.0000');
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $order, $account, '400.0000');

        // An unrelated customer/order in the same organization must never
        // leak into this customer's own figures.
        $otherOrder = $this->documentFixture(total: '5000.0000')[3];

        $this->activate($owner, $organization);
        $this->actingAs($owner)->get(route('sales.customers.show', $customer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.sales_total', '1000.0000')
                ->where('summary.invoiced_total', '1000.0000')
                ->where('summary.paid_total', '400.0000')
                ->where('summary.outstanding', '600.0000')
                ->where('ordersCount', 1)
                ->where('invoicesCount', 1));

        $this->assertNotSame($otherOrder->id, $order->id);
    }

    public function test_a_customer_from_another_organization_404s_instead_of_leaking_existence(): void
    {
        [$ownerA, $organizationA] = $this->documentFixture();
        [, , , , $customerB] = $this->documentFixture();

        $this->activate($ownerA, $organizationA);
        $this->actingAs($ownerA)->get(route('sales.customers.show', $customerB))->assertNotFound();
    }

    public function test_summary_queries_are_bounded_regardless_of_document_count(): void
    {
        [$owner, $organization, $store, , $customer] = $this->documentFixture();
        $account = $this->createFinancialAccount($organization);

        $addOrder = function () use ($owner, $organization, $store, $customer, $account) {
            $order = $this->createDraftOrder($owner, $organization, $store, $customer, ['sale_date' => now()->toDateString()]);
            $this->addCustomLine($owner, $order, ['unit_price_excl_tax' => '50.0000']);
            $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
            $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
            $this->recordPayment($owner, $order, $account, '20.0000');

            return $invoice;
        };

        $addOrder();
        $addOrder();
        $this->activate($owner, $organization);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($owner)->get(route('sales.customers.show', $customer))->assertOk();
        $queriesForTwo = count(DB::getQueryLog());
        DB::disableQueryLog();

        for ($i = 0; $i < 6; $i++) {
            $addOrder();
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($owner)->get(route('sales.customers.show', $customer))->assertOk();
        $queriesForEight = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($queriesForTwo, $queriesForEight);
    }
}
