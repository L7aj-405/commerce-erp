<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\AuthorizesDocumentAction;
use App\Mail\InvoiceDocumentMail;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentPdfService;
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
    ) {}

    public function execute(User $actor, Invoice $invoice, string $recipient, ?string $subject = null, ?string $message = null): void
    {
        $this->authorizeInvoice($actor, $invoice, 'invoices.email');
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => 'A valid recipient email is required.']);
        }

        try {
            $document = $this->pdf->invoice($invoice);
            $this->mail->send(
                $invoice->organization,
                new InvoiceDocumentMail($this->renderer->payload($invoice), $document['bytes'], $document['filename'], $subject, $message),
                $recipient,
            );
            $this->audit->record('invoice.email_sent', $actor, $invoice->organization, $invoice->store, $invoice, newValues: [
                'recipient' => $recipient, 'filename' => $document['filename'],
            ]);
        } catch (Throwable $exception) {
            $this->audit->record('invoice.email_failed', $actor, $invoice->organization, $invoice->store, $invoice, newValues: [
                'recipient' => $recipient, 'exception' => $exception::class,
            ]);
            throw $exception;
        }
    }
}
