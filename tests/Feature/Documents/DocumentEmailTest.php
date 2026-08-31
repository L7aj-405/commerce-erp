<?php

namespace Tests\Feature\Documents;

use App\Contracts\PdfGenerator;
use App\Mail\DeliveryNoteDocumentMail;
use App\Mail\InvoiceDocumentMail;
use App\Models\InventoryMovement;
use Illuminate\Support\Facades\Mail;
use Tests\Support\DocumentTestCase;

class DocumentEmailTest extends DocumentTestCase
{
    public function test_issued_documents_can_be_emailed_with_pdf_attachments_and_are_audited(): void
    {
        Mail::fake();
        [$owner, , , $order] = $this->documentFixture(true);
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $note = $this->issueDeliveryNote($owner, $this->createDeliveryNote($owner, $order));
        $before = [
            'invoice_status' => $invoice->status->value,
            'invoice_total' => $invoice->total_incl_tax,
            'payment_status' => $order->fresh()->payment_status->value,
            'inventory_movements' => InventoryMovement::query()->count(),
        ];

        $this->actingAs($owner)->post(route('invoices.email', $invoice), ['email' => 'accounts@example.test'])->assertRedirect();
        $this->actingAs($owner)->post(route('invoices.email', $invoice), ['email' => 'accounts@example.test'])->assertRedirect();
        $this->actingAs($owner)->post(route('delivery-notes.email', $note), ['email' => 'warehouse@example.test'])->assertRedirect();

        Mail::assertSent(InvoiceDocumentMail::class, fn ($mail) => $mail->hasTo('accounts@example.test')
            && $mail->attachments()[0]->as === 'Facture-'.$invoice->invoice_number.'.pdf'
            && $mail->attachments()[0]->mime === 'application/pdf');
        Mail::assertSent(InvoiceDocumentMail::class, 2);
        Mail::assertSent(DeliveryNoteDocumentMail::class, fn ($mail) => $mail->hasTo('warehouse@example.test')
            && $mail->attachments()[0]->as === 'Bon-de-Livraison-'.$note->delivery_note_number.'.pdf'
            && $mail->attachments()[0]->mime === 'application/pdf');
        $this->assertDatabaseHas('audit_logs', ['event' => 'invoice.email_sent', 'auditable_id' => $invoice->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'delivery_note.email_sent', 'auditable_id' => $note->id]);
        $this->assertSame($before['invoice_status'], $invoice->fresh()->status->value);
        $this->assertSame($before['invoice_total'], $invoice->fresh()->total_incl_tax);
        $this->assertSame('billing@example.test', $invoice->fresh()->customer_email);
        $this->assertSame($before['payment_status'], $order->fresh()->payment_status->value);
        $this->assertSame($before['inventory_movements'], InventoryMovement::query()->count());
    }

    public function test_draft_email_is_rejected_before_mail_delivery(): void
    {
        Mail::fake();
        [$owner, , , $order] = $this->documentFixture();
        $invoice = $this->createInvoice($owner, $order);

        $this->actingAs($owner)->post(route('invoices.email', $invoice), ['email' => 'accounts@example.test'])->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_pdf_failure_does_not_send_or_mutate_an_issued_document_and_is_audited(): void
    {
        Mail::fake();
        [$owner, , , $order] = $this->documentFixture();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->app->bind(PdfGenerator::class, fn () => new class implements PdfGenerator
        {
            public function generate(string $html): string
            {
                throw new \RuntimeException('Synthetic renderer failure');
            }
        });

        $this->actingAs($owner)->post(route('invoices.email', $invoice), ['email' => 'accounts@example.test'])
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();
        $this->assertSame('issued', $invoice->fresh()->status->value);
        $this->assertDatabaseHas('audit_logs', ['event' => 'invoice.email_failed', 'auditable_id' => $invoice->id]);
    }
}
