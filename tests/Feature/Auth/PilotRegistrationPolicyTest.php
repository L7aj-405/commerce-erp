<?php

namespace Tests\Feature\Auth;

use App\Actions\Auth\ResolveGoogleAuthenticatedUserAction;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\Auth\GoogleAuthIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Support\PlatformTestCase;

/** PILOT-1.1: closed pilot — no public self-registration, operator bootstrap of the first owner, MySQL in UTC. */
class PilotRegistrationPolicyTest extends PlatformTestCase
{
    private function closeRegistration(): void
    {
        config(['auth.public_registration' => false]);
    }

    public function test_registration_is_open_outside_production_by_default_and_closed_when_disabled(): void
    {
        $this->get('/register')->assertOk();

        $this->closeRegistration();
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'Intrus', 'email' => 'intrus@example.test', 'password' => 'correct horse battery', 'password_confirmation' => 'correct horse battery'])
            ->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => 'intrus@example.test']);
    }

    public function test_login_password_reset_and_invitations_are_unaffected_when_registration_is_closed(): void
    {
        $this->closeRegistration();
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->where('features.public_registration', false));
        $this->get('/forgot-password')->assertOk();

        Mail::fake();
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $role = $organization->roles()->where('slug', 'sales-employee')->firstOrFail();
        $response = $this->withFreshAuthentication()->actingAs($owner)->post(route('organization-invitations.store', $organization), [
            'email' => 'invitee@example.test', 'role_id' => $role->id,
        ])->assertRedirect();
        $token = Str::afterLast((string) session('invitationUrl'), '/invitations/');
        auth()->logout();

        $this->post(route('invitations.accept.store', $token), [
            'name' => 'Invitee', 'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
        ])->assertRedirect(route('platform.index'));
        $invitee = User::query()->where('email', 'invitee@example.test')->firstOrFail();
        $this->assertTrue(OrganizationMembership::query()->where('organization_id', $organization->getKey())->where('user_id', $invitee->getKey())->exists());
        $this->assertNotNull(UserInvitation::query()->where('email', 'invitee@example.test')->value('accepted_at'));
    }

    public function test_google_sign_in_never_creates_an_account_when_registration_is_closed(): void
    {
        $this->closeRegistration();
        $resolve = app(ResolveGoogleAuthenticatedUserAction::class);

        try {
            $resolve->execute(new GoogleAuthIdentity('google-sub-1', 'stranger@example.test', true, 'Stranger', null));
            $this->fail('An unknown Google identity must not create an account.');
        } catch (RuntimeException) {
            $this->assertDatabaseMissing('users', ['email' => 'stranger@example.test']);
        }

        $existing = User::factory()->create(['email' => 'member@example.test']);
        $resolved = $resolve->execute(new GoogleAuthIdentity('google-sub-2', 'member@example.test', true, 'Member', null));
        $this->assertSame((int) $existing->getKey(), (int) $resolved->getKey(), 'Existing accounts still sign in and link Google.');
    }

    public function test_bootstrap_owner_command_creates_the_owner_organization_and_a_setup_link_without_a_password(): void
    {
        $this->closeRegistration();
        $this->artisan('erp:bootstrap-owner', ['email' => 'Owner@Pilot.test', '--name' => 'Pilot Owner', '--organization' => 'Pilot SARL', '--timezone' => 'Africa/Casablanca'])
            ->expectsOutputToContain('One-time password-setup link')
            ->expectsOutputToContain('/reset-password/')
            ->assertSuccessful();

        $owner = User::query()->where('email', 'owner@pilot.test')->firstOrFail();
        $organization = Organization::query()->where('name', 'Pilot SARL')->firstOrFail();
        $this->assertNotNull($owner->email_verified_at);
        $this->assertStringStartsWith('$2y$', (string) $owner->password, 'Only a hash of an unknown random password is stored.');
        $this->assertSame((int) $organization->getKey(), (int) $owner->active_organization_id);
        $this->assertSame('Africa/Casablanca', $organization->businessTimezone());
        $membership = OrganizationMembership::query()->where('organization_id', $organization->getKey())->where('user_id', $owner->getKey())->firstOrFail();
        $this->assertSame('owner', $membership->role->slug);
        $this->assertTrue($owner->hasPermission($organization, 'settings.update'));
        $this->assertTrue(AuditLog::query()->where('event', 'organization.owner_bootstrapped')->where('organization_id', $organization->getKey())->exists());
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', 'owner@pilot.test')->count());

        // Never touches an existing account; validates its input.
        $this->artisan('erp:bootstrap-owner', ['email' => 'owner@pilot.test', '--name' => 'X', '--organization' => 'Takeover'])->assertFailed();
        $this->assertFalse(Organization::query()->where('name', 'Takeover')->exists());
        $this->artisan('erp:bootstrap-owner', ['email' => 'not-an-email', '--name' => 'X', '--organization' => 'Y'])->assertExitCode(2);
        $this->artisan('erp:bootstrap-owner', ['email' => 'tz@pilot.test', '--name' => 'X', '--organization' => 'Y', '--timezone' => 'Mars/Base'])->assertExitCode(2);
    }

    public function test_mysql_sessions_are_pinned_to_utc_and_production_never_falls_back_to_sqlite(): void
    {
        $this->assertSame('+00:00', config('database.connections.mysql.timezone'));
        $this->assertArrayNotHasKey('timezone', config('database.connections.sqlite'));
        $this->assertSame('UTC', config('app.timezone'));

        // The shipped default (no DB_CONNECTION at all) is mysql, never a silent SQLite file.
        $config = require base_path('config/database.php');
        $this->assertSame(getenv('DB_CONNECTION') ?: 'mysql', $config['default']);
        $this->assertStringContainsString("env('DB_CONNECTION', 'mysql')", (string) file_get_contents(base_path('config/database.php')));
    }
}
