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
