<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Sprint 1.1 §3 — forgot/reset password. Uses Laravel's native Password
 * Broker (expiring, single-use, hashed tokens in `password_reset_tokens`),
 * so most of the hard security properties (expiry, single-use, per-user
 * binding) come from the framework itself — these tests confirm the
 * anti-enumeration response and this app's own additions (session
 * invalidation, audit log, centralized password policy).
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_forgot_password_response_is_identical_for_an_existing_and_a_nonexistent_email(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'exists@example.test']);

        $existing = $this->post('/forgot-password', ['email' => 'exists@example.test']);
        $missing = $this->post('/forgot-password', ['email' => 'nobody@example.test']);

        $existing->assertRedirect();
        $missing->assertRedirect();
        $this->assertSame($existing->getSession()->get('status'), $missing->getSession()->get('status'));
        $missing->assertSessionHasNoErrors();

        Notification::assertSentTo(User::query()->where('email', 'exists@example.test')->firstOrFail(), ResetPassword::class);
    }

    public function test_a_valid_token_resets_the_password_and_logs_out_other_sessions(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password-value')]);
        DB::table('sessions')->insert([
            'id' => 'other-session-id',
            'user_id' => $user->getKey(),
            'ip_address' => '203.0.113.9',
            'user_agent' => 'Other Device',
            'payload' => base64_encode('x'),
            'last_activity' => now()->timestamp,
        ]);

        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'un-nouveau-mot-de-passe',
            'password_confirmation' => 'un-nouveau-mot-de-passe',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('un-nouveau-mot-de-passe', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session-id']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.password_reset']);
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        // config('auth.passwords.users.expire') is 60 minutes.
        DB::table('password_reset_tokens')->where('email', $user->email)->update([
            'created_at' => now()->subMinutes(61),
        ]);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'un-nouveau-mot-de-passe',
            'password_confirmation' => 'un-nouveau-mot-de-passe',
        ])->assertSessionHasErrors('email');

        $this->assertFalse(Hash::check('un-nouveau-mot-de-passe', $user->fresh()->password));
    }

    public function test_a_token_cannot_be_reused_after_a_successful_reset(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'premiere-reinitialisation',
            'password_confirmation' => 'premiere-reinitialisation',
        ])->assertRedirect(route('login'));

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'deuxieme-tentative-ici',
            'password_confirmation' => 'deuxieme-tentative-ici',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('premiere-reinitialisation', $user->fresh()->password));
    }

    public function test_the_reset_endpoint_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->post('/reset-password', [
                'token' => 'not-a-real-token-'.$i,
                'email' => $user->email,
                'password' => 'un-nouveau-mot-de-passe',
                'password_confirmation' => 'un-nouveau-mot-de-passe',
            ]);
        }

        $this->post('/reset-password', [
            'token' => 'not-a-real-token-final',
            'email' => $user->email,
            'password' => 'un-nouveau-mot-de-passe',
            'password_confirmation' => 'un-nouveau-mot-de-passe',
        ])->assertStatus(429);
    }

    public function test_password_rules_match_registration_on_the_reset_endpoint(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        // Below the centralized Password::defaults() minimum (10).
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ])->assertSessionHasErrors('password');
    }

    public function test_reset_tokens_and_passwords_are_never_logged(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'un-nouveau-mot-de-passe-log',
            'password_confirmation' => 'un-nouveau-mot-de-passe-log',
        ]);

        $log = DB::table('audit_logs')->where('event', 'auth.password_reset')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString($token, (string) $log->new_values);
        $this->assertStringNotContainsString('un-nouveau-mot-de-passe-log', (string) $log->new_values);
    }
}
