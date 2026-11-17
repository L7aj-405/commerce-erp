<?php

namespace Tests\Feature\SystemHealth;

use App\Models\SystemHealthHeartbeat;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\Support\PlatformTestCase;

/** Phases 5/6: trustworthy release identification and honest worker / scheduler reporting. */
class SystemHealthReleaseVisibilityTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('queue.default', 'database');
        config()->set('system-health.cache_seconds', 1);
    }

    public function test_application_card_shows_environment_sha_build_and_unverified_worker(): void
    {
        config()->set('system-health.commit_sha', 'abc123def4567890abc123def4567890abc123de');
        config()->set('system-health.build_at', '2026-11-16T09:00:00+00:00');
        $owner = $this->owner();

        $data = $this->actingAs($owner)->getJson(route('system-health.index'))->assertOk()->json('checks.application.data');

        $this->assertSame('abc123def4567890abc123def4567890abc123de', $data['commit']);
        $this->assertSame('2026-11-16T09:00:00+00:00', $data['build_at']);
        $this->assertNotEmpty($data['environment']);
        $this->assertSame('Unverified', $data['worker_commit']);
        $this->assertSame('Unverified', $data['worker_build_at']);
        $this->assertSame('Unverified', $data['worker_matches_app']);
    }

    public function test_worker_identity_comes_from_a_fresh_worker_probe_and_flags_a_mismatch(): void
    {
        config()->set('system-health.commit_sha', 'new-release');
        SystemHealthHeartbeat::beat('queue_worker', ['queue' => 'default', 'commit' => 'old-release', 'build_at' => '2026-11-01T00:00:00+00:00']);
        $owner = $this->owner();

        $data = $this->actingAs($owner)->getJson(route('system-health.index'))->json('checks.application.data');

        $this->assertSame('old-release', $data['worker_commit']);
        $this->assertStringContainsString('NO', $data['worker_matches_app']);
    }

    public function test_missing_monitoring_is_distinguished_from_a_stopped_worker(): void
    {
        $owner = $this->owner();

        // No scheduler heartbeat and no worker probe: the worker is UNVERIFIABLE, not "down".
        $checks = $this->actingAs($owner)->getJson(route('system-health.index'))->json('checks');
        $this->assertSame('unknown', $checks['queue']['status']);
        $this->assertStringContainsString('scheduler', $checks['queue']['message']);
        $this->assertSame('degraded', $checks['scheduler']['status']);
    }

    /** Round 2 BUG 3: "Unverified"/garbage in a `*_at` field crashed the page (RangeError: Invalid time value). */
    public function test_malformed_or_absent_build_timestamps_are_normalised_to_unverified_or_iso(): void
    {
        $owner = $this->owner();

        foreach ([null, '', '   ', 'not-a-date', 'Unverified', '2026-13-45T99:99:99'] as $malformed) {
            Cache::flush();
            config()->set('system-health.commit_sha', 'abc1234');
            config()->set('system-health.build_at', $malformed);
            SystemHealthHeartbeat::beat('queue_worker', ['queue' => 'default', 'commit' => 'abc1234', 'build_at' => $malformed]);

            $data = $this->actingAs($owner)->getJson(route('system-health.index'))->assertOk()->json('checks.application.data');

            $this->assertSame('abc1234', $data['commit'], 'commit SHA stays visible');
            $this->assertContains($data['worker_build_at'], ['Unverified'], 'malformed worker build date is Unverified');
            // build_at falls back to the manifest mtime (ISO) or Unverified, never to the raw garbage.
            $this->assertTrue(
                $data['build_at'] === 'Unverified' || strtotime($data['build_at']) !== false,
                'build_at must be Unverified or a parseable date, got '.var_export($data['build_at'], true),
            );
            $this->assertNotSame('not-a-date', $data['build_at']);
        }
    }

    public function test_missing_commit_and_heartbeats_render_honest_non_healthy_state(): void
    {
        config()->set('system-health.commit_sha', null);
        $owner = $this->owner();

        $json = $this->actingAs($owner)->getJson(route('system-health.index'))->assertOk()->json();

        $this->assertSame('Unverified', $json['checks']['application']['data']['commit']);
        $this->assertNull($json['checks']['scheduler']['data']['last_seen_at']);
        $this->assertNotSame('operational', $json['checks']['scheduler']['status']);
        $this->assertNotSame('operational', $json['checks']['queue']['status']);
    }

    public function test_stale_heartbeats_are_never_reported_operational(): void
    {
        $owner = $this->owner();
        SystemHealthHeartbeat::beat('scheduler');
        SystemHealthHeartbeat::beat('queue_worker', ['commit' => 'x', 'build_at' => '2026-11-01T00:00:00+00:00']);
        SystemHealthHeartbeat::query()->update(['last_seen_at' => now()->subDay()]);

        $checks = $this->actingAs($owner)->getJson(route('system-health.index'))->assertOk()->json('checks');

        $this->assertNotSame('operational', $checks['scheduler']['status']);
        $this->assertNotSame('operational', $checks['queue']['status']);
        $this->assertSame('Unverified', $checks['application']['data']['worker_build_at']);
    }

    public function test_secrets_are_never_part_of_the_payload(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
        $owner = $this->owner();

        $body = $this->actingAs($owner)->getJson(route('system-health.index'))->getContent();

        $this->assertStringNotContainsString(base64_encode(str_repeat('s', 32)), $body);
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        return $owner;
    }
}
