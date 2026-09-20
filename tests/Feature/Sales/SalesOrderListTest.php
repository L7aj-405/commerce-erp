<?php

namespace Tests\Feature\Sales;

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Support\PaymentTestCase;

class SalesOrderListTest extends PaymentTestCase
{
    public function test_index_exposes_summary_counts_filters_and_linked_invoice_state(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'Widget', 'W-1', ['default_sale_price' => '100.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '50.0000');

        // A: confirmed, unfulfilled, unpaid  -> counts toward "à préparer"
        $a = $this->createDraftOrder($owner, $organization, $store, null, ['sale_date' => '2026-09-01']);
        $this->addCatalogLine($owner, $a, $variant, $warehouse, ['quantity' => '2.0000']);
        $a = app(ConfirmSalesOrderAction::class)->execute($owner, $a)->fresh();

        // B: confirmed + fulfilled + fully paid -> "payées", and carries a DRAFT invoice
        $b = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $b, $variant, $warehouse, ['quantity' => '1.0000']);
        $b = app(ConfirmSalesOrderAction::class)->execute($owner, $b)->fresh();
        $b = app(FulfillSalesOrderAction::class)->execute($owner, $b->fresh())->fresh();
        $account = $this->createFinancialAccount($organization);
        $this->recordPayment($owner, $b, $account, $b->total_incl_tax);
        $draftInvoice = app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $b->fresh());

        // C: still a draft order -> not confirmed
        $this->createDraftOrder($owner, $organization, $store);

        $this->actingAs($owner)->get(route('sales.orders.index'))->assertOk()->assertInertia(fn (AssertableJson $page) => $page
            ->component('Sales/Orders/Index')
            ->where('summary.total', 3)
            ->where('summary.a_preparer', 1)
            ->where('summary.payees', 1)
            ->has('orders.data', 3));

        // The order carrying a draft invoice is flagged; the plain confirmed one is not.
        $this->actingAs($owner)->get(route('sales.orders.index', ['search' => $b->order_number]))
            ->assertInertia(fn (AssertableJson $page) => $page->has('orders.data', 1)
                ->where('orders.data.0.has_active_invoice', fn ($v) => (bool) $v === true));
        $this->actingAs($owner)->get(route('sales.orders.index', ['search' => $a->order_number]))
            ->assertInertia(fn (AssertableJson $page) => $page->has('orders.data', 1)
                ->where('orders.data.0.has_active_invoice', fn ($v) => (bool) $v === false));

        // Fulfillment filter narrows the list.
        $this->actingAs($owner)->get(route('sales.orders.index', ['fulfillment_status' => 'fulfilled']))
            ->assertInertia(fn (AssertableJson $page) => $page->has('orders.data', 1)
                ->where('orders.data.0.order_number', $b->order_number));

        // Payment filter narrows the list.
        $this->actingAs($owner)->get(route('sales.orders.index', ['payment_status' => 'paid']))
            ->assertInertia(fn (AssertableJson $page) => $page->has('orders.data', 1));

        // Detail page links the invoice both ways and preserves the order snapshot total.
        $this->actingAs($owner)->get(route('sales.orders.show', $b))->assertOk()->assertInertia(fn (AssertableJson $page) => $page
            ->component('Sales/Orders/Show')
            ->where('order.total_incl_tax', $b->total_incl_tax)
            ->where('documents.invoices.0.id', $draftInvoice->id)
            ->where('documents.invoices.0.status', 'draft')
            ->where('paymentSummary.status', 'paid'));

        // Once issued, the order detail shows the official number.
        $issued = app(IssueInvoiceAction::class)->execute($owner, $draftInvoice)->fresh();
        $this->actingAs($owner)->get(route('sales.orders.show', $b))->assertInertia(fn (AssertableJson $page) => $page
            ->where('documents.invoices.0.invoice_number', $issued->invoice_number)
            ->where('documents.invoices.0.status', 'issued'));
    }

    public function test_search_matches_order_number_customer_and_phone(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $customer = $this->createCustomer($organization, 'Karim Bennani', ['phone' => '0611223344']);

        $order = $this->createDraftOrder($owner, $organization, $store, $customer);
        $this->addCustomLine($owner, $order);

        foreach (['Karim', '0611223344', $order->order_number] as $term) {
            $this->actingAs($owner)->get(route('sales.orders.index', ['search' => $term]))
                ->assertInertia(fn (AssertableJson $page) => $page->has('orders.data', 1));
        }

        $this->actingAs($owner)->get(route('sales.orders.index', ['search' => 'no-such-order']))
            ->assertInertia(fn (AssertableJson $page) => $page->has('orders.data', 0));
    }
}
