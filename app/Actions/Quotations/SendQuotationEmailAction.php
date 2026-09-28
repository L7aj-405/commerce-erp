<?php

namespace App\Actions\Quotations;

use App\Actions\Quotations\Concerns\AuthorizesQuotationAction;
use App\Enums\QuotationStatus;
use App\Mail\QuotationDocumentMail;
use App\Models\DocumentEmailDelivery;
use App\Models\Quotation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentPdfService;
use App\Services\Documents\DocumentEmailRecipientService;
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
        private readonly DocumentEmailRecipientService $recipients,
    ) {}

    /** @param string|array<string, mixed> $recipient */
    public function execute(User $actor, Quotation $quotation, string|array $recipient, ?string $subject = null, ?string $message = null): void
    {
        $this->authorizeQuotation($actor, $quotation, 'quotations.email');

        if ($quotation->status === QuotationStatus::Draft) {
            throw ValidationException::withMessages(['quotation' => 'Seul un devis émis peut être envoyé.']);
        }
        $envelope = $this->recipients->envelope($actor, $quotation->organization, is_array($recipient) ? $recipient : ['email' => $recipient]);
        $subject ??= __('documents.email.quotation_subject', ['number' => $quotation->quotation_number], config('documents.locale'));
        $delivery = $this->delivery($actor, $quotation, $envelope, $subject);

        try {
            $document = $this->pdf->quotation($quotation);
            $this->mail->send(
                $quotation->organization,
                new QuotationDocumentMail($this->renderer->payload($quotation), $document['bytes'], $document['filename'], $subject, $message),
                $envelope['mail_to'],
                $envelope['mail_cc'],
                $envelope['mail_bcc'],
            );
            $delivery->status = DocumentEmailDelivery::STATUS_SENT;
            $delivery->sent_at = now();
            $delivery->save();
            $this->audit->record('quotation.sent', $actor, $quotation->organization, $quotation->store, $quotation, newValues: [
                'to' => $envelope['to'], 'cc' => $envelope['cc'], 'bcc' => $envelope['bcc'], 'filename' => $document['filename'], 'channel' => 'email',
            ]);
        } catch (Throwable $exception) {
            $delivery->status = DocumentEmailDelivery::STATUS_FAILED;
            $delivery->failure_message = $this->recipients->sanitizeFailure($exception);
            $delivery->save();
            $this->audit->record('quotation.email_failed', $actor, $quotation->organization, $quotation->store, $quotation, newValues: [
                'to' => $envelope['to'], 'cc' => $envelope['cc'], 'bcc' => $envelope['bcc'], 'exception' => $exception::class,
            ]);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $envelope */
    private function delivery(User $actor, Quotation $quotation, array $envelope, string $subject): DocumentEmailDelivery
    {
        $delivery = new DocumentEmailDelivery;
        $delivery->organization_id = $quotation->organization_id;
        $delivery->sender_user_id = $actor->getKey();
        $delivery->document_type = Quotation::class;
        $delivery->document_id = $quotation->getKey();
        $delivery->to_recipients = $envelope['to'];
        $delivery->cc_recipients = $envelope['cc'];
        $delivery->bcc_recipients = $envelope['bcc'];
        $delivery->subject = $subject;
        $delivery->status = DocumentEmailDelivery::STATUS_FAILED;
        $delivery->save();

        return $delivery;
    }
}
