<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceDocumentMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string, mixed> $document */
    public function __construct(
        public readonly array $document,
        private readonly string $pdfBytes,
        private readonly string $pdfFilename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('documents.email.invoice_subject', ['number' => $this->document['document']['number']], config('documents.locale')));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.documents.invoice', with: ['document' => $this->document]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdfBytes, $this->pdfFilename)->withMime('application/pdf')];
    }
}
