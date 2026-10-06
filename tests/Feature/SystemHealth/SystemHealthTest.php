<?php

namespace Tests\Feature\SystemHealth;

use App\Models\Organization;
use App\Models\OrganizationBackup;
use App\Models\OrganizationBackupSetting;
use App\Models\SystemHealthHeartbeat;
use App\Models\User;
use App\Services\PermissionProvisioner;
use App\Services\SystemHealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PlatformTestCase;

class SystemHealthTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('queue.default', 'database');
        config()->set('system-health.cache_seconds', 1);
    }

    public function test_health_permission_is_idempotently_provisioned_to_system_roles_only(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $permission = $this->permission('system.health.view');
        $admin = $organization->roles()->where('slug', 'admin')->firstOrFail();
        $custom = $this->createRole($organization, [], 'Custom operations role');
        $admin->permissions()->detach($permission->id);

        app(PermissionProvisioner::class)->provisionAllOrganizations();
        app(PermissionProvisioner::class)->provisionAllOrganizations();

        $this->assertTrue($admin->fresh()->permissions()->where('key', 'system.health.view')->exists());
        $this->assertFalse($custom->fresh()->permissions()->where('key', 'system.health.view')->exists());
    }

    public function test_authorized_owner_can_view_health_center_and_json_poll_endpoint(): void
    {
        [$owner, $organization] = $this->ownerAndOrganization();

        $this->actingAs($owner)->get(route('system-health.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SystemHealth/Index')
                ->has('snapshot.checks.application')
                ->has('snapshot.checks.database')
                ->has('snapshot.checks.queue')
                ->has('snapshot.checks.scheduler'));

        $this->actingAs($owner)->getJson(route('system-health.index'))->assertOk()
            ->assertJsonPath('checks.database.status', 'operational')
            ->assertJsonMissingPath('checks.smtp.data.smtp_password');
    }

    public function test_member_without_health_permission_is_forbidden(): void
    {
        [$owner, $organization] = $this->ownerAndOrganization();
        $member = User::factory()->create();
        $this->addOrganizationMember($organization, $member, ['products.view']);
        $this->activate($member, $organization);

        $this->actingAs($member)->get(route('system-health.index'))->assertForbidden();
    }

    public function test_admin_view_does_not_receive_restricted_global_metrics_or_failed_job_metadata(): void
    {
        [$owner, $organization] = $this->ownerAndOrganization();
        $admin = User::factory()->create();
        $adminRole = $organization->roles()->where('slug', 'admin')->firstOrFail();
        $membership = $this->addOrganizationMember($organization, $admin, [], roleName: 'Temporary role');
        $membership->role_id = $adminRole->id;
        $membership->save();
        $this->activate($admin, $organization);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'private-queue',
            'payload' => '{}', 'exception' => 'private failure', 'failed_at' => now(),
        ]);

        $this->actingAs($admin)->getJson(route('system-health.index'))->assertOk()
            ->assertJsonPath('checks.database.status', 'unknown')
            ->assertJsonPath('checks.storage.status', 'unknown')
            ->assertJsonCount(0, 'failed_jobs');
    }

    public function test_organization_operational_data_is_tenant_scoped_and_failure_text_is_redacted(): void
    {
        $this->travelTo('2026-10-05 12:00:00');
        [$ownerA, $organizationA] = $this->ownerAndOrganization('Organization A');
        $ownerB = User::factory()->create();
        $organizationB = $this->createOrganization($ownerB, 'Organization B');
        $this->completedBackup($organizationA, now()->subHour());
        $this->failedBackup($organizationA, 'password=visible-secret smtp://user:pass@example.test');
        $this->completedBackup($organizationB, now()->subMinute());
        Cache::flush();

        $response = $this->actingAs($ownerA)->getJson(route('system-health.index'))->assertOk();

        $response->assertJsonPath('checks.backups.data.latest_success_at', now()->subHour()->toIso8601String());
        $this->assertStringNotContainsString('visible-secret', $response->getContent());
        $this->assertStringNotContainsString('user:pass', $response->getContent());
        $this->assertStringNotContainsString($organizationB->name, $response->getContent());
    }

    public function test_queue_metrics_and_worker_heartbeat_freshness_are_reported(): void
    {
        [$owner] = $this->ownerAndOrganization();
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->timestamp, 'created_at' => now()->subMinutes(10)->timestamp,
        ]);

        $this->actingAs($owner)->getJson(route('system-health.index'))
            ->assertJsonPath('checks.queue.status', 'critical')
            ->assertJsonPath('checks.queue.data.waiting_jobs', 1);

        SystemHealthHeartbeat::beat('queue_worker', ['queue' => 'default']);
        Cache::flush();
        $this->actingAs($owner)->getJson(route('system-health.index'))
            ->assertJsonPath('checks.queue.status', 'operational')
            ->assertJsonPath('checks.queue.data.waiting_jobs', 1);
    }

    public function test_scheduler_heartbeat_transitions_from_stale_to_fresh(): void
    {
        [$owner] = $this->ownerAndOrganization();
        $heartbeat = new SystemHealthHeartbeat;
        $heartbeat->key = 'scheduler';
        $heartbeat->last_seen_at = now()->subMinutes(10);
        $heartbeat->metadata = [];
        $heartbeat->save();

        $this->actingAs($owner)->getJson(route('system-health.index'))
            ->assertJsonPath('checks.scheduler.status', 'degraded');

        SystemHealthHeartbeat::beat('scheduler');
        Cache::flush();
        $this->actingAs($owner)->getJson(route('system-health.index'))
            ->assertJsonPath('checks.scheduler.status', 'operational');
    }

    public function test_backup_age_is_calculated_from_latest_success_for_active_organization(): void
    {
        $this->travelTo('2026-10-05 12:00:00');
        [$owner, $organization] = $this->ownerAndOrganization();
        $this->completedBackup($organization, now()->subHours(48));
        $setting = OrganizationBackupSetting::query()->where('organization_id', $organization->id)->first() ?? new OrganizationBackupSetting;
        $setting->organization_id = $organization->id;
        $setting->enabled = true;
        $setting->frequency = 'daily';
        $setting->time_of_day = '03:00:00';
        $setting->timezone = 'UTC';
        $setting->retention_count = 30;
        $setting->notify_on_failure = false;
        $setting->save();
        Cache::flush();

        $this->actingAs($owner)->getJson(route('system-health.index'))
            ->assertJsonPath('checks.backups.status', 'degraded')
            ->assertJsonPath('checks.backups.data.latest_success_age_hours', 48);
    }

    public function test_disk_threshold_classification_is_deterministic(): void
    {
        config()->set('system-health.disk_warning_percent', 80);
        config()->set('system-health.disk_critical_percent', 90);
        $service = app(SystemHealthService::class);

        $this->assertSame('operational', $service->classifyDiskUsage(79.9));
        $this->assertSame('degraded', $service->classifyDiskUsage(80));
        $this->assertSame('critical', $service->classifyDiskUsage(90));
    }

    public function test_failed_job_details_never_expose_payload_or_exception(): void
    {
        [$owner] = $this->ownerAndOrganization();
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{"smtp_password":"must-not-leak"}', 'exception' => 'token=must-not-leak', 'failed_at' => now(),
        ]);

        $response = $this->actingAs($owner)->getJson(route('system-health.index'))->assertOk()
            ->assertJsonCount(1, 'failed_jobs')
            ->assertJsonMissingPath('failed_jobs.0.payload')
            ->assertJsonMissingPath('failed_jobs.0.exception');
        $this->assertStringNotContainsString('must-not-leak', $response->getContent());
    }

    /** @return array{User, Organization} */
    private function ownerAndOrganization(string $name = 'Organization'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner, $name);
        $this->activate($owner, $organization);

        return [$owner, $organization];
    }

    private function completedBackup(Organization $organization, $completedAt): OrganizationBackup
    {
        $backup = new OrganizationBackup;
        $backup->organization_id = $organization->id;
        $backup->uuid = (string) Str::uuid();
        $backup->type = OrganizationBackup::TYPE_SCHEDULED;
        $backup->status = OrganizationBackup::STATUS_COMPLETED;
        $backup->completed_at = $completedAt;
        $backup->save();

        return $backup;
    }

    private function failedBackup(Organization $organization, string $message): OrganizationBackup
    {
        $backup = new OrganizationBackup;
        $backup->organization_id = $organization->id;
        $backup->uuid = (string) Str::uuid();
        $backup->type = OrganizationBackup::TYPE_SCHEDULED;
        $backup->status = OrganizationBackup::STATUS_FAILED;
        $backup->completed_at = now();
        $backup->failure_message = $message;
        $backup->save();

        return $backup;
    }
}
