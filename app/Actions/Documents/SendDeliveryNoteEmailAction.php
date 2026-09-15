<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\AuthorizesDocumentAction;
use App\Mail\DeliveryNoteDocumentMail;
use App\Models\DeliveryNote;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DeliveryNoteDocumentRenderer;
use App\Services\DocumentPdfService;
use App\Services\OrganizationOutboundMailService;
use Illuminate\Validation\ValidationException;
use Throwable;

class SendDeliveryNoteEmailAction
{
    use AuthorizesDocumentAction;

    public function __construct(
        private readonly DocumentPdfService $pdf,
        private readonly DeliveryNoteDocumentRenderer $renderer,
        private readonly OrganizationOutboundMailService $mail,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, DeliveryNote $note, string $recipient): void
    {
        $this->authorizeDeliveryNote($actor, $note, 'delivery_notes.email');
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => 'A valid recipient email is required.']);
        }

        try {
            $document = $this->pdf->deliveryNote($note);
            $this->mail->send(
                $note->organization,
                new DeliveryNoteDocumentMail($this->renderer->payload($note), $document['bytes'], $document['filename']),
                $recipient,
            );
            $this->audit->record('delivery_note.email_sent', $actor, $note->organization, $note->store, $note, newValues: [
                'recipient' => $recipient, 'filename' => $document['filename'],
            ]);
        } catch (Throwable $exception) {
            $this->audit->record('delivery_note.email_failed', $actor, $note->organization, $note->store, $note, newValues: [
                'recipient' => $recipient, 'exception' => $exception::class,
            ]);
            throw $exception;
        }
    }
}
