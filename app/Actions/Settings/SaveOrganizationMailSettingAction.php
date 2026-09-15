<?php

namespace App\Actions\Settings;

use App\Models\Organization;
use App\Models\OrganizationMailSetting;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Creates or updates an organization's outbound email configuration. The
 * SMTP password is only written when a fresh non-empty value is supplied —
 * a blank password on an existing configuration preserves the current
 * encrypted secret. It is stored via the model's `encrypted` cast and never
 * returned to the client or written to the audit log.
 */
class SaveOrganizationMailSettingAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Organization $organization, array $data): OrganizationMailSetting
    {
        $setting = $organization->mailSetting()->first() ?? new OrganizationMailSetting;
        $creating = ! $setting->exists;
        $setting->organization_id = $organization->getKey();

        foreach ([
            'sender_name', 'sender_email', 'smtp_host', 'smtp_port', 'smtp_username',
            'smtp_encryption', 'reply_to_email', 'reply_to_name', 'is_enabled',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $setting->{$field} = $data[$field];
            }
        }

        $passwordChanged = false;
        if (filled($data['smtp_password'] ?? null)) {
            $setting->smtp_password = $data['smtp_password'];
            $passwordChanged = true;
        }

        // Any credential or transport change invalidates the last test result —
        // a stale "verified" status must never survive a settings edit.
        if ($passwordChanged || $this->transportFieldsChanged($setting, $data)) {
            $setting->last_tested_at = null;
            $setting->last_test_ok = null;
            $setting->last_test_message = null;
        }

        $setting->save();

        $this->audit->record(
            $creating ? 'organization_mail_setting.created' : 'organization_mail_setting.updated',
            $actor, $organization, auditable: $setting,
            newValues: [
                'sender_name' => $setting->sender_name,
                'sender_email' => $setting->sender_email,
                'smtp_host' => $setting->smtp_host,
                'smtp_port' => $setting->smtp_port,
                'smtp_encryption' => $setting->smtp_encryption,
                'is_enabled' => $setting->is_enabled,
                'password_replaced' => $passwordChanged,
            ],
        );

        return $setting;
    }

    /** @param array<string, mixed> $data */
    private function transportFieldsChanged(OrganizationMailSetting $setting, array $data): bool
    {
        foreach (['smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption'] as $field) {
            if (array_key_exists($field, $data) && $setting->isDirty($field)) {
                return true;
            }
        }

        return false;
    }
}
