<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class UserInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $organizationName,
        public readonly string $roleName,
        public readonly string $acceptUrl,
        public readonly Carbon $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Invitation à rejoindre {$this->organizationName}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.user-invitation');
    }
}
