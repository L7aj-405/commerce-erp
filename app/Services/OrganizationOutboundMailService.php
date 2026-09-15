<?php

namespace App\Services;

use App\Exceptions\Mail\OrganizationMailDeliveryException;
use App\Exceptions\Mail\OrganizationMailNotConfiguredException;
use App\Models\Organization;
use App\Models\OrganizationMailSetting;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends a Mailable through an organization's own SMTP configuration instead
 * of the framework's global MAIL_* mailer. Multi-tenant by design: every
 * send resolves the organization's credentials fresh from the database and
 * builds a throwaway, uniquely-named mailer for that single send only — the
 * transient `mail.mailers.*` config entry and the Mail manager's resolved-
 * mailer cache entry are both removed immediately afterwards (in a finally
 * block), so no organization's credentials can be reused or leaked into a
 * later send for a different organization within the same PHP process.
 *
 * Document email (Invoice/Devis/Bon de livraison) is currently sent
 * synchronously within the request, so this per-send isolation is all that's
 * required. If a caller ever moves a send behind a queued job, the job must
 * still resolve the organization's settings itself when it runs (never
 * serialize an OrganizationMailSetting or its password into the job payload)
 * so this same guarantee holds for a long-running queue worker.
 */
class OrganizationOutboundMailService
{
    public function isConfigured(Organization $organization): bool
    {
        return $organization->mailSetting()->first()?->isUsable() ?? false;
    }

    /**
     * @param  string|array<int, string>  $to
     *
     * @throws OrganizationMailNotConfiguredException
     * @throws OrganizationMailDeliveryException
     */
    public function send(Organization $organization, Mailable $mailable, string|array $to): void
    {
        $setting = $organization->mailSetting()->first();

        if (! $setting || ! $setting->isUsable()) {
            throw new OrganizationMailNotConfiguredException;
        }

        $this->deliver($setting, $mailable, $to);
    }

    /**
     * @throws OrganizationMailDeliveryException
     */
    private function deliver(OrganizationMailSetting $setting, Mailable $mailable, string|array $to): void
    {
        $mailerName = 'org_smtp_'.$setting->organization_id.'_'.Str::random(16);

        config(["mail.mailers.{$mailerName}" => [
            'transport' => 'smtp',
            'host' => $setting->smtp_host,
            'port' => $setting->smtp_port,
            'username' => $setting->smtp_username,
            'password' => $setting->smtp_password,
            'encryption' => $setting->smtp_encryption === 'none' ? null : $setting->smtp_encryption,
            'timeout' => 30,
        ]]);

        $mailable->from($setting->sender_email, $setting->sender_name);
        if (filled($setting->reply_to_email)) {
            $mailable->replyTo($setting->reply_to_email, $setting->reply_to_name ?: null);
        }

        try {
            Mail::mailer($mailerName)->to($to)->send($mailable);
        } catch (Throwable $exception) {
            throw new OrganizationMailDeliveryException($exception);
        } finally {
            $mailers = config('mail.mailers');
            unset($mailers[$mailerName]);
            config(['mail.mailers' => $mailers]);

            // Mail::fake() swaps in a fake manager that has no purge() method —
            // there is nothing to purge in that case, and nothing to guard against.
            $manager = app('mail.manager');
            if (method_exists($manager, 'purge')) {
                $manager->purge($mailerName);
            }
        }
    }
}
