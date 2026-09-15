<?php

namespace App\Models;

use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationMailSetting extends Model
{
    use ScopesToActiveOrganization;

    protected $table = 'organization_mail_settings';

    protected $guarded = ['*'];

    /** The SMTP secret is never serialised to the client. */
    protected $hidden = ['smtp_password'];

    protected function casts(): array
    {
        return [
            'smtp_password' => 'encrypted',
            'is_enabled' => 'boolean',
            'last_test_ok' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Whether this configuration has everything required to attempt a send.
     * Does not imply a successful test — only that a send would be attempted
     * rather than rejected outright.
     */
    public function isUsable(): bool
    {
        return $this->is_enabled
            && filled($this->sender_email)
            && filled($this->smtp_host)
            && filled($this->smtp_port)
            && filled($this->smtp_username)
            && filled($this->getRawOriginal('smtp_password'));
    }
}
