<?php

namespace Tests\Feature\Auth;

use App\Models\TrustedTwoFactorDevice;
use App\Models\User;
use App\Services\Security\RecoveryCodeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\Support\PlatformTestCase;

class TrustedTwoFactorDeviceTest extends PlatformTestCase
{
    private const RECOVERY_CODE = 'ABCD-EFGH';

    private function enabledUser(bool $withOrganization = false): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => app(RecoveryCodeService::class)->hash([self::RECOVERY_CODE]),
        ])->save();

        if ($withOrganization) {
            $organization = $this->createOrganization($user);
            $this->activate($user, $organization);
        }

        return $user->fresh();
    }

    /** @return array{TrustedTwoFactorDevice, string} */
    private function trustedDevice(User $user, array $overrides = []): array
    {
        $token = bin2hex(random_bytes(32));
        $device = new TrustedTwoFactorDevice;
        $device->user_id = $user->getKey();
        $device->token_hash = hash('sha256', $token);
        $device->device_name = $overrides['device_name'] ?? 'Chrome sur Windows';
        $device->browser = 'Chrome';
        $device->platform = 'Windows';
        $device->created_ip = '127.0.0.1';
        $device->last_ip = '127.0.0.1';
        $device->last_used_at = now();
        $device->expires_at = $overrides['expires_at'] ?? now()->addDays(15);
        $device->revoked_at = $overrides['revoked_at'] ?? null;
        $device->save();

        return [$device, $device->getKey().'|'.$token];
    }

    public function test_challenge_can_create_a_hashed_secure_trusted_device_grant(): void
    {
        config(['session.secure' => true]);
        $user = $this->enabledUser(withOrganization: true);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.challenge.show'));

        $response = $this->post(route('two-factor.challenge.store'), [
            'code' => self::RECOVERY_CODE,
            'trust_device' => true,
            'duration_days' => 15,
        ])->assertRedirect(route('platform.index'));

        $response->assertCookie(config('two-factor.trusted_device_cookie'));
        $device = TrustedTwoFactorDevice::query()->where('user_id', $user->getKey())->sole();
        $this->assertSame(64, strlen($device->token_hash));
        $this->assertTrue($device->expires_at->between(now()->addDays(14), now()->addDays(16)));
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($candidate) => $candidate->getName() === config('two-factor.trusted_device_cookie'));
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertDatabaseHas('audit_logs', ['event' => 'two_factor.trusted_device_created', 'actor_id' => $user->getKey()]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $user->getKey(), 'event_type' => 'two_factor.trusted_device_created']);
    }

    public function test_valid_trusted_device_skips_only_the_second_factor_after_password_verification(): void
    {
        $user = $this->enabledUser();
        [$device, $cookie] = $this->trustedDevice($user);

        $this->withCookie(config('two-factor.trusted_device_cookie'), $cookie)
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('platform.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($device->fresh()->last_used_at->greaterThanOrEqualTo($device->last_used_at));
    }

    public function test_trusted_device_never_bypasses_the_password(): void
    {
        $user = $this->enabledUser();
        [, $cookie] = $this->trustedDevice($user);

        $this->withCookie(config('two-factor.trusted_device_cookie'), $cookie)
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_expired_revoked_and_other_account_devices_cannot_skip_the_challenge(): void
    {
        $user = $this->enabledUser();
        $other = $this->enabledUser();

        foreach ([
            $this->trustedDevice($user, ['expires_at' => now()->subMinute()])[1],
            $this->trustedDevice($user, ['revoked_at' => now()])[1],
            $this->trustedDevice($other)[1],
        ] as $cookie) {
            $this->withCookie(config('two-factor.trusted_device_cookie'), $cookie)
                ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
                ->assertRedirect(route('two-factor.challenge.show'));
            $this->assertGuest();
            $this->flushSession();
        }
    }

    public function test_duration_must_come_from_the_configured_allow_list(): void
    {
        $user = $this->enabledUser();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->post(route('two-factor.challenge.store'), [
            'code' => self::RECOVERY_CODE,
            'trust_device' => true,
            'duration_days' => 365,
        ])->assertSessionHasErrors('duration_days');

        $this->assertGuest();
        $this->assertDatabaseCount('trusted_two_factor_devices', 0);
    }

    public function test_user_can_revoke_one_or_all_own_devices_but_not_another_users_device(): void
    {
        $user = $this->enabledUser(withOrganization: true);
        $other = $this->enabledUser();
        [$first, $cookie] = $this->trustedDevice($user);
        [$second] = $this->trustedDevice($user, ['device_name' => 'Firefox sur Linux']);
        [$foreign] = $this->trustedDevice($other);

        $this->actingAs($user)->withCookie(config('two-factor.trusted_device_cookie'), $cookie)
            ->delete(route('security.trusted-devices.destroy', $first))
            ->assertRedirect()
            ->assertCookieExpired(config('two-factor.trusted_device_cookie'));
        $this->assertNotNull($first->fresh()->revoked_at);

        $this->actingAs($user)->delete(route('security.trusted-devices.destroy', $foreign))->assertNotFound();
        $this->assertNull($foreign->fresh()->revoked_at);

        $this->actingAs($user)->delete(route('security.trusted-devices.destroy-all'))->assertRedirect();
        $this->assertNotNull($second->fresh()->revoked_at);
    }

    public function test_disabling_or_reconfiguring_two_factor_revokes_existing_grants(): void
    {
        $user = $this->enabledUser();
        [$device] = $this->trustedDevice($user);

        $this->actingAs($user)->delete('/two-factor-authentication', ['password' => 'password'])->assertRedirect();
        $this->assertNotNull($device->fresh()->revoked_at);

        [$stale] = $this->trustedDevice($user);
        $this->actingAs($user)->postJson('/two-factor-authentication')->assertOk();
        $this->assertNotNull($stale->fresh()->revoked_at);
    }

    public function test_password_and_email_changes_revoke_trusted_devices(): void
    {
        Notification::fake();
        $user = $this->enabledUser();
        [$passwordDevice] = $this->trustedDevice($user);

        $this->actingAs($user)->patchJson(route('account.password.update'), [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertOk();
        $this->assertNotNull($passwordDevice->fresh()->revoked_at);

        [$emailDevice] = $this->trustedDevice($user);
        $this->actingAs($user)->patch(route('account.profile.update'), [
            'name' => $user->name,
            'email' => 'changed@example.test',
            'current_password' => 'new-secure-password',
        ])->assertRedirect();
        $this->assertNotNull($emailDevice->fresh()->revoked_at);
    }

    public function test_cleanup_removes_only_old_expired_or_revoked_records(): void
    {
        $user = $this->enabledUser();
        [$active] = $this->trustedDevice($user);
        [$recentlyExpired] = $this->trustedDevice($user, ['expires_at' => now()->subDay()]);
        [$oldExpired] = $this->trustedDevice($user, ['expires_at' => now()->subDays(40)]);
        [$oldRevoked] = $this->trustedDevice($user, ['revoked_at' => now()->subDays(40)]);

        Artisan::call('security:cleanup-trusted-devices');

        $this->assertDatabaseHas('trusted_two_factor_devices', ['id' => $active->getKey()]);
        $this->assertDatabaseHas('trusted_two_factor_devices', ['id' => $recentlyExpired->getKey()]);
        $this->assertDatabaseMissing('trusted_two_factor_devices', ['id' => $oldExpired->getKey()]);
        $this->assertDatabaseMissing('trusted_two_factor_devices', ['id' => $oldRevoked->getKey()]);
    }
}
