<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\AuthorizesDocumentAction;
use App\Mail\DeliveryNoteDocumentMail;
use App\Models\DeliveryNote;
use App\Models\DocumentEmailDelivery;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DeliveryNoteDocumentRenderer;
use App\Services\DocumentPdfService;
use App\Services\Documents\DocumentEmailRecipientService;
use App\Services\OrganizationOutboundMailService;
use Throwable;

class SendDeliveryNoteEmailAction
{
    use AuthorizesDocumentAction;

    public function __construct(
        private readonly DocumentPdfService $pdf,
        private readonly DeliveryNoteDocumentRenderer $renderer,
        private readonly OrganizationOutboundMailService $mail,
        private readonly AuditLogger $audit,
        private readonly DocumentEmailRecipientService $recipients,
    ) {}

    /** @param string|array<string, mixed> $recipient */
    public function execute(User $actor, DeliveryNote $note, string|array $recipient, ?string $subject = null, ?string $message = null): void
    {
        $this->authorizeDeliveryNote($actor, $note, 'delivery_notes.email');
        $envelope = $this->recipients->envelope($actor, $note->organization, is_array($recipient) ? $recipient : ['email' => $recipient]);
        $subject ??= __('documents.email.delivery_note_subject', ['number' => $note->delivery_note_number], config('documents.locale'));
        $delivery = $this->delivery($actor, $note, $envelope, $subject);

        try {
            $document = $this->pdf->deliveryNote($note);
            $this->mail->send(
                $note->organization,
                new DeliveryNoteDocumentMail($this->renderer->payload($note), $document['bytes'], $document['filename'], $subject, $message),
                $envelope['mail_to'],
                $envelope['mail_cc'],
                $envelope['mail_bcc'],
            );
            $delivery->status = DocumentEmailDelivery::STATUS_SENT;
            $delivery->sent_at = now();
            $delivery->save();
            $this->audit->record('delivery_note.email_sent', $actor, $note->organization, $note->store, $note, newValues: [
                'to' => $envelope['to'], 'cc' => $envelope['cc'], 'bcc' => $envelope['bcc'], 'filename' => $document['filename'],
            ]);
        } catch (Throwable $exception) {
            $delivery->status = DocumentEmailDelivery::STATUS_FAILED;
            $delivery->failure_message = $this->recipients->sanitizeFailure($exception);
            $delivery->save();
            $this->audit->record('delivery_note.email_failed', $actor, $note->organization, $note->store, $note, newValues: [
                'to' => $envelope['to'], 'cc' => $envelope['cc'], 'bcc' => $envelope['bcc'], 'exception' => $exception::class,
            ]);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $envelope */
    private function delivery(User $actor, DeliveryNote $note, array $envelope, string $subject): DocumentEmailDelivery
    {
        $delivery = new DocumentEmailDelivery;
        $delivery->organization_id = $note->organization_id;
        $delivery->sender_user_id = $actor->getKey();
        $delivery->document_type = DeliveryNote::class;
        $delivery->document_id = $note->getKey();
        $delivery->to_recipients = $envelope['to'];
        $delivery->cc_recipients = $envelope['cc'];
        $delivery->bcc_recipients = $envelope['bcc'];
        $delivery->subject = $subject;
        $delivery->status = DocumentEmailDelivery::STATUS_FAILED;
        $delivery->save();

        return $delivery;
    }
}
