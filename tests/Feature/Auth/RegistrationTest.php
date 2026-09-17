<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ExistingAccountRegistrationAttempted;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_view_the_register_screen(): void
    {
        $this->withHeader('X-Inertia', 'true')
            ->get(route('register'))
            ->assertOk()
            ->assertJsonPath('component', 'Auth/Register');
    }

    public function test_authenticated_user_is_redirected_away_from_register(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('register'))
            ->assertRedirect();
    }

    /**
     * Sprint 1.1 §5 — registration never auto-logs the requester in anymore
     * (see RegisteredUserController's class doc): that would itself be an
     * enumeration oracle once the "email already exists" branch cannot
     * authenticate the requester either. A fresh account still goes through
     * the normal unverified -> verify-email path.
     */
    public function test_new_user_can_register_and_is_sent_to_login_unauthenticated(): void
    {
        Notification::fake();

        $response = $this->post('/register', [
            'name' => 'Amine Benali',
            'email' => 'amine@example.test',
            'password' => 'un-mot-de-passe-solide',
            'password_confirmation' => 'un-mot-de-passe-solide',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertGuest();

        $user = User::query()->where('email', 'amine@example.test')->firstOrFail();
        $this->assertSame('Amine Benali', $user->name);
        $this->assertTrue(Hash::check('un-mot-de-passe-solide', $user->password));
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    /**
     * Sprint 1.1 §5 — the response for an already-registered email is
     * identical to a brand-new registration: no distinct "already used"
     * validation error, no duplicate account, and never an authenticated
     * session for the requester. The existing account owner is notified
     * out of band instead.
     */
    public function test_registering_an_existing_email_gives_an_identical_generic_response(): void
    {
        Notification::fake();
        $existing = User::factory()->create(['email' => 'taken@example.test']);

        $response = $this->post('/register', [
            'name' => 'Second User',
            'email' => 'taken@example.test',
            'password' => 'un-mot-de-passe-solide',
            'password_confirmation' => 'un-mot-de-passe-solide',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status');
        $this->assertGuest();

        // No duplicate row, and the real owner's account is untouched.
        $this->assertSame(1, User::query()->where('email', 'taken@example.test')->count());
        $this->assertSame($existing->password, $existing->fresh()->password);
        Notification::assertSentTo($existing, ExistingAccountRegistrationAttempted::class);
        Notification::assertNotSentTo($existing, VerifyEmail::class);
    }

    public function test_registration_attempts_are_rate_limited(): void
    {
        Notification::fake();

        for ($i = 0; $i < 6; $i++) {
            $this->post('/register', [
                'name' => 'Flooder',
                'email' => "flood{$i}@example.test",
                'password' => 'un-mot-de-passe-solide',
                'password_confirmation' => 'un-mot-de-passe-solide',
            ]);
        }

        $this->post('/register', [
            'name' => 'Flooder',
            'email' => 'flood-final@example.test',
            'password' => 'un-mot-de-passe-solide',
            'password_confirmation' => 'un-mot-de-passe-solide',
        ])->assertStatus(429);
    }

    public function test_registration_requires_matching_password_confirmation(): void
    {
        $this->from('/register')->post('/register', [
            'name' => 'Mismatch User',
            'email' => 'mismatch@example.test',
            'password' => 'un-mot-de-passe-solide',
            'password_confirmation' => 'autre-chose',
        ])->assertRedirect('/register')->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'mismatch@example.test']);
    }
}
