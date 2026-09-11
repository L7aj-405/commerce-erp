<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_new_user_can_register_and_is_logged_in_and_sent_to_the_platform(): void
    {
        $response = $this->post('/register', [
            'name' => 'Amine Benali',
            'email' => 'amine@example.test',
            'password' => 'motdepasse-solide',
            'password_confirmation' => 'motdepasse-solide',
        ]);

        $response->assertRedirect(route('platform.index'));
        $this->assertAuthenticated();

        $user = User::query()->where('email', 'amine@example.test')->firstOrFail();
        $this->assertSame('Amine Benali', $user->name);
        $this->assertTrue(Hash::check('motdepasse-solide', $user->password));
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $this->from('/register')->post('/register', [
            'name' => 'Second User',
            'email' => 'taken@example.test',
            'password' => 'motdepasse-solide',
            'password_confirmation' => 'motdepasse-solide',
        ])->assertRedirect('/register')->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_registration_requires_matching_password_confirmation(): void
    {
        $this->from('/register')->post('/register', [
            'name' => 'Mismatch User',
            'email' => 'mismatch@example.test',
            'password' => 'motdepasse-solide',
            'password_confirmation' => 'autre-chose',
        ])->assertRedirect('/register')->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'mismatch@example.test']);
    }
}
