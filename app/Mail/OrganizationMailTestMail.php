<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrganizationMailTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $organizationName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Test de configuration e-mail — {$this->organizationName}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.organization-mail-test', with: ['organizationName' => $this->organizationName]);
    }
}
