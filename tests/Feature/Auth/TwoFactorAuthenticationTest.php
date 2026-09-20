<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Security\TotpService;
use Illuminate\Support\Str;
use Tests\Support\PlatformTestCase;

/**
 * §E — TOTP two-factor authentication: enrollment requires confirmation
 * before it counts as enabled, the secret is encrypted at rest, login stops
 * short of an authenticated session until the challenge passes, recovery
 * codes are single-use, and disabling requires re-entering the password.
 */
class TwoFactorAuthenticationTest extends PlatformTestCase
{
    private function currentCodeFor(User $user): string
    {
        return $this->totpCodeFor($user->fresh()->two_factor_secret);
    }

    private function totpCodeFor(string $secret): string
    {
        // Brute-force the 6-digit code for "now" using the real TotpService —
        // there is no public "generate" method (only verify), so we probe
        // the handful of codes verify() itself would accept for this instant.
        // Simpler and just as real: read the algorithm's own reflection-free
        // path by asking the service to verify candidates is impractical for
        // 10^6 codes, so instead compute it directly the same way TotpService
        // does internally via a tiny local RFC 6238 helper mirroring it.
        $key = $this->base32Decode($secret);
        $counter = intdiv(time(), 30);
        $binaryCounter = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($truncated % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $data));
        $bits = '';
        foreach (str_split($data) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                break;
            }
            $bytes .= chr(bindec($chunk));
        }

        return $bytes;
    }

    public function test_enrollment_requires_a_valid_totp_confirmation_before_it_counts_as_enabled(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/two-factor-authentication')->assertOk();
        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication(), 'a secret alone must not enable 2FA');

        $code = $this->currentCodeFor($user);
        $response = $this->actingAs($user)->postJson('/two-factor-authentication/confirm', ['code' => $code])->assertOk();
        $this->assertNotEmpty($response->json('recovery_codes'));

        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    /**
     * Sprint 1.1 §4 — the QR code shown during enrollment is rendered
     * entirely client-side (see resources/js/pages/Settings/Security.tsx's
     * TotpQrCode, built on the `qrcode` npm package) directly from this
     * response's `otpauth_uri` — never generated/stored server-side. This
     * asserts the enrollment endpoint's payload is exactly what may end up
     * encoded into that QR: the otpauth URI and the manual setup key, and
     * nothing else (no password, no recovery codes at this step).
     */
    public function test_enrollment_response_contains_only_the_secret_and_a_correct_otpauth_uri(): void
    {
        $user = User::factory()->create(['email' => 'totp-user@example.test']);

        $response = $this->actingAs($user)->postJson('/two-factor-authentication')->assertOk();

        $response->assertJsonStructure(['secret', 'otpauth_uri']);
        $this->assertCount(2, $response->json());

        $uri = $response->json('otpauth_uri');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString(rawurlencode('totp-user@example.test'), $uri);
        $this->assertStringContainsString('secret='.$response->json('secret'), $uri);
        $this->assertStringNotContainsString('password', Str::lower($uri));
    }

    public function test_an_invalid_confirmation_code_does_not_enable_two_factor(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/two-factor-authentication')->assertOk();

        $this->actingAs($user)->postJson('/two-factor-authentication/confirm', ['code' => '000000'])
            ->assertStatus(422);

        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_the_totp_secret_is_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/two-factor-authentication')->assertOk();

        $raw = $user->fresh()->getRawOriginal('two_factor_secret');
        $this->assertNotSame($user->fresh()->two_factor_secret, $raw);
        $this->assertStringNotContainsString($user->fresh()->two_factor_secret, (string) $raw);
    }

    public function test_the_secret_and_recovery_codes_never_appear_in_the_user_json_representation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/two-factor-authentication')->assertOk();
        $user->refresh();
        $this->actingAs($user)->postJson('/two-factor-authentication/confirm', ['code' => $this->currentCodeFor($user)])->assertOk();

        $json = $user->fresh()->toArray();
        $this->assertArrayNotHasKey('two_factor_secret', $json);
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $json);
    }

    /** @return array{User, string} enrolled user + one still-valid plaintext recovery code */
    private function enrolledUserWithRecoveryCode(): array
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/two-factor-authentication')->assertOk();
        $user->refresh();
        $response = $this->actingAs($user)->postJson('/two-factor-authentication/confirm', ['code' => $this->currentCodeFor($user)])->assertOk();

        // Enrollment is an authenticated flow. End that browser session so
        // callers that exercise login actually begin as guests and cannot
        // accidentally bypass the password/2FA challenge middleware in the
        // test fixture itself.
        $this->post(route('logout'))->assertRedirect(route('login'));

        return [$user->fresh(), $response->json('recovery_codes')[0]];
    }

    public function test_a_login_for_a_two_factor_enabled_account_stops_at_the_challenge_not_a_full_session(): void
    {
        [$user] = $this->enrolledUserWithRecoveryCode();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.challenge.show'));

        $this->assertGuest();
    }

    public function test_direct_navigation_to_a_protected_route_is_not_possible_mid_challenge(): void
    {
        [$user] = $this->enrolledUserWithRecoveryCode();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->get(route('platform.index'))->assertRedirect(route('login'));
    }

    public function test_a_correct_totp_code_completes_the_login(): void
    {
        [$user] = $this->enrolledUserWithRecoveryCode();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->post(route('two-factor.challenge.store'), ['code' => $this->currentCodeFor($user)])
            ->assertRedirect(route('platform.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_an_invalid_totp_code_is_rejected_and_the_session_stays_unauthenticated(): void
    {
        [$user] = $this->enrolledUserWithRecoveryCode();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->post(route('two-factor.challenge.store'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_totp_challenge_attempts_are_rate_limited(): void
    {
        [$user] = $this->enrolledUserWithRecoveryCode();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('two-factor.challenge.store'), ['code' => '000000']);
        }

        // The 6th attempt is blocked by the throttle even before checking the
        // code — a valid code submitted here must still fail.
        $this->post(route('two-factor.challenge.store'), ['code' => $this->currentCodeFor($user)])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_a_recovery_code_works_once_and_then_cannot_be_reused(): void
    {
        [$user, $recoveryCode] = $this->enrolledUserWithRecoveryCode();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->post(route('two-factor.challenge.store'), ['code' => $recoveryCode])
            ->assertRedirect(route('platform.index'));
        $this->assertAuthenticatedAs($user);

        auth()->logout();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.challenge.store'), ['code' => $recoveryCode])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_regenerating_recovery_codes_invalidates_the_previous_set(): void
    {
        [$user, $oldCode] = $this->enrolledUserWithRecoveryCode();

        $response = $this->actingAs($user)->postJson('/two-factor-recovery-codes', ['password' => 'password'])->assertOk();
        $newCodes = $response->json('recovery_codes');
        $this->assertNotContains($oldCode, $newCodes);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.challenge.store'), ['code' => $oldCode])->assertSessionHasErrors('code');
    }

    public function test_regenerating_recovery_codes_with_the_wrong_password_is_rejected(): void
    {
        [$user] = $this->enrolledUserWithRecoveryCode();

        $this->actingAs($user)->postJson('/two-factor-recovery-codes', ['password' => 'not-the-password'])
            ->assertStatus(422);
    }

    public function test_disabling_two_factor_requires_the_correct_password(): void
    {
        [$user] = $this->enrolledUserWithRecoveryCode();

        $this->actingAs($user)->json('DELETE', '/two-factor-authentication', ['password' => 'not-the-password'])
            ->assertStatus(422);
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());

        $this->actingAs($user)->json('DELETE', '/two-factor-authentication', ['password' => 'password'])
            ->assertRedirect();
        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_organization_mandatory_two_factor_policy_redirects_an_unenrolled_member_to_security_settings(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        // A settings row already exists (created by OrganizationCreator) —
        // update it rather than create() a second row (organization_id is
        // unique on this table).
        $this->configureOrganizationSecurity($organization, requireTwoFactor: true);

        $this->actingAs($owner)->get(route('platform.index'))->assertRedirect(route('security.edit'));

        // Enrollment itself must stay reachable, or the policy is an
        // inescapable lock.
        $this->actingAs($owner)->get(route('security.edit'))->assertOk();
    }

    public function test_organization_mandatory_two_factor_policy_does_not_block_a_member_who_already_has_it_enabled(): void
    {
        [$owner] = $this->enrolledUserWithRecoveryCode();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $this->configureOrganizationSecurity($organization, requireTwoFactor: true);

        $this->actingAs($owner)->get(route('platform.index'))->assertOk();
    }

    public function test_privileged_role_two_factor_policy_blocks_an_unenrolled_owner_when_enabled(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $this->configureOrganizationSecurity($organization, requireTwoFactorForPrivilegedRoles: true);

        $this->actingAs($owner)->get(route('platform.index'))->assertRedirect(route('security.edit'));
        // Enrollment itself must stay reachable.
        $this->actingAs($owner)->get(route('security.edit'))->assertOk();
    }

    public function test_privileged_role_two_factor_policy_does_not_apply_to_a_non_privileged_member_even_when_enabled(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->configureOrganizationSecurity($organization, requireTwoFactorForPrivilegedRoles: true);
        $employee = User::factory()->create();
        $this->addOrganizationMember($organization, $employee, [], roleName: 'Employee');
        $this->activate($employee, $organization);

        $this->actingAs($employee)->get(route('platform.index'))->assertOk();
    }

    public function test_privileged_role_two_factor_policy_does_not_block_an_owner_who_already_has_it_enabled(): void
    {
        [$owner] = $this->enrolledUserWithRecoveryCode();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        $this->configureOrganizationSecurity($organization, requireTwoFactorForPrivilegedRoles: true);

        $this->actingAs($owner)->get(route('platform.index'))->assertOk();
    }

    /**
     * OrganizationCreator sets `require_2fa_for_privileged_roles` to true by
     * default for every NEWLY created organization outside local/testing
     * ("prefer enforcing it for privileged accounts by default", §7) — this
     * is the one test that needs to observe that outside its usual testing
     * environment, so it restores the environment in a finally block even if
     * an assertion fails.
     */
    public function test_new_organizations_default_to_privileged_2fa_required_outside_local_and_testing(): void
    {
        try {
            $this->app->detectEnvironment(fn () => 'production');

            $organization = $this->createOrganization(User::factory()->create());

            $this->assertTrue($organization->fresh()->requiresTwoFactorForPrivilegedRoles());
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_privileged_role_two_factor_policy_is_not_retroactively_enabled_for_an_organization_with_no_settings_row(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);
        // Simulate a pre-existing organization from before this policy
        // existed: no settings row recorded at all.
        $organization->securitySetting()->delete();

        $this->assertFalse($organization->fresh()->requiresTwoFactorForPrivilegedRoles());
        $this->actingAs($owner)->get(route('platform.index'))->assertOk();
    }
}
