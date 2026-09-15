<?php

namespace App\Actions\Quotations;

use App\Actions\Quotations\Concerns\AuthorizesQuotationAction;
use App\Enums\QuotationStatus;
use App\Mail\QuotationDocumentMail;
use App\Models\Quotation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentPdfService;
use App\Services\OrganizationOutboundMailService;
use App\Services\QuotationDocumentRenderer;
use Illuminate\Validation\ValidationException;
use Throwable;

class SendQuotationEmailAction
{
    use AuthorizesQuotationAction;

    public function __construct(
        private readonly DocumentPdfService $pdf,
        private readonly QuotationDocumentRenderer $renderer,
        private readonly OrganizationOutboundMailService $mail,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, Quotation $quotation, string $recipient, ?string $subject = null, ?string $message = null): void
    {
        $this->authorizeQuotation($actor, $quotation, 'quotations.email');

        if ($quotation->status === QuotationStatus::Draft) {
            throw ValidationException::withMessages(['quotation' => 'Seul un devis émis peut être envoyé.']);
        }
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => 'Une adresse email valide est requise.']);
        }

        try {
            $document = $this->pdf->quotation($quotation);
            $this->mail->send(
                $quotation->organization,
                new QuotationDocumentMail($this->renderer->payload($quotation), $document['bytes'], $document['filename'], $subject, $message),
                $recipient,
            );
            $this->audit->record('quotation.sent', $actor, $quotation->organization, $quotation->store, $quotation, newValues: [
                'recipient' => $recipient, 'filename' => $document['filename'], 'channel' => 'email',
            ]);
        } catch (Throwable $exception) {
            $this->audit->record('quotation.email_failed', $actor, $quotation->organization, $quotation->store, $quotation, newValues: [
                'recipient' => $recipient, 'exception' => $exception::class,
            ]);
            throw $exception;
        }
    }
}
