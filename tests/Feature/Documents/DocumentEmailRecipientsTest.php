<?php

namespace Tests\Feature\Documents;

use App\Mail\InvoiceDocumentMail;
use App\Models\DocumentEmailDelivery;
use App\Models\InventoryMovement;
use App\Models\OrganizationContact;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\Support\DocumentTestCase;

class DocumentEmailRecipientsTest extends DocumentTestCase
{
    public function test_invoice_email_supports_multiple_to_cc_bcc_and_records_one_delivery(): void
    {
        Mail::fake();
        [$owner, $organization, , $order] = $this->documentFixture(true);
        $this->configureOrganizationMail($organization);
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $contact = $this->contact($organization->id, 'Finance Client', 'finance@example.test');
        $before = [
            'customer_email' => $invoice->customer_email,
            'inventory_movements' => InventoryMovement::query()->count(),
        ];

        $this->actingAs($owner)->post(route('invoices.email', $invoice), [
            'to' => [
                ['contact_id' => $contact->id],
                ['email' => 'MANUAL@example.test', 'name' => 'Manual Recipient'],
            ],
            'cc' => [['email' => 'copy@example.test']],
            'bcc' => [['email' => 'hidden@example.test']],
            'subject' => 'Votre facture',
            'message' => 'Bonjour, voici la facture.',
        ])->assertRedirect();

        Mail::assertSent(InvoiceDocumentMail::class, 1);
        Mail::assertSent(InvoiceDocumentMail::class, fn (InvoiceDocumentMail $mail) => $mail->hasTo('finance@example.test')
            && $mail->hasTo('manual@example.test')
            && $mail->hasCc('copy@example.test')
            && $mail->hasBcc('hidden@example.test')
            && $mail->attachments()[0]->mime === 'application/pdf');

        $delivery = DocumentEmailDelivery::query()->where('document_type', $invoice::class)->where('document_id', $invoice->id)->firstOrFail();
        $this->assertSame(DocumentEmailDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame('Votre facture', $delivery->subject);
        $this->assertCount(2, $delivery->to_recipients);
        $this->assertCount(1, $delivery->cc_recipients);
        $this->assertCount(1, $delivery->bcc_recipients);
        $this->assertSame($before['customer_email'], $invoice->fresh()->customer_email);
        $this->assertSame($before['inventory_movements'], InventoryMovement::query()->count());
    }

    public function test_duplicate_recipient_normalization_uses_to_then_cc_then_bcc_precedence(): void
    {
        Mail::fake();
        [$owner, $organization, , $order] = $this->documentFixture(true);
        $this->configureOrganizationMail($organization);
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $this->actingAs($owner)->post(route('invoices.email', $invoice), [
            'to' => [['email' => 'client@example.test']],
            'cc' => [['email' => 'CLIENT@example.test'], ['email' => 'copy@example.test']],
            'bcc' => [['email' => 'copy@example.test'], ['email' => 'hidden@example.test']],
        ])->assertRedirect();

        Mail::assertSent(InvoiceDocumentMail::class, 1);
        $delivery = DocumentEmailDelivery::query()->firstOrFail();
        $this->assertSame(['client@example.test'], collect($delivery->to_recipients)->pluck('email')->all());
        $this->assertSame(['copy@example.test'], collect($delivery->cc_recipients)->pluck('email')->all());
        $this->assertSame(['hidden@example.test'], collect($delivery->bcc_recipients)->pluck('email')->all());
    }

    public function test_foreign_organization_contact_is_rejected_and_no_email_is_sent(): void
    {
        Mail::fake();
        [$ownerA, $organizationA, , $orderA] = $this->documentFixture(true);
        $this->configureOrganizationMail($organizationA);
        $invoice = $this->issueInvoice($ownerA, $this->createInvoice($ownerA, $orderA));

        [$ownerB, $organizationB] = $this->documentFixture(true);
        $foreign = $this->contact($organizationB->id, 'Other Org', 'other@example.test');

        $this->actingAs($ownerA)->post(route('invoices.email', $invoice), [
            'to' => [['contact_id' => $foreign->id]],
        ])->assertSessionHasErrors('to.0.contact_id');

        Mail::assertNothingSent();
    }

    public function test_manual_recipient_can_be_saved_as_contact_when_authorized(): void
    {
        Mail::fake();
        [$owner, $organization, , $order] = $this->documentFixture(true);
        $this->configureOrganizationMail($organization);
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $this->actingAs($owner)->post(route('invoices.email', $invoice), [
            'to' => [['email' => 'new-contact@example.test', 'name' => 'New Contact', 'save_as_contact' => true]],
        ])->assertRedirect();

        $this->assertDatabaseHas('organization_contacts', [
            'organization_id' => $organization->id,
            'full_name' => 'New Contact',
            'email' => 'new-contact@example.test',
        ]);
    }

    public function test_send_failure_records_failed_delivery_without_reporting_success(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(true);
        $this->configureOrganizationMail($organization, ['smtp_host' => '127.0.0.1', 'smtp_port' => 1]);
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $this->actingAs($owner)->post(route('invoices.email', $invoice), [
            'to' => [['email' => 'client@example.test']],
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseHas('document_email_deliveries', [
            'organization_id' => $organization->id,
            'document_type' => $invoice::class,
            'document_id' => $invoice->id,
            'status' => DocumentEmailDelivery::STATUS_FAILED,
        ]);
    }

    private function contact(int $organizationId, string $name, string $email): OrganizationContact
    {
        $contact = new OrganizationContact;
        $contact->organization_id = $organizationId;
        $contact->full_name = $name;
        $contact->email = $email;
        $contact->contact_type = 'client';
        $contact->active = true;
        $contact->save();

        return $contact;
    }
}
