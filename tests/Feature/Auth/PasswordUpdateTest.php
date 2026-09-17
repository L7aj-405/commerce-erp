<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\PlatformTestCase;

/**
 * Account Settings V1 — "Mot de passe" (Account > Security). Requires the
 * current password as part of the same request (no stale confirm-password
 * timestamp), then rotates the current session and signs out every other
 * one — the same rule a broker-driven password reset already applies (see
 * NewPasswordController).
 */
class PasswordUpdateTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // This feature's session-invalidation behaviour only exists on the
        // `database` driver — see ActiveSessionTest's own doc for why.
        config(['session.driver' => 'database']);
    }

    public function test_changing_the_password_requires_the_correct_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/account/password', [
            'current_password' => 'not-the-password',
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_a_correct_current_password_changes_the_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/account/password', [
            'current_password' => 'password',
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertOk();

        $this->assertTrue(Hash::check('a-brand-new-passphrase', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.password_changed']);
    }

    public function test_the_new_password_must_meet_the_centralized_policy(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/account/password', [
            'current_password' => 'password',
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ])->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_changing_the_password_signs_out_every_other_session_but_keeps_the_current_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('security.edit'))->assertOk();
        $currentSessionId = DB::table('sessions')->where('user_id', $user->getKey())->value('id');
        $this->assertNotNull($currentSessionId);

        DB::table('sessions')->insert([
            'id' => 'other-device',
            'user_id' => $user->getKey(),
            'ip_address' => '198.51.100.1',
            'user_agent' => 'Some Other Browser',
            'payload' => base64_encode('x'),
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($user)->patchJson('/account/password', [
            'current_password' => 'password',
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        // The requester's own session id is rotated (defense in depth), but a
        // row for the (now-current) session must still exist.
        $this->assertDatabaseMissing('sessions', ['id' => $currentSessionId]);
        $this->assertDatabaseCount('sessions', 1);
    }

    public function test_password_hashes_are_never_exposed_in_a_validation_error_response(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patchJson('/account/password', [
            'current_password' => 'wrong',
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ]);

        $response->assertStatus(422);
        $this->assertStringNotContainsString($user->password, $response->getContent());
    }
}
