<?php

namespace Tests\Feature\Auth;

use App\Models\OrganizationCloudBackupConnection;
use App\Models\User;
use App\Models\UserSocialIdentity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\PlatformTestCase;

/**
 * Account Settings V1 — "Mot de passe" (Account > Security). Requires the
 * current password as part of the same request (no stale confirm-password
 * timestamp), then rotates the current session and signs out every other
 * one — the same rule a broker-driven password reset already applies (see
 * NewPasswordController).
 */
class PasswordUpdateTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // This feature's session-invalidation behaviour only exists on the
        // `database` driver — see ActiveSessionTest's own doc for why.
        config(['session.driver' => 'database']);
        $this->withFreshAuthentication(level: 1);
    }

    public function test_changing_the_password_requires_the_correct_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/account/password', [
            'current_password' => 'not-the-password',
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_a_correct_current_password_changes_the_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/account/password', [
            'current_password' => 'password',
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertOk();

        $this->assertTrue(Hash::check('a-brand-new-passphrase', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.password_changed']);
    }

    public function test_the_new_password_must_meet_the_centralized_policy(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/account/password', [
            'current_password' => 'password',
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ])->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_changing_the_password_signs_out_every_other_session_but_keeps_the_current_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('security.edit'))->assertOk();
        $currentSessionId = DB::table('sessions')->where('user_id', $user->getKey())->value('id');
        $this->assertNotNull($currentSessionId);

        DB::table('sessions')->insert([
            'id' => 'other-device',
            'user_id' => $user->getKey(),
            'ip_address' => '198.51.100.1',
            'user_agent' => 'Some Other Browser',
            'payload' => base64_encode('x'),
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($user)->patchJson('/account/password', [
            'current_password' => 'password',
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        // The requester's own session id is rotated (defense in depth), but a
        // row for the (now-current) session must still exist.
        $this->assertDatabaseMissing('sessions', ['id' => $currentSessionId]);
        $this->assertDatabaseCount('sessions', 1);
    }

    public function test_password_hashes_are_never_exposed_in_a_validation_error_response(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patchJson('/account/password', [
            'current_password' => 'wrong',
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ]);

        $response->assertStatus(422);
        $this->assertStringNotContainsString($user->password, $response->getContent());
    }

    public function test_google_created_user_can_define_an_initial_erp_password_without_current_password(): void
    {
        $user = User::factory()->create(['password' => null]);
        $this->socialIdentity($user, 'google-sub-initial', 'google@example.com');

        $this->actingAs($user)->postJson('/account/password/initial', [
            'password' => 'first-erp-passphrase',
            'password_confirmation' => 'first-erp-passphrase',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('first-erp-passphrase', $user->password));
        $this->assertNotSame('first-erp-passphrase', $user->password);
        $this->assertSame(1, $user->socialIdentities()->where('provider', UserSocialIdentity::PROVIDER_GOOGLE)->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.initial_password_set', 'actor_id' => $user->id]);
    }

    public function test_initial_password_requires_an_authenticated_session(): void
    {
        $this->postJson('/account/password/initial', [
            'password' => 'first-erp-passphrase',
            'password_confirmation' => 'first-erp-passphrase',
        ])->assertUnauthorized();
    }

    public function test_initial_password_requires_confirmation_and_centralized_policy(): void
    {
        $user = User::factory()->create(['password' => null]);

        $this->actingAs($user)->postJson('/account/password/initial', [
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertJsonValidationErrors('password');

        $this->assertNull($user->fresh()->password);
    }

    public function test_initial_password_endpoint_cannot_replace_an_existing_password(): void
    {
        $user = User::factory()->create(['password' => 'existing-passphrase']);

        $this->actingAs($user)->postJson('/account/password/initial', [
            'password' => 'replacement-passphrase',
            'password_confirmation' => 'replacement-passphrase',
        ])->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('existing-passphrase', $user->fresh()->password));
    }

    public function test_user_can_login_with_email_password_after_initial_password_setup_and_keeps_same_account(): void
    {
        $user = User::factory()->create([
            'email' => 'google-password@example.com',
            'password' => null,
        ]);
        $this->socialIdentity($user, 'google-sub-login', $user->email);

        $this->actingAs($user)->postJson('/account/password/initial', [
            'password' => 'first-erp-passphrase',
            'password_confirmation' => 'first-erp-passphrase',
        ])->assertOk();

        Auth::guard('web')->logout();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'first-erp-passphrase',
        ])->assertRedirect(route('platform.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->where('email', $user->email)->count());
        $this->assertSame(1, UserSocialIdentity::query()->where('provider_user_id', 'google-sub-login')->count());
    }

    public function test_initial_password_does_not_change_memberships_two_factor_or_google_drive_backup_connections(): void
    {
        $user = User::factory()->create(['password' => null]);
        $organization = $this->createOrganization($user);
        $this->socialIdentity($user, 'google-sub-security', $user->email);

        $user->two_factor_secret = 'TOTPSECRET';
        $user->two_factor_confirmed_at = now();
        $user->save();

        $connection = new OrganizationCloudBackupConnection;
        $connection->organization_id = $organization->id;
        $connection->provider = OrganizationCloudBackupConnection::PROVIDER_GOOGLE_DRIVE;
        $connection->provider_account_identifier = 'drive@example.com';
        $connection->access_token = 'drive-access-token';
        $connection->refresh_token = 'drive-refresh-token';
        $connection->token_payload = ['scope' => 'https://www.googleapis.com/auth/drive.file'];
        $connection->is_enabled = true;
        $connection->save();

        $membershipCount = $user->organizationMemberships()->count();

        $this->actingAs($user)->postJson('/account/password/initial', [
            'password' => 'first-erp-passphrase',
            'password_confirmation' => 'first-erp-passphrase',
        ])->assertOk();

        $user->refresh();
        $connection->refresh();
        $this->assertSame($membershipCount, $user->organizationMemberships()->count());
        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());
        $this->assertSame('drive@example.com', $connection->provider_account_identifier);
        $this->assertSame(['scope' => 'https://www.googleapis.com/auth/drive.file'], $connection->token_payload);
        $this->assertSame(1, OrganizationCloudBackupConnection::query()->where('organization_id', $organization->id)->count());
    }

    public function test_initial_password_audit_does_not_store_password_values(): void
    {
        $user = User::factory()->create(['password' => null]);

        $this->actingAs($user)->postJson('/account/password/initial', [
            'password' => 'first-erp-passphrase',
            'password_confirmation' => 'first-erp-passphrase',
        ])->assertOk();

        $audit = DB::table('audit_logs')
            ->where('event', 'auth.initial_password_set')
            ->where('actor_id', $user->id)
            ->first();

        $this->assertNotNull($audit);
        $serializedAuditValues = json_encode([$audit->old_values, $audit->new_values], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('first-erp-passphrase', $serializedAuditValues);
        $this->assertStringNotContainsString($user->fresh()->password, $serializedAuditValues);
    }

    private function socialIdentity(User $user, string $sub, string $email): UserSocialIdentity
    {
        $identity = new UserSocialIdentity;
        $identity->user_id = $user->id;
        $identity->provider = UserSocialIdentity::PROVIDER_GOOGLE;
        $identity->provider_user_id = $sub;
        $identity->provider_email = $email;
        $identity->provider_name = $user->name;
        $identity->save();

        return $identity;
    }
}
