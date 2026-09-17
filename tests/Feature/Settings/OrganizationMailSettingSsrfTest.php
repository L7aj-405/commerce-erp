<?php

namespace Tests\Feature\Settings;

use App\Exceptions\Mail\OrganizationMailDeliveryException;
use App\Mail\OrganizationMailTestMail;
use App\Models\User;
use App\Services\OrganizationOutboundMailService;
use Illuminate\Support\Facades\Mail;
use Tests\Support\PlatformTestCase;

/**
 * SSRF hardening for organization SMTP configuration (§A5). Save-time
 * validation is on OrganizationMailSettingController; send-time (defense in
 * depth, and the only guard the "test connection" button and real document
 * emails both go through) is on OrganizationOutboundMailService — see its
 * class doc.
 */
class OrganizationMailSettingSsrfTest extends PlatformTestCase
{
    public function test_saving_smtp_settings_with_a_private_host_is_rejected(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->put(route('email-settings.update'), [
            'sender_name' => 'ACME',
            'sender_email' => 'no-reply@acme.example',
            'smtp_host' => '127.0.0.1',
            'smtp_port' => 25,
            'smtp_username' => 'no-reply@acme.example',
            'smtp_password' => 'secret-password',
            'smtp_encryption' => 'tls',
        ])->assertSessionHasErrors('smtp_host');

        $this->assertDatabaseMissing('organization_mail_settings', ['organization_id' => $organization->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'smtp.destination_rejected']);
    }

    public function test_saving_smtp_settings_whose_host_resolves_to_a_link_local_address_is_rejected(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $this->fakeDns()->map('mail.internal.test', ['169.254.169.254']);

        $this->actingAs($owner)->put(route('email-settings.update'), [
            'sender_name' => 'ACME',
            'sender_email' => 'no-reply@acme.example',
            'smtp_host' => 'mail.internal.test',
            'smtp_port' => 587,
            'smtp_username' => 'no-reply@acme.example',
            'smtp_password' => 'secret-password',
            'smtp_encryption' => 'tls',
        ])->assertSessionHasErrors('smtp_host');
    }

    public function test_a_genuinely_public_smtp_host_saves_normally(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->put(route('email-settings.update'), [
            'sender_name' => 'ACME',
            'sender_email' => 'no-reply@acme.example',
            'smtp_host' => 'smtp.sendgrid.net',
            'smtp_port' => 587,
            'smtp_username' => 'no-reply@acme.example',
            'smtp_password' => 'secret-password',
            'smtp_encryption' => 'tls',
        ])->assertRedirect();

        $this->assertDatabaseHas('organization_mail_settings', ['organization_id' => $organization->id]);
    }

    public function test_sending_is_blocked_at_send_time_if_dns_now_resolves_privately(): void
    {
        // Configured while the host was still public (DNS-rebinding
        // scenario) — the send path must re-validate, not trust the save.
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $setting = $this->configureOrganizationMail($organization, ['smtp_host' => 'mail.example.com']);
        $this->fakeDns()->map('mail.example.com', ['127.0.0.1']);

        Mail::fake();

        $this->expectException(OrganizationMailDeliveryException::class);
        app(OrganizationOutboundMailService::class)->send($organization, new OrganizationMailTestMail($organization->name), 'someone@example.com');
    }
}
