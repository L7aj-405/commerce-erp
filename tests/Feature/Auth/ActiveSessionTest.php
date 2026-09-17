<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Settings\ActiveSessionController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\PlatformTestCase;

/**
 * Sprint 1.1 §8 — account session visibility/revocation on the `database`
 * session driver. The test suite normally runs with SESSION_DRIVER=array
 * (phpunit.xml) for speed, which never touches the `sessions` table at all —
 * this feature only exists for the database driver, so this file overrides
 * it back to `database` to exercise the real mechanics end-to-end.
 */
class ActiveSessionTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    private function insertSession(string $id, User $user, int $lastActivity): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->getKey(),
            'ip_address' => '198.51.100.1',
            'user_agent' => 'Some Browser',
            'payload' => base64_encode('x'),
            'last_activity' => $lastActivity,
        ]);
    }

    public function test_the_security_page_lists_sessions_without_exposing_the_raw_session_id(): void
    {
        $user = User::factory()->create();
        $this->insertSession('other-device-session', $user, now()->subMinute()->timestamp);

        $response = $this->actingAs($user)->withHeader('X-Inertia', 'true')->get(route('security.edit'));

        $response->assertOk();
        $sessions = $response->json('props.sessions');
        $this->assertNotEmpty($sessions);
        foreach ($sessions as $session) {
            $this->assertArrayNotHasKey('id', $session);
            $this->assertNotSame('other-device-session', $session['token']);
        }
    }

    public function test_the_current_session_is_flagged_and_cannot_be_revoked_via_this_endpoint(): void
    {
        $user = User::factory()->create();

        // A real request through the `database` session driver persists this
        // exact session's row — read it back to get the genuine current id.
        $this->actingAs($user)->get(route('security.edit'))->assertOk();
        $currentSessionId = DB::table('sessions')->where('user_id', $user->getKey())->value('id');
        $this->assertNotNull($currentSessionId);

        $token = ActiveSessionController::opaqueToken($currentSessionId);
        $this->actingAs($user)->delete("/security/sessions/{$token}")->assertRedirect();

        // The controller explicitly excludes the requester's own current
        // session from the candidates it will ever delete.
        $this->assertDatabaseHas('sessions', ['id' => $currentSessionId]);
    }

    public function test_a_user_can_revoke_a_specific_other_session(): void
    {
        $user = User::factory()->create();
        $this->insertSession('device-a', $user, now()->timestamp);

        $token = ActiveSessionController::opaqueToken('device-a');

        $this->actingAs($user)->delete("/security/sessions/{$token}")->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['id' => 'device-a']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.session_revoked']);
    }

    public function test_revoking_another_users_session_token_does_nothing(): void
    {
        $victim = User::factory()->create();
        $this->insertSession('victim-session', $victim, now()->timestamp);
        $attacker = User::factory()->create();

        $token = ActiveSessionController::opaqueToken('victim-session');
        $this->actingAs($attacker)->delete("/security/sessions/{$token}")->assertRedirect();

        $this->assertDatabaseHas('sessions', ['id' => 'victim-session']);
    }

    public function test_revoke_others_deletes_every_other_session_for_the_user_only(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->insertSession('mine-a', $user, now()->timestamp);
        $this->insertSession('mine-b', $user, now()->timestamp);
        $this->insertSession('someone-elses', $otherUser, now()->timestamp);

        $this->actingAs($user)->delete('/security/sessions')->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['id' => 'mine-a']);
        $this->assertDatabaseMissing('sessions', ['id' => 'mine-b']);
        $this->assertDatabaseHas('sessions', ['id' => 'someone-elses']);
    }
}
