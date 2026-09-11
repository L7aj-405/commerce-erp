<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WelcomeAndLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_the_welcome_page_at_root(): void
    {
        $this->withHeader('X-Inertia', 'true')
            ->get('/')
            ->assertOk()
            ->assertJsonPath('component', 'Home');
    }

    public function test_authenticated_user_is_redirected_from_root_to_the_platform(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('platform.index'));
    }

    public function test_guest_can_view_the_login_screen(): void
    {
        $this->withHeader('X-Inertia', 'true')
            ->get(route('login'))
            ->assertOk()
            ->assertJsonPath('component', 'Auth/Login');
    }

    public function test_login_with_valid_credentials_redirects_to_the_platform(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertRedirect(route('platform.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_with_invalid_credentials_returns_a_localized_error(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertRedirect('/login')->assertSessionHasErrors([
            'email' => 'Ces identifiants ne correspondent à aucun compte.',
        ]);

        $this->assertGuest();
    }

    public function test_logout_returns_the_user_to_the_login_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
