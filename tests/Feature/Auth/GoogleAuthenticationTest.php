<?php

namespace Tests\Feature\Auth;

use App\Models\OrganizationCloudBackupConnection;
use App\Models\User;
use App\Models\UserSocialIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_redirect_uses_identity_scopes_only(): void
    {
        config(['services.google_auth.client_id' => 'auth-client', 'services.google_auth.redirect_uri' => 'https://erp.test/auth/google/callback']);

        $response = $this->get(route('auth.google.redirect'));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('openid+email+profile', $location);
        $this->assertStringNotContainsString('drive.file', $location);
        $this->assertStringNotContainsString('access_type=offline', $location);
        $this->assertNotNull(session('auth.google.state'));
    }

    public function test_new_google_user_signup_creates_user_and_identity(): void
    {
        $this->fakeGoogleIdentity('google-sub-1', 'new@example.com', 'New User');
        session(['auth.google.state' => 'state-ok']);

        $this->get(route('auth.google.callback', ['state' => 'state-ok', 'code' => 'code-ok']))
            ->assertRedirect(route('platform.index'));

        $user = User::query()->where('email', 'new@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('user_social_identities', [
            'user_id' => $user->id,
            'provider' => UserSocialIdentity::PROVIDER_GOOGLE,
            'provider_user_id' => 'google-sub-1',
            'provider_email' => 'new@example.com',
        ]);
    }

    public function test_existing_google_linked_user_can_login(): void
    {
        $user = User::factory()->create(['email' => 'linked@example.com']);
        $this->socialIdentity($user, 'google-sub-linked', 'linked@example.com');
        $this->fakeGoogleIdentity('google-sub-linked', 'linked@example.com', 'Linked User');
        session(['auth.google.state' => 'state-ok']);

        $this->get(route('auth.google.callback', ['state' => 'state-ok', 'code' => 'code-ok']))
            ->assertRedirect(route('platform.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->where('email', 'linked@example.com')->count());
        $this->assertSame(1, UserSocialIdentity::query()->where('provider_user_id', 'google-sub-linked')->count());
    }

    public function test_verified_google_email_safely_links_existing_local_user_without_duplicate(): void
    {
        $user = User::factory()->create(['email' => 'local@example.com', 'password' => 'secret-password']);
        $this->fakeGoogleIdentity('google-sub-local', 'local@example.com', 'Local User');
        session(['auth.google.state' => 'state-ok']);

        $this->get(route('auth.google.callback', ['state' => 'state-ok', 'code' => 'code-ok']))
            ->assertRedirect(route('platform.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->where('email', 'local@example.com')->count());
        $this->assertDatabaseHas('user_social_identities', [
            'user_id' => $user->id,
            'provider_user_id' => 'google-sub-local',
        ]);
        $this->assertNotNull($user->fresh()->password);
    }

    public function test_duplicate_provider_sub_does_not_create_duplicate_identity(): void
    {
        $user = User::factory()->create(['email' => 'one@example.com']);
        $this->socialIdentity($user, 'same-google-sub', 'one@example.com');
        $this->fakeGoogleIdentity('same-google-sub', 'two@example.com', 'Changed Email');
        session(['auth.google.state' => 'state-ok']);

        $this->get(route('auth.google.callback', ['state' => 'state-ok', 'code' => 'code-ok']))
            ->assertRedirect(route('platform.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, UserSocialIdentity::query()->where('provider_user_id', 'same-google-sub')->count());
    }

    public function test_invalid_oauth_state_is_rejected(): void
    {
        session(['auth.google.state' => 'expected']);

        $this->get(route('auth.google.callback', ['state' => 'wrong', 'code' => 'code-ok']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');

        $this->assertGuest();
    }

    public function test_google_denial_is_handled_safely(): void
    {
        session(['auth.google.state' => 'state-ok']);

        $this->get(route('auth.google.callback', ['state' => 'state-ok', 'error' => 'access_denied']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');

        $this->assertGuest();
    }

    public function test_missing_google_identity_is_rejected(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'no-id-token'], 200),
        ]);
        session(['auth.google.state' => 'state-ok']);

        $this->get(route('auth.google.callback', ['state' => 'state-ok', 'code' => 'code-ok']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');

        $this->assertGuest();
    }

    public function test_unverified_google_email_is_rejected(): void
    {
        $this->fakeGoogleIdentity('google-sub-unverified', 'unsafe@example.com', 'Unsafe User', emailVerified: false);
        session(['auth.google.state' => 'state-ok']);

        $this->get(route('auth.google.callback', ['state' => 'state-ok', 'code' => 'code-ok']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'unsafe@example.com']);
    }

    public function test_google_login_for_two_factor_user_stops_at_existing_challenge(): void
    {
        $user = User::factory()->create(['email' => 'secure@example.com']);
        $user->two_factor_secret = 'SECRET';
        $user->two_factor_confirmed_at = now();
        $user->save();
        $this->socialIdentity($user, 'google-sub-secure', 'secure@example.com');
        $this->fakeGoogleIdentity('google-sub-secure', 'secure@example.com', 'Secure User');
        session(['auth.google.state' => 'state-ok']);

        $this->get(route('auth.google.callback', ['state' => 'state-ok', 'code' => 'code-ok']))
            ->assertRedirect(route('two-factor.challenge.show'));

        $this->assertGuest();
        $this->assertSame($user->id, session('login.2fa.user_id'));
    }

    public function test_google_login_does_not_connect_google_drive_backup(): void
    {
        $this->fakeGoogleIdentity('google-sub-auth-only', 'authonly@example.com', 'Auth Only');
        session(['auth.google.state' => 'state-ok']);

        $this->get(route('auth.google.callback', ['state' => 'state-ok', 'code' => 'code-ok']));

        $this->assertSame(0, OrganizationCloudBackupConnection::query()->count());
    }

    public function test_existing_google_drive_connection_does_not_imply_google_auth_identity(): void
    {
        $user = User::factory()->create(['email' => 'drive@example.com']);
        $connection = new OrganizationCloudBackupConnection;
        $connection->organization_id = 999;
        $connection->provider = OrganizationCloudBackupConnection::PROVIDER_GOOGLE_DRIVE;
        $connection->provider_account_identifier = 'drive@example.com';
        $connection->access_token = 'drive-access';
        $connection->refresh_token = 'drive-refresh';
        $connection->is_enabled = true;

        $this->assertSame(0, UserSocialIdentity::query()->where('user_id', $user->id)->count());
    }

    public function test_password_login_still_works_for_password_accounts(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertRedirect(route('platform.index'));

        $this->assertAuthenticatedAs($user);
    }

    private function fakeGoogleIdentity(string $sub, string $email, string $name, bool $emailVerified = true): void
    {
        config([
            'services.google_auth.client_id' => 'auth-client',
            'services.google_auth.client_secret' => 'auth-secret',
            'services.google_auth.redirect_uri' => 'https://erp.test/auth/google/callback',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['id_token' => 'id-token'], 200),
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'auth-client',
                'sub' => $sub,
                'email' => $email,
                'email_verified' => $emailVerified ? 'true' : 'false',
                'name' => $name,
                'picture' => 'https://example.com/avatar.png',
            ], 200),
        ]);
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
