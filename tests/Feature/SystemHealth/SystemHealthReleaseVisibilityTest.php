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

    protected function tearDown(): void
    {
        foreach (['APP_COMMIT_SHA', 'SOURCE_COMMIT', 'APP_BUILD_AT'] as $name) {
            putenv($name);
        }
        parent::tearDown();
    }

    /** Round 4: the worker's config:cache froze the SHA as null, but the platform injected SOURCE_COMMIT at runtime. */
    public function test_worker_probe_records_the_live_environment_sha_when_cached_config_is_null(): void
    {
        $sha = 'b1dc43ef692095960ec829e9ddfe341f23167f4c';
        config()->set('system-health.commit_sha', null);
        config()->set('system-health.commit_source', null);
        config()->set('system-health.build_at', null);
        putenv('SOURCE_COMMIT='.$sha);
        putenv('APP_BUILD_AT=2026-11-17T10:04:00+00:00');

        (new \App\Jobs\RecordQueueWorkerHeartbeatJob)->handle();

        $metadata = SystemHealthHeartbeat::query()->find('queue_worker')->metadata;
        $this->assertSame($sha, $metadata['commit']);
        $this->assertSame('2026-11-17T10:04:00+00:00', $metadata['build_at']);

        $data = $this->actingAs($this->owner())->getJson(route('system-health.index'))->assertOk()->json('checks.application.data');
        $this->assertSame($sha, $data['worker_commit']);
        $this->assertSame('yes', $data['worker_matches_app']);
        $this->assertSame('SOURCE_COMMIT (runtime env)', $data['commit_source']);
        $this->assertSame('APP_BUILD_AT', $data['build_at_source']);
    }

    public function test_worker_with_an_older_release_than_the_app_is_flagged(): void
    {
        putenv('SOURCE_COMMIT=1111111111111111111111111111111111111111');
        (new \App\Jobs\RecordQueueWorkerHeartbeatJob)->handle();
        // The web app is now on a newer release than the (long-running) worker.
        putenv('SOURCE_COMMIT=2222222222222222222222222222222222222222');

        $data = $this->actingAs($this->owner())->getJson(route('system-health.index'))->assertOk()->json('checks.application.data');

        $this->assertSame('1111111111111111111111111111111111111111', $data['worker_commit']);
        $this->assertStringContainsString('NO', $data['worker_matches_app']);
    }

    public function test_app_commit_sha_takes_precedence_over_source_commit_and_malformed_env_is_unverified(): void
    {
        config()->set('system-health.commit_sha', null);
        putenv('SOURCE_COMMIT=3333333333333333333333333333333333333333');
        putenv('APP_COMMIT_SHA=4444444444444444444444444444444444444444');
        $this->assertSame('4444444444444444444444444444444444444444', \App\Support\ReleaseIdentity::commit());
        $this->assertSame('APP_COMMIT_SHA (runtime env)', \App\Support\ReleaseIdentity::commitSource());

        putenv('APP_COMMIT_SHA=garbage');
        putenv('SOURCE_COMMIT=also not a sha');
        $this->assertNull(\App\Support\ReleaseIdentity::commit());
        (new \App\Jobs\RecordQueueWorkerHeartbeatJob)->handle();
        $data = $this->actingAs($this->owner())->getJson(route('system-health.index'))->json('checks.application.data');
        $this->assertSame('Unverified', $data['commit']);
        $this->assertSame('Unverified', $data['worker_commit']);
        $this->assertSame('Unverified', $data['worker_matches_app']);
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
        config()->set('system-health.commit_sha', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        SystemHealthHeartbeat::beat('queue_worker', ['queue' => 'default', 'commit' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'build_at' => '2026-11-01T00:00:00+00:00']);
        $owner = $this->owner();

        $data = $this->actingAs($owner)->getJson(route('system-health.index'))->json('checks.application.data');

        $this->assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $data['worker_commit']);
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

    /** Round 3 ISSUE 4: only OBSERVED, well-formed identities are shown; app and worker are compared fairly. */
    public function test_malformed_commit_is_never_displayed_as_a_release(): void
    {
        config()->set('system-health.commit_sha', 'not-a-sha; DROP TABLE');
        SystemHealthHeartbeat::beat('queue_worker', ['commit' => 'zzz', 'build_at' => null]);
        $owner = $this->owner();

        $data = $this->actingAs($owner)->getJson(route('system-health.index'))->assertOk()->json('checks.application.data');

        $this->assertSame('Unverified', $data['commit']);
        $this->assertSame('Unverified', $data['worker_commit']);
        $this->assertSame('Unverified', $data['worker_matches_app']);
    }

    public function test_short_and_full_sha_of_the_same_release_match_and_source_is_reported(): void
    {
        $full = 'c0ffee1234567890c0ffee1234567890c0ffee12';
        config()->set('system-health.commit_sha', $full);
        config()->set('system-health.commit_source', 'SOURCE_COMMIT');
        SystemHealthHeartbeat::beat('queue_worker', ['commit' => substr($full, 0, 9), 'build_at' => '2026-11-17T10:04:00+00:00']);
        $owner = $this->owner();

        $data = $this->actingAs($owner)->getJson(route('system-health.index'))->assertOk()->json('checks.application.data');

        $this->assertSame($full, $data['commit']);
        $this->assertSame('SOURCE_COMMIT', $data['commit_source']);
        $this->assertSame('yes', $data['worker_matches_app']);
        $this->assertSame('2026-11-17T10:04:00+00:00', $data['worker_build_at']);
    }

    public function test_build_time_without_declaration_is_labelled_as_manifest_derived_not_declared(): void
    {
        config()->set('system-health.build_at', null);
        $owner = $this->owner();

        $data = $this->actingAs($owner)->getJson(route('system-health.index'))->assertOk()->json('checks.application.data');

        $this->assertContains($data['build_at_source'], ['build manifest mtime', 'Unverified']);
        $this->assertNotSame('APP_BUILD_AT', $data['build_at_source']);
    }

    /** Round 3 ISSUE 3: a dead worker must not pile up identical probes, and the page says what is waiting. */
    public function test_scheduler_skips_a_new_probe_while_one_is_already_waiting(): void
    {
        $this->assertFalse(\App\Jobs\RecordQueueWorkerHeartbeatJob::alreadyPending());

        dispatch(new \App\Jobs\RecordQueueWorkerHeartbeatJob);

        $this->assertTrue(\App\Jobs\RecordQueueWorkerHeartbeatJob::alreadyPending());
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_queue_card_lists_pending_job_types_read_only_and_is_critical_without_worker(): void
    {
        dispatch(new \App\Jobs\RecordQueueWorkerHeartbeatJob);
        $owner = $this->owner();

        $queue = $this->actingAs($owner)->getJson(route('system-health.index'))->assertOk()->json('checks.queue');

        $this->assertSame('critical', $queue['status']);
        $this->assertSame(1, $queue['data']['pending_types']['RecordQueueWorkerHeartbeatJob']);
        $this->assertDatabaseCount('jobs', 1); // nothing executed or removed
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
