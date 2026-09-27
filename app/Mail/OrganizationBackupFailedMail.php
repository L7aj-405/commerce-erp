<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\OrganizationBackup;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrganizationBackupFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Organization $organization,
        public readonly OrganizationBackup $backup,
        public readonly string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Échec de sauvegarde automatique — {$this->organization->name}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.organization-backup-failed', with: [
            'organization' => $this->organization,
            'backup' => $this->backup,
            'reason' => $this->reason,
            'settingsUrl' => route('organization-backups.index'),
        ]);
    }
}
