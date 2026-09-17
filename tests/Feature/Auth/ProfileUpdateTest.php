<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\Support\PlatformTestCase;

/**
 * Account Settings V1 — "Mon profil". Name/email are the user's OWN state,
 * never organization membership/role/permissions. Changing the email is
 * security-sensitive: it requires the current password, resets
 * `email_verified_at`, and re-sends the same verification notification a new
 * registration goes through — MustVerifyEmail must never be weakened.
 */
class ProfileUpdateTest extends PlatformTestCase
{
    public function test_a_user_can_update_their_name_without_a_password(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $this->actingAs($user)->patch('/account/profile', [
            'name' => 'New Name',
            'email' => $user->email,
        ])->assertRedirect(route('account.profile.edit'));

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertNotNull($user->email_verified_at, 'an unchanged email must not lose its verified state');
        $this->assertDatabaseHas('audit_logs', ['event' => 'account.profile_updated']);
    }

    public function test_changing_the_email_requires_the_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/account/profile', [
            'name' => $user->name,
            'email' => 'new-address@example.test',
        ])->assertSessionHasErrors('current_password');

        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_changing_the_email_with_the_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/account/profile', [
            'name' => $user->name,
            'email' => 'new-address@example.test',
            'current_password' => 'not-the-password',
        ])->assertSessionHasErrors('current_password');

        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_changing_the_email_with_the_correct_password_resets_verification_and_notifies(): void
    {
        $user = User::factory()->create(['email' => 'old@example.test']);
        $this->assertNotNull($user->email_verified_at);

        $this->actingAs($user)->patch('/account/profile', [
            'name' => $user->name,
            'email' => 'new@example.test',
            'current_password' => 'password',
        ])->assertRedirect(route('account.profile.edit'));

        $user->refresh();
        $this->assertSame('new@example.test', $user->email);
        $this->assertNull($user->email_verified_at, 'a changed email must require re-verification');
        $this->assertDatabaseHas('audit_logs', ['event' => 'account.email_changed']);
    }

    public function test_the_new_email_must_be_unique(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create(['email' => 'taken@example.test']);

        $this->actingAs($user)->patch('/account/profile', [
            'name' => $user->name,
            'email' => $other->email,
            'current_password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_a_verified_user_can_be_redirected_away_from_business_routes_immediately_after_changing_email(): void
    {
        // §10 — MustVerifyEmail is never weakened for a self-service email
        // change: the very next request after the change is routed to the
        // verification-notice screen, exactly like a brand-new registration.
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/account/profile', [
            'name' => $user->name,
            'email' => 'changed@example.test',
            'current_password' => 'password',
        ]);

        $this->actingAs($user)->get(route('platform.index'))->assertRedirect(route('verification.notice'));
    }

    public function test_profile_update_never_touches_organization_membership_or_role(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $membershipBefore = $owner->organizationMemberships()->first();

        $this->actingAs($owner)->patch('/account/profile', [
            'name' => 'Renamed Owner',
            'email' => $owner->email,
        ])->assertRedirect();

        $membershipAfter = $owner->organizationMemberships()->first();
        $this->assertSame($membershipBefore->role_id, $membershipAfter->role_id);
        $this->assertSame($membershipBefore->organization_id, $membershipAfter->organization_id);
        $this->assertSame($membershipBefore->status, $membershipAfter->status);
    }
}
