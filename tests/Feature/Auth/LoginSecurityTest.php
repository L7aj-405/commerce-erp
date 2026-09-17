<?php

namespace Tests\Feature\Auth;

use App\Contracts\ChallengeVerifier;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeChallengeVerifier;
use Tests\Support\PlatformTestCase;

/**
 * §D — progressive login-failure protection. Keyed by email+IP together (see
 * LoginThrottle), never a permanent lock, challenge after 3 failures,
 * cooldown after 5, enumeration-safe messaging throughout.
 */
class LoginSecurityTest extends PlatformTestCase
{
    private function attemptWrongPassword(string $email): TestResponse
    {
        return $this->post(route('login.store'), ['email' => $email, 'password' => 'not-the-password']);
    }

    public function test_the_first_two_failures_behave_normally_and_a_correct_third_attempt_needs_no_challenge(): void
    {
        // The threshold is ">= 3 recorded failures" — after only 2, the next
        // (correct) attempt must succeed on the password alone.
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $this->app->instance(ChallengeVerifier::class, new FakeChallengeVerifier(passes: false));

        $this->attemptWrongPassword($user->email)->assertSessionHasErrors('email');
        $this->attemptWrongPassword($user->email)->assertSessionHasErrors('email');

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('platform.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_after_three_failures_a_challenge_is_required_even_with_the_correct_password(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $this->app->instance(ChallengeVerifier::class, new FakeChallengeVerifier(passes: false));

        $this->attemptWrongPassword($user->email);
        $this->attemptWrongPassword($user->email);
        $this->attemptWrongPassword($user->email);

        // 4th attempt: correct password, but the (failing) challenge blocks it.
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('challenge');

        $this->assertGuest();
    }

    public function test_a_passing_challenge_lets_the_correct_password_through_after_three_failures(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);

        $this->attemptWrongPassword($user->email);
        $this->attemptWrongPassword($user->email);
        $this->attemptWrongPassword($user->email);

        $this->app->instance(ChallengeVerifier::class, new FakeChallengeVerifier(passes: true));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('platform.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_five_or_more_failures_trigger_a_temporary_cooldown_that_blocks_even_the_correct_password(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $this->app->instance(ChallengeVerifier::class, new FakeChallengeVerifier(passes: true));

        for ($i = 0; $i < 5; $i++) {
            $this->attemptWrongPassword($user->email);
        }

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_the_cooldown_is_never_permanent_and_recovers_on_its_own(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $this->app->instance(ChallengeVerifier::class, new FakeChallengeVerifier(passes: true));

        for ($i = 0; $i < 5; $i++) {
            $this->attemptWrongPassword($user->email);
        }
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        // First cooldown tier is 60 seconds — travel past it.
        $this->travel(61)->seconds();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('platform.index'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_successful_login_clears_the_failure_count_for_that_identifier(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $this->app->instance(ChallengeVerifier::class, new FakeChallengeVerifier(passes: true));

        $this->attemptWrongPassword($user->email);
        $this->attemptWrongPassword($user->email);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('platform.index'));

        // A fresh, unrelated failure afterward starts from zero again — not
        // "3rd failure overall" (would immediately demand a challenge).
        auth()->logout();
        $this->app->instance(ChallengeVerifier::class, new FakeChallengeVerifier(passes: false));
        $this->attemptWrongPassword($user->email)->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_failure_messages_never_distinguish_unknown_email_from_wrong_password(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $genericMessage = 'Ces identifiants ne correspondent à aucun compte.';

        $this->attemptWrongPassword('nobody-registered@example.com')
            ->assertSessionHasErrors(['email' => $genericMessage]);
        $this->attemptWrongPassword($user->email)
            ->assertSessionHasErrors(['email' => $genericMessage]);
    }

    /**
     * Sprint 1.1 §2 — LoginThrottle keys on `$request->ip()`. The default
     * test-client REMOTE_ADDR (127.0.0.1) is not within the trusted private
     * ranges TrustedProxyRanges configures (see bootstrap/app.php), so an
     * X-Forwarded-For claimed by that direct connection must be ignored —
     * an attacker rotating it on every request must not be able to reset
     * the failure count and dodge the cooldown.
     */
    public function test_a_spoofed_forwarded_for_header_cannot_be_used_to_bypass_the_cooldown(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $this->app->instance(ChallengeVerifier::class, new FakeChallengeVerifier(passes: true));

        for ($i = 0; $i < 5; $i++) {
            $this->call('POST', route('login.store'), [
                'email' => $user->email,
                'password' => 'not-the-password',
            ], [], [], ['HTTP_X_FORWARDED_FOR' => "10.0.0.{$i}"]);
        }

        $this->call('POST', route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ], [], [], ['HTTP_X_FORWARDED_FOR' => '10.0.0.99'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_failure_tracking_is_scoped_per_email_so_one_account_cannot_be_denial_of_service_locked_by_targeting_another(): void
    {
        $victim = User::factory()->create(['email' => 'victim@example.com']);
        $this->app->instance(ChallengeVerifier::class, new FakeChallengeVerifier(passes: true));

        // An attacker fails a DIFFERENT account 5 times from the same IP...
        $attacker = User::factory()->create(['email' => 'attacker-target@example.com']);
        for ($i = 0; $i < 5; $i++) {
            $this->attemptWrongPassword($attacker->email);
        }

        // ...the victim's own account, from the same IP, is unaffected.
        $this->post(route('login.store'), ['email' => $victim->email, 'password' => 'password'])
            ->assertRedirect(route('platform.index'));
        $this->assertAuthenticatedAs($victim);
    }
}
