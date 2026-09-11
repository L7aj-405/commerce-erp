<?php

namespace Tests\Feature\Documents;

use App\Actions\Sales\ConfirmSalesOrderAction;
use Illuminate\Support\Facades\URL;
use Tests\Support\DocumentTestCase;

class SharedInvoicePdfLinkTest extends DocumentTestCase
{
    public function test_a_valid_signed_link_returns_the_invoice_pdf_without_authentication(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $url = URL::temporarySignedRoute('invoices.shared-pdf', now()->addDay(), $invoice);

        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertNull(auth()->user());
    }

    public function test_a_tampered_signature_or_id_is_rejected(): void
    {
        [$owner, $organization, $store, $order] = $this->documentFixture();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $second = $this->createDraftOrder($owner, $organization, $store);
        $this->addCustomLine($owner, $second);
        $second = app(ConfirmSalesOrderAction::class)->execute($owner, $second)->fresh();
        $otherInvoice = $this->issueInvoice($owner, $this->createInvoice($owner, $second));

        $url = URL::temporarySignedRoute('invoices.shared-pdf', now()->addDay(), $invoice);

        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('0', 64), $url))->assertForbidden();
        $this->get(str_replace("/invoices/{$invoice->id}/", "/invoices/{$otherInvoice->id}/", $url))->assertForbidden();
    }

    public function test_an_expired_link_is_rejected(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $url = URL::temporarySignedRoute('invoices.shared-pdf', now()->subMinute(), $invoice);

        $this->get($url)->assertForbidden();
    }

    public function test_a_forged_link_without_a_real_signature_is_rejected(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $this->get("/invoices/{$invoice->id}/shared-pdf?expires=9999999999&signature=deadbeef")->assertForbidden();
        $this->get("/invoices/{$invoice->id}/shared-pdf")->assertForbidden();
    }

    public function test_a_signed_link_to_a_draft_invoice_is_not_served(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $draft = $this->createInvoice($owner, $order);

        $url = URL::temporarySignedRoute('invoices.shared-pdf', now()->addDay(), $draft);

        $this->get($url)->assertNotFound();
    }
}
