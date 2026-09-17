<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\PlatformTestCase;

/**
 * §C — public registration stays available, but a fresh account is
 * unverified and every business route (and organization creation
 * specifically, §C2) is gated behind it server-side (the `verified`
 * middleware — see routes/web.php), not a frontend flag.
 */
class EmailVerificationTest extends PlatformTestCase
{
    public function test_registration_remains_publicly_available(): void
    {
        $this->get(route('register'))->assertOk();
    }

    public function test_a_newly_registered_user_is_unverified_and_receives_a_verification_email(): void
    {
        Notification::fake();

        $this->post(route('register.store'), [
            'name' => 'Nouvel utilisateur',
            'email' => 'nouveau@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertRedirect();

        $user = User::query()->where('email', 'nouveau@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_an_unverified_user_is_redirected_away_from_business_routes_to_the_verification_notice(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('platform.index'))->assertRedirect(route('verification.notice'));
    }

    public function test_an_unverified_user_cannot_create_an_organization(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post(route('organizations.store'), ['name' => 'Ma société'])
            ->assertRedirect(route('verification.notice'));

        $this->assertDatabaseMissing('organizations', ['name' => 'Ma société']);
    }

    public function test_an_unverified_user_can_still_reach_the_verification_notice_and_logout(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('verification.notice'))->assertOk();
        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));
    }

    public function test_visiting_a_valid_signed_verification_link_verifies_the_email_and_unlocks_organization_creation(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('platform.index'));
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->actingAs($user->fresh())->post(route('organizations.store'), ['name' => 'Ma société'])->assertRedirect();
        $this->assertDatabaseHas('organizations', ['name' => 'Ma société']);
    }

    /** Sprint 1.1 §6 audit — expiry comes from the signed URL itself. */
    public function test_an_expired_verification_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    /** Sprint 1.1 §6 audit — resend is throttled (route-level throttle:6,1). */
    public function test_resending_the_verification_email_is_throttled(): void
    {
        $user = User::factory()->unverified()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->post(route('verification.send'));
        }

        $this->actingAs($user)->post(route('verification.send'))->assertStatus(429);
    }

    /** Sprint 1.1 §6 audit — an already-verified account replaying the link is a no-op, never a state change. */
    public function test_replaying_a_valid_link_after_verification_is_a_safe_no_op(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('platform.index'));
        $verifiedAt = $user->fresh()->email_verified_at;

        $this->actingAs($user->fresh())->get($url)->assertRedirect(route('platform.index'));
        $this->assertEquals($verifiedAt, $user->fresh()->email_verified_at);
    }

    public function test_a_tampered_verification_link_hash_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->getKey(),
            'hash' => sha1('someone-else@example.com'),
        ]);

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_a_user_created_via_invitation_acceptance_is_verified_immediately(): void
    {
        Mail::fake();

        $inviter = User::factory()->create();
        $organization = $this->createOrganization($inviter);
        $this->activate($inviter, $organization);
        $role = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();

        $response = $this->actingAs($inviter)->post(route('organization-invitations.store', $organization), [
            'email' => 'invitee@example.com',
            'role_id' => $role->getKey(),
        ])->assertRedirect();
        $token = Str::afterLast(session('invitationUrl'), '/invitations/');

        $this->post(route('invitations.accept.store', $token), [
            'name' => 'Invitee',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertRedirect(route('platform.index'));

        $user = User::query()->where('email', 'invitee@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
    }
}
