<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\AuthorizesDocumentAction;
use App\Mail\InvoiceDocumentMail;
use App\Models\DocumentEmailDelivery;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentPdfService;
use App\Services\Documents\DocumentEmailRecipientService;
use App\Services\InvoiceDocumentRenderer;
use App\Services\OrganizationOutboundMailService;
use Illuminate\Validation\ValidationException;
use Throwable;

class SendInvoiceEmailAction
{
    use AuthorizesDocumentAction;

    public function __construct(
        private readonly DocumentPdfService $pdf,
        private readonly InvoiceDocumentRenderer $renderer,
        private readonly OrganizationOutboundMailService $mail,
        private readonly AuditLogger $audit,
        private readonly DocumentEmailRecipientService $recipients,
    ) {}

    /** @param string|array<string, mixed> $recipient */
    public function execute(User $actor, Invoice $invoice, string|array $recipient, ?string $subject = null, ?string $message = null): void
    {
        $this->authorizeInvoice($actor, $invoice, 'invoices.email');
        $envelope = $this->recipients->envelope($actor, $invoice->organization, is_array($recipient) ? $recipient : ['email' => $recipient]);
        $subject ??= __('documents.email.invoice_subject', ['number' => $invoice->invoice_number], config('documents.locale'));
        $delivery = $this->delivery($actor, $invoice, $envelope, $subject);

        try {
            $document = $this->pdf->invoice($invoice);
            $this->mail->send(
                $invoice->organization,
                new InvoiceDocumentMail($this->renderer->payload($invoice), $document['bytes'], $document['filename'], $subject, $message),
                $envelope['mail_to'],
                $envelope['mail_cc'],
                $envelope['mail_bcc'],
            );
            $delivery->status = DocumentEmailDelivery::STATUS_SENT;
            $delivery->sent_at = now();
            $delivery->save();
            $this->audit->record('invoice.email_sent', $actor, $invoice->organization, $invoice->store, $invoice, newValues: [
                'to' => $envelope['to'], 'cc' => $envelope['cc'], 'bcc' => $envelope['bcc'], 'filename' => $document['filename'],
            ]);
        } catch (Throwable $exception) {
            $delivery->status = DocumentEmailDelivery::STATUS_FAILED;
            $delivery->failure_message = $this->recipients->sanitizeFailure($exception);
            $delivery->save();
            $this->audit->record('invoice.email_failed', $actor, $invoice->organization, $invoice->store, $invoice, newValues: [
                'to' => $envelope['to'], 'cc' => $envelope['cc'], 'bcc' => $envelope['bcc'], 'exception' => $exception::class,
            ]);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $envelope */
    private function delivery(User $actor, Invoice $invoice, array $envelope, string $subject): DocumentEmailDelivery
    {
        $delivery = new DocumentEmailDelivery;
        $delivery->organization_id = $invoice->organization_id;
        $delivery->sender_user_id = $actor->getKey();
        $delivery->document_type = Invoice::class;
        $delivery->document_id = $invoice->getKey();
        $delivery->to_recipients = $envelope['to'];
        $delivery->cc_recipients = $envelope['cc'];
        $delivery->bcc_recipients = $envelope['bcc'];
        $delivery->subject = $subject;
        $delivery->status = DocumentEmailDelivery::STATUS_FAILED;
        $delivery->save();

        return $delivery;
    }
}
