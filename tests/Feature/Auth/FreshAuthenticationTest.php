<?php

namespace Tests\Feature\Auth;

use App\Models\TrustedTwoFactorDevice;
use App\Models\User;
use App\Models\UserSocialIdentity;
use App\Services\Security\RecoveryCodeService;
use Illuminate\Support\Facades\Http;
use Tests\Support\PlatformTestCase;

class FreshAuthenticationTest extends PlatformTestCase
{
    private const RECOVERY_CODE = 'FRESH-CODE';

    private function enabledUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => app(RecoveryCodeService::class)->hash([self::RECOVERY_CODE]),
        ])->save();

        return $user->fresh();
    }

    public function test_sensitive_action_requires_confirmation_and_valid_password_grants_the_window(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('referer', route('security.sessions.index'))
            ->delete(route('security.sessions.destroy-others'))
            ->assertRedirect(route('security.confirm'));

        $this->post(route('security.confirm.password'), ['password' => 'password'])
            ->assertRedirect('/security/sessions');

        $this->delete(route('security.sessions.destroy-others'))->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.fresh_confirmed', 'actor_id' => $user->getKey()]);
    }

    public function test_invalid_password_is_rejected_and_confirmation_is_rate_limited(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->delete(route('security.sessions.destroy-others'));

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post(route('security.confirm.password'), ['password' => 'wrong-password'])
                ->assertSessionHasErrors('password');
        }

        $this->post(route('security.confirm.password'), ['password' => 'wrong-password'])
            ->assertTooManyRequests();
    }

    public function test_fresh_authentication_expires_after_the_configured_window(): void
    {
        config(['security.fresh_auth_timeout_minutes' => 15]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.fresh.account_confirmed_at' => time() - 901])
            ->delete(route('security.sessions.destroy-others'))
            ->assertRedirect(route('security.confirm'));
    }

    public function test_level_two_requires_fresh_two_factor_and_trusted_device_does_not_bypass_it(): void
    {
        $user = $this->enabledUser();
        $token = bin2hex(random_bytes(32));
        $device = new TrustedTwoFactorDevice;
        $device->user_id = $user->getKey();
        $device->token_hash = hash('sha256', $token);
        $device->expires_at = now()->addDays(15);
        $device->save();

        $this->actingAs($user)
            ->withCookie(config('two-factor.trusted_device_cookie'), $device->getKey().'|'.$token)
            ->withSession(['auth.fresh.account_confirmed_at' => time()])
            ->delete(route('security.trusted-devices.destroy-all'))
            ->assertRedirect(route('security.confirm'));

        $this->post(route('security.confirm.two-factor'), ['code' => self::RECOVERY_CODE])
            ->assertRedirect();
        $this->delete(route('security.trusted-devices.destroy-all'))->assertRedirect();
        $this->assertNotNull($device->fresh()->revoked_at);
    }

    public function test_google_only_account_can_use_explicit_matching_google_reauthentication(): void
    {
        $user = User::factory()->create(['password' => null, 'email' => 'google@example.test']);
        $identity = new UserSocialIdentity;
        $identity->user_id = $user->getKey();
        $identity->provider = UserSocialIdentity::PROVIDER_GOOGLE;
        $identity->provider_user_id = 'google-subject-1';
        $identity->provider_email = $user->email;
        $identity->save();

        $this->actingAs($user)->delete(route('security.sessions.destroy-others'))
            ->assertRedirect(route('security.confirm'));
        $redirect = $this->get(route('security.confirm.google'))->assertRedirect();
        $this->assertStringContainsString('prompt=login', $redirect->headers->get('Location'));
        $this->assertStringContainsString('max_age=0', $redirect->headers->get('Location'));
        $state = session('auth.fresh.google_state');

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['id_token' => 'fresh-id-token']),
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => config('services.google_auth.client_id'),
                'sub' => 'google-subject-1',
                'email' => $user->email,
                'email_verified' => true,
            ]),
        ]);

        $this->get(route('security.confirm.google.callback', ['state' => $state, 'code' => 'fresh-code']))
            ->assertRedirect();
        $this->delete(route('security.sessions.destroy-others'))->assertRedirect();
    }

    public function test_google_reauthentication_rejects_a_different_google_identity(): void
    {
        $user = User::factory()->create(['password' => null]);
        $identity = new UserSocialIdentity;
        $identity->user_id = $user->getKey();
        $identity->provider = UserSocialIdentity::PROVIDER_GOOGLE;
        $identity->provider_user_id = 'expected-subject';
        $identity->save();

        $this->actingAs($user)->delete(route('security.sessions.destroy-others'));
        $this->get(route('security.confirm.google'));
        $state = session('auth.fresh.google_state');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['id_token' => 'fresh-id-token']),
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => config('services.google_auth.client_id'),
                'sub' => 'attacker-subject',
                'email' => 'attacker@example.test',
                'email_verified' => true,
            ]),
        ]);

        $this->get(route('security.confirm.google.callback', ['state' => $state, 'code' => 'fresh-code']))
            ->assertSessionHasErrors('google');
        $this->delete(route('security.sessions.destroy-others'))
            ->assertRedirect(route('security.confirm'));
    }

    public function test_external_referer_is_never_used_as_the_post_confirmation_redirect(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->withHeader('referer', 'https://attacker.example/phishing')
            ->delete(route('security.sessions.destroy-others'));

        $this->post(route('security.confirm.password'), ['password' => 'password'])
            ->assertRedirect(route('security.edit'));
    }

    public function test_ordinary_erp_routes_do_not_require_fresh_authentication(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('platform.index'))->assertOk();
    }

    public function test_authorization_and_tenant_scope_are_checked_before_fresh_authentication(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $sales = User::factory()->create();
        $this->addOrganizationMember($organization, $sales, [], roleName: 'Sales');
        $this->activate($sales, $organization);

        $this->actingAs($sales)->post(route('roles.store'), ['name' => 'Forbidden Role'])
            ->assertForbidden();
        $this->assertDatabaseMissing('roles', ['organization_id' => $organization->getKey(), 'name' => 'Forbidden Role']);
    }
}
