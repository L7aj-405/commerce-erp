<?php

namespace Tests\Feature\Settings;

use App\Mail\OrganizationMailTestMail;
use App\Models\AuditLog;
use App\Models\OrganizationMailSetting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PlatformTestCase;

class OrganizationMailSettingTest extends PlatformTestCase
{
    public function test_organization_admin_can_save_smtp_configuration(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->put(route('email-settings.update'), [
            'sender_name' => 'AV Professional',
            'sender_email' => 'info@avprofessional.example',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_username' => 'info@avprofessional.example',
            'smtp_password' => 'correct-horse-battery-staple',
            'smtp_encryption' => 'tls',
        ])->assertRedirect();

        $setting = OrganizationMailSetting::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame('AV Professional', $setting->sender_name);
        $this->assertSame('info@avprofessional.example', $setting->sender_email);
        $this->assertTrue($setting->isUsable());
    }

    public function test_password_is_stored_encrypted_not_plaintext(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $setting = $this->configureOrganizationMail($organization, ['smtp_password' => 'super-secret-value']);

        $raw = $setting->getRawOriginal('smtp_password');
        $this->assertNotSame('super-secret-value', $raw);
        $this->assertStringNotContainsString('super-secret-value', (string) $raw);
        // The encrypted cast round-trips transparently through the model.
        $this->assertSame('super-secret-value', $setting->fresh()->smtp_password);
    }

    public function test_password_is_never_returned_to_the_frontend(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $this->configureOrganizationMail($organization);

        $this->actingAs($owner)->get(route('email-settings.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('setting.has_password', true)
                ->missing('setting.smtp_password'));
    }

    public function test_blank_password_on_edit_preserves_existing_secret(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $setting = $this->configureOrganizationMail($organization, ['smtp_password' => 'original-secret']);

        $this->actingAs($owner)->put(route('email-settings.update'), [
            'sender_name' => $setting->sender_name,
            'sender_email' => $setting->sender_email,
            'smtp_host' => $setting->smtp_host,
            'smtp_port' => $setting->smtp_port,
            'smtp_username' => $setting->smtp_username,
            'smtp_password' => '',
            'smtp_encryption' => $setting->smtp_encryption,
        ])->assertRedirect();

        $this->assertSame('original-secret', $setting->fresh()->smtp_password);
    }

    public function test_new_password_replaces_the_existing_secret(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $setting = $this->configureOrganizationMail($organization, ['smtp_password' => 'original-secret']);

        $this->actingAs($owner)->put(route('email-settings.update'), [
            'sender_name' => $setting->sender_name,
            'sender_email' => $setting->sender_email,
            'smtp_host' => $setting->smtp_host,
            'smtp_port' => $setting->smtp_port,
            'smtp_username' => $setting->smtp_username,
            'smtp_password' => 'brand-new-secret',
            'smtp_encryption' => $setting->smtp_encryption,
        ])->assertRedirect();

        $this->assertSame('brand-new-secret', $setting->fresh()->smtp_password);
    }

    public function test_organization_a_cannot_access_organization_b_mail_configuration(): void
    {
        $ownerA = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'Org A');
        $this->configureOrganizationMail($organizationA, ['sender_email' => 'a@example.test']);

        $ownerB = User::factory()->create();
        $organizationB = $this->createOrganization($ownerB, 'Org B');
        $this->configureOrganizationMail($organizationB, ['sender_email' => 'b@example.test']);

        $this->activate($ownerA, $organizationA);
        $response = $this->actingAs($ownerA)->get(route('email-settings.edit'))->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('setting.sender_email', 'a@example.test'));
    }

    public function test_settings_view_permission_is_read_only_for_email_configuration(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $viewer, ['settings.view']);
        $this->activate($viewer, $organization);

        $this->actingAs($viewer)->get(route('email-settings.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canUpdate', false));

        $this->actingAs($viewer)->put(route('email-settings.update'), [
            'sender_name' => 'X', 'sender_email' => 'x@example.test', 'smtp_host' => 'h', 'smtp_port' => 587,
            'smtp_username' => 'u', 'smtp_password' => 'p', 'smtp_encryption' => 'tls',
        ])->assertForbidden();
    }

    public function test_send_test_email_success_path_is_truthful(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $this->configureOrganizationMail($organization);

        $response = $this->actingAs($owner)->postJson(route('email-settings.test'), ['email' => 'someone@example.test'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        Mail::assertSent(OrganizationMailTestMail::class, fn ($mail) => $mail->hasTo('someone@example.test'));
        $this->assertTrue((bool) $response->json('ok'));
    }

    public function test_send_test_email_failure_path_never_claims_success(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        // Unroutable host: a real SMTP attempt must fail, not silently pass
        // like the old `log` mailer did.
        $this->configureOrganizationMail($organization, ['smtp_host' => '127.0.0.1', 'smtp_port' => 1]);

        $response = $this->actingAs($owner)->postJson(route('email-settings.test'), ['email' => 'someone@example.test'])
            ->assertOk();

        $this->assertFalse((bool) $response->json('ok'));
        $this->assertSame("Échec de l'envoi. Vérifiez votre configuration e-mail.", $response->json('message'));
    }

    public function test_missing_configuration_reports_configuration_required_not_success(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $response = $this->actingAs($owner)->postJson(route('email-settings.test'), ['email' => 'someone@example.test'])
            ->assertOk();

        $this->assertFalse((bool) $response->json('ok'));
    }

    public function test_audit_log_never_contains_the_smtp_password(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->put(route('email-settings.update'), [
            'sender_name' => 'AV Professional',
            'sender_email' => 'info@avprofessional.example',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_username' => 'info@avprofessional.example',
            'smtp_password' => 'do-not-leak-me',
            'smtp_encryption' => 'tls',
        ])->assertRedirect();

        $logs = AuditLog::query()->where('event', 'organization_mail_setting.created')->get();
        $this->assertNotEmpty($logs);
        foreach ($logs as $log) {
            $payload = json_encode([$log->old_values, $log->new_values]);
            $this->assertStringNotContainsString('do-not-leak-me', (string) $payload);
        }
    }
}
