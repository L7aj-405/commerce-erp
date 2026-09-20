<?php

namespace Tests\Feature\UsersRolesPermissions;

use App\Mail\UserInvitationMail;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PlatformTestCase;

class InvitationTest extends PlatformTestCase
{
    public function test_admin_can_invite_a_brand_new_user_by_email_and_they_can_accept(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $role = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();
        $response = $this->actingAs($owner)->post(route('organization-invitations.store', $organization), [
            'email' => 'new.teammate@example.test',
            'role_id' => $role->id,
        ])->assertRedirect();
        $response->assertSessionHas('invitationUrl');

        $invitation = UserInvitation::query()->where('email', 'new.teammate@example.test')->firstOrFail();
        $this->assertSame($organization->getKey(), $invitation->organization_id);
        $this->assertSame($role->getKey(), $invitation->role_id);
        $this->assertNull($invitation->accepted_at);
        $this->assertNotNull($invitation->expires_at);

        $token = $this->extractToken($response);
        // The plaintext token is never persisted — only its hash.
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);

        Mail::assertSent(UserInvitationMail::class, fn ($mail) => $mail->hasTo('new.teammate@example.test'));

        $this->assertDatabaseMissing('users', ['email' => 'new.teammate@example.test']);

        $this->get(route('invitations.accept', $token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('status', 'pending')
                ->where('userExists', false)
                ->where('invitation.email', 'new.teammate@example.test'));

        $this->post(route('invitations.accept.store', $token), [
            'name' => 'New Teammate',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ])->assertRedirect(route('platform.index'));

        $user = User::query()->where('email', 'new.teammate@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);

        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->firstOrFail();
        $this->assertSame($role->getKey(), $membership->role_id);
        $this->assertSame('active', $membership->status);

        $invitation->refresh();
        $this->assertNotNull($invitation->accepted_at);
        $this->assertSame($user->getKey(), $invitation->accepted_by_user_id);
    }

    public function test_invitation_cannot_be_redeemed_twice(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $role = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();

        $response = $this->actingAs($owner)->post(route('organization-invitations.store', $organization), [
            'email' => 'once@example.test',
            'role_id' => $role->id,
        ]);
        $token = $this->extractToken($response);

        $this->post(route('invitations.accept.store', $token), [
            'name' => 'Once',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ])->assertRedirect();

        $this->assertSame(1, OrganizationMembership::query()->where('organization_id', $organization->getKey())->where('user_id', '!=', $owner->id)->count());

        // Same token, second attempt — must be rejected, no second membership/user.
        $this->post(route('invitations.accept.store', $token), [
            'name' => 'Once Again',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ])->assertStatus(410);

        $this->assertSame(1, User::query()->where('email', 'once@example.test')->count());
        $this->assertSame(1, OrganizationMembership::query()->where('organization_id', $organization->getKey())->where('user_id', '!=', $owner->id)->count());
    }

    public function test_expired_invitation_is_rejected(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $role = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();

        $response = $this->actingAs($owner)->post(route('organization-invitations.store', $organization), [
            'email' => 'late@example.test',
            'role_id' => $role->id,
        ]);
        $token = $this->extractToken($response);

        UserInvitation::query()->where('email', 'late@example.test')->update(['expires_at' => now()->subDay()]);

        $this->get(route('invitations.accept', $token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('status', 'invalid'));

        $this->post(route('invitations.accept.store', $token), [
            'name' => 'Late',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ])->assertStatus(410);

        $this->assertDatabaseMissing('users', ['email' => 'late@example.test']);
    }

    public function test_invitation_role_must_belong_to_the_same_organization(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA);
        $organizationB = $this->createOrganization($ownerB);
        $roleB = $organizationB->roles()->where('slug', 'sales-employee')->firstOrFail();

        $this->actingAs($ownerA)->postJson(route('organization-invitations.store', $organizationA), [
            'email' => 'target@example.test',
            'role_id' => $roleB->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('role_id');

        $this->assertDatabaseMissing('user_invitations', ['email' => 'target@example.test']);
    }

    public function test_owner_role_cannot_be_used_for_an_invitation(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $ownerRole = $organization->roles()->where('slug', 'owner')->firstOrFail();

        $this->actingAs($owner)->post(route('organization-invitations.store', $organization), [
            'email' => 'wannabe-owner@example.test',
            'role_id' => $ownerRole->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('user_invitations', ['email' => 'wannabe-owner@example.test']);
    }

    public function test_actor_cannot_invite_with_a_role_that_grants_permissions_they_do_not_hold(): void
    {
        $owner = User::factory()->create();
        $limited = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $limited, ['members.create']);
        $adminRole = $organization->roles()->where('slug', 'admin')->firstOrFail();

        $this->actingAs($limited)->post(route('organization-invitations.store', $organization), [
            'email' => 'too-powerful@example.test',
            'role_id' => $adminRole->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('user_invitations', ['email' => 'too-powerful@example.test']);
    }

    public function test_inviting_an_already_registered_member_is_rejected(): void
    {
        $owner = User::factory()->create();
        $existingMember = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $role = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();
        $this->addOrganizationMember($organization, $existingMember, ['customers.view']);

        $this->actingAs($owner)->postJson(route('organization-invitations.store', $organization), [
            'email' => $existingMember->email,
            'role_id' => $role->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_new_invitation_supersedes_a_previous_pending_one_for_the_same_email(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $role = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();

        $first = $this->actingAs($owner)->post(route('organization-invitations.store', $organization), [
            'email' => 'repeat@example.test',
            'role_id' => $role->id,
        ]);
        $firstToken = $this->extractToken($first);

        $this->actingAs($owner)->post(route('organization-invitations.store', $organization), [
            'email' => 'repeat@example.test',
            'role_id' => $role->id,
        ]);

        $this->assertSame(1, UserInvitation::query()->where('email', 'repeat@example.test')->count());

        $this->post(route('invitations.accept.store', $firstToken), [
            'name' => 'Repeat',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ])->assertStatus(404);
    }

    public function test_admin_can_revoke_a_pending_invitation(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $role = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();
        $this->actingAs($owner)->post(route('organization-invitations.store', $organization), [
            'email' => 'revoke-me@example.test',
            'role_id' => $role->id,
        ]);
        $invitation = UserInvitation::query()->where('email', 'revoke-me@example.test')->firstOrFail();

        $this->actingAs($owner)->delete(route('invitations.destroy', $invitation))->assertRedirect();

        $this->assertDatabaseMissing('user_invitations', ['id' => $invitation->id]);
    }

    public function test_member_from_another_organization_cannot_revoke_an_invitation(): void
    {
        Mail::fake();
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA);
        $this->createOrganization($ownerB);
        $role = $organizationA->roles()->where('slug', 'sales-employee')->firstOrFail();

        $this->actingAs($ownerA)->post(route('organization-invitations.store', $organizationA), [
            'email' => 'foreign@example.test',
            'role_id' => $role->id,
        ]);
        $invitation = UserInvitation::query()->where('email', 'foreign@example.test')->firstOrFail();

        $this->actingAs($ownerB)->delete(route('invitations.destroy', $invitation))->assertNotFound();
        $this->assertDatabaseHas('user_invitations', ['id' => $invitation->id]);
    }

    public function test_admin_can_add_an_existing_user_by_email(): void
    {
        $owner = User::factory()->create();
        $existingUser = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $role = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();

        $this->actingAs($owner)->post(route('organization-memberships.store', $organization), [
            'email' => $existingUser->email,
            'role_id' => $role->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->getKey(),
            'user_id' => $existingUser->getKey(),
            'role_id' => $role->getKey(),
        ]);
    }

    public function test_adding_a_member_by_unknown_email_is_rejected_with_a_helpful_error(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $role = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();
        $membershipCount = $organization->memberships()->count();

        $this->actingAs($owner)->postJson(route('organization-memberships.store', $organization), [
            'email' => 'nobody-yet@example.test',
            'role_id' => $role->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSame($membershipCount, $organization->memberships()->count());
    }

    public function test_existing_user_with_matching_email_can_accept_while_authenticated(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $invitee = User::factory()->create(['email' => 'already-has-account@example.test']);
        $organization = $this->createOrganization($owner);
        $role = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();

        $response = $this->actingAs($owner)->post(route('organization-invitations.store', $organization), [
            'email' => $invitee->email,
            'role_id' => $role->id,
        ]);
        $token = $this->extractToken($response);

        $this->get(route('invitations.accept', $token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('userExists', true));

        $this->actingAs($invitee)->post(route('invitations.accept.store', $token))->assertRedirect(route('platform.index'));

        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->getKey(),
            'user_id' => $invitee->getKey(),
            'role_id' => $role->getKey(),
        ]);
        // No duplicate user was created.
        $this->assertSame(1, User::query()->where('email', $invitee->email)->count());
    }

    public function test_existing_user_logged_in_as_someone_else_cannot_accept_a_foreign_invitation(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $invitee = User::factory()->create(['email' => 'target-person@example.test']);
        $someoneElse = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $role = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();

        $response = $this->actingAs($owner)->post(route('organization-invitations.store', $organization), [
            'email' => $invitee->email,
            'role_id' => $role->id,
        ]);
        $token = $this->extractToken($response);

        $this->actingAs($someoneElse)->postJson(route('invitations.accept.store', $token))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('organization_memberships', [
            'organization_id' => $organization->getKey(),
            'user_id' => $someoneElse->getKey(),
        ]);
    }

    private function extractToken($response): string
    {
        $response->assertSessionHas('invitationUrl');
        $url = session('invitationUrl');
        $this->assertIsString($url);

        return Str::afterLast($url, '/invitations/');
    }
}
