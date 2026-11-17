<?php

namespace Tests\Feature\Sales;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DocumentTestCase;

/**
 * BUG-02: "Reste 0" on invoices must never hide a confirmed, not-yet-invoiced order.
 * Invoice-based receivables and order exposure are reported as two separate figures.
 */
class CustomerUnbilledBalanceTest extends DocumentTestCase
{
    public function test_confirmed_unbilled_order_is_reported_separately_from_the_invoice_balance(): void
    {
        [$owner, $organization, $store, $order, $customer] = $this->documentFixture(total: '99.0000');
        $this->activate($owner, $organization, $store);

        $this->actingAs($owner)->get(route('sales.customers.show', $customer))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.invoiced_total', '0.0000')
                ->where('summary.outstanding', '0.0000')
                ->where('summary.unbilled_outstanding', '99.0000'));

        // Once the order is invoiced, the exposure moves to the invoice-based receivable.
        $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->actingAs($owner)->get(route('sales.customers.show', $customer))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.outstanding', '99.0000')
                ->where('summary.unbilled_outstanding', '0.0000'));
    }
}
