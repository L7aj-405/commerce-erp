<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Sprint 1.1 §9 — centralized password policy (`Password::defaults()`,
 * registered once in AppServiceProvider::boot()). Every entry point that
 * accepts a new password (registration, invitation acceptance, password
 * reset) validates through `Password::defaults()` rather than its own
 * ad hoc rule, so this asserts the SAME boundary on two independent
 * endpoints instead of duplicating a single implementation's unit test.
 */
class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_rejects_a_password_under_the_minimum(): void
    {
        $this->post('/register', [
            'name' => 'Short Pass',
            'email' => 'shortpass@example.test',
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'shortpass@example.test']);
    }

    public function test_registration_accepts_a_ten_character_passphrase(): void
    {
        $this->post('/register', [
            'name' => 'Long Enough',
            'email' => 'longenough@example.test',
            'password' => 'exactly-10',
            'password_confirmation' => 'exactly-10',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'longenough@example.test']);
    }

    public function test_reset_password_rejects_a_password_under_the_minimum(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ])->assertSessionHasErrors('password');
    }
}
