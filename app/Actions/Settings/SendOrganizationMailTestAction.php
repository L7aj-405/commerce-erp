<?php

namespace App\Actions\Settings;

use App\Exceptions\Mail\OrganizationMailDeliveryException;
use App\Exceptions\Mail\OrganizationMailNotConfiguredException;
use App\Mail\OrganizationMailTestMail;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrganizationOutboundMailService;
use Illuminate\Support\Facades\Log;

/**
 * Sends a real test email through the organization's configured SMTP
 * account and persists a truthful verified/failed status. Never reports
 * success unless the SMTP send itself completed without error — in
 * particular this never runs through the `log` mailer, since it always goes
 * through OrganizationOutboundMailService, which requires a real, usable
 * organization SMTP configuration.
 */
class SendOrganizationMailTestAction
{
    public function __construct(
        private readonly OrganizationOutboundMailService $mail,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{ok: bool, message: string} */
    public function execute(User $actor, Organization $organization, string $recipient): array
    {
        $setting = $organization->mailSetting()->first();

        try {
            $this->mail->send($organization, new OrganizationMailTestMail($organization->name), $recipient);

            $result = ['ok' => true, 'message' => 'E-mail de test envoyé avec succès.'];
        } catch (OrganizationMailNotConfiguredException $exception) {
            $result = ['ok' => false, 'message' => $exception->getMessage()];
        } catch (OrganizationMailDeliveryException $exception) {
            Log::warning('organization_mail.test_failed', [
                'organization_id' => $organization->getKey(),
                'exception' => $exception->getPrevious()?->getMessage() ?? $exception->getMessage(),
            ]);
            $result = ['ok' => false, 'message' => 'Échec de l\'envoi. Vérifiez votre configuration e-mail.'];
        }

        if ($setting) {
            $setting->forceFill([
                'last_tested_at' => now(),
                'last_test_ok' => $result['ok'],
                'last_test_message' => $result['message'],
            ])->save();
        }

        $this->audit->record('organization_mail_setting.tested', $actor, $organization, auditable: $setting, newValues: [
            'ok' => $result['ok'],
            'recipient' => $recipient,
        ]);

        return $result;
    }
}
