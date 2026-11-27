<?php

namespace Tests\Feature\Finance;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Support\QuotationTestCase;

/**
 * Step 12 (U-1, U-2): the labels/definitions the UI shows are driven by REAL props.
 * A figure the viewer may not see is null (the UI labels it "incomplet"); the invoice
 * page only offers the "record a payment" pointer when the viewer may record one.
 */
class ReliabilityUxPropsTest extends QuotationTestCase
{
    /** @return array{Organization, Store, Customer, Invoice, User} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner, 'Main');
        $customer = $this->createCustomer($organization, 'ACME');
        $this->activate($owner, $organization, $store);
        $order = $this->createDraftOrder($owner, $organization, $store, $customer);
        $this->addCustomLine($owner, $order, ['description' => 'Prestation', 'unit_price_excl_tax' => '100.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $invoice = $this->createInvoice($owner, $order);

        return [$organization, $store, $customer, $invoice, $owner];
    }

    private function member(Organization $organization, Store $store, array $permissions): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($organization, $user, $permissions);
        $this->addStoreMember($store, $user);
        $this->activate($user, $organization, $store);

        return $user;
    }

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }

    public function test_customer_summary_hides_payment_derived_figures_for_a_viewer_without_payment_rights(): void
    {
        [$organization, $store, $customer, , $owner] = $this->fixture();

        $full = $this->props($this->actingAs($owner)->get('/sales/customers/'.$customer->getKey())->assertOk())['summary'];
        foreach (['sales_total', 'invoiced_total', 'paid_total', 'outstanding', 'unbilled_outstanding', 'refund_obligation'] as $key) {
            $this->assertNotNull($full[$key], "{$key} must be computed for a viewer with full rights.");
        }

        $limited = $this->member($organization, $store, ['customers.view', 'sales_orders.view', 'invoices.view']);
        $summary = $this->props($this->actingAs($limited)->get('/sales/customers/'.$customer->getKey())->assertOk())['summary'];
        // null is what the UI turns into the "Incomplet" label: never a misleading 0.
        $this->assertNull($summary['paid_total']);
        $this->assertNull($summary['outstanding']);
        $this->assertNull($summary['unbilled_outstanding']);
        $this->assertNull($summary['refund_obligation']);
        $this->assertNotNull($summary['sales_total']);
        $this->assertNotNull($summary['invoiced_total']);
    }

    public function test_invoice_page_exposes_record_payment_pointer_only_to_users_who_may_record_a_payment(): void
    {
        [$organization, $store, , $invoice, $owner] = $this->fixture();

        $this->actingAs($owner)->get(route('invoices.show', $invoice))->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.recordPayment', true)->where('can.viewOrder', true));

        $viewer = $this->member($organization, $store, ['invoices.view', 'sales_orders.view']);
        $this->actingAs($viewer)->get(route('invoices.show', $invoice))->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.recordPayment', false));
    }
}
