<?php

namespace Tests\Feature\Documents;

use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Support\DocumentTestCase;

class InvoiceSharingTest extends DocumentTestCase
{
    public function test_issued_invoice_exposes_email_and_whatsapp_sharing_with_prefilled_values(): void
    {
        [$owner, , , $order] = $this->documentFixture(); // customer email billing@example.test, phone 0600000000
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $this->actingAs($owner)->get(route('invoices.show', $invoice))->assertOk()->assertInertia(fn (AssertableJson $page) => $page
            ->where('sharing.email', 'billing@example.test')
            ->where('sharing.phone', '0600000000')
            ->where('sharing.whatsappPhone', '212600000000')
            ->where('sharing.whatsappMessage', fn ($message) => str_contains($message, 'Bonjour')
                && str_contains($message, $invoice->invoice_number)
                && str_contains($message, 'Montant :')
                && str_contains($message, 'PDF : http'))
            ->where('sharing.pdfUrl', fn ($url) => str_contains($url, '/shared-pdf?')
                && str_contains($url, 'signature='))
        );
    }

    public function test_draft_invoice_exposes_no_official_sharing(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $draft = $this->createInvoice($owner, $order);

        $this->actingAs($owner)->get(route('invoices.show', $draft))->assertOk()
            ->assertInertia(fn (AssertableJson $page) => $page->where('sharing', null));
    }

    public function test_whatsapp_phone_is_null_when_the_customer_has_no_phone(): void
    {
        $owner = \App\Models\User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $customer = $this->createCustomer($organization, 'Sans Téléphone', ['email' => 'x@example.test']);
        $order = $this->createDraftOrder($owner, $organization, $store, $customer);
        $this->addCustomLine($owner, $order);
        $order = app(\App\Actions\Sales\ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $this->actingAs($owner)->get(route('invoices.show', $invoice))->assertInertia(fn (AssertableJson $page) => $page
            ->where('sharing.phone', null)
            ->where('sharing.whatsappPhone', null)
            ->where('sharing.email', 'x@example.test'));
    }
}
