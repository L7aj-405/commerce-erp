<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Models\OrganizationBackup;
use App\Models\SystemHealthHeartbeat;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WooCommerceSyncRun;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Notifications\OperationalNotificationProducer;
use App\Services\Notifications\SystemHealthNotificationMonitor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PlatformTestCase;

class NotificationCenterTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('queue.default', 'database');
        config()->set('system-health.cache_seconds', 1);
    }

    public function test_notification_center_requires_authentication(): void
    {
        $this->get(route('notifications.index'))->assertRedirect('/login');
        $this->getJson(route('notifications.feed'))->assertUnauthorized();
    }

    public function test_feed_is_scoped_to_current_user_and_active_organization(): void
    {
        $owner = User::factory()->create();
        $organizationA = $this->createOrganization($owner, 'A');
        $organizationB = $this->createOrganization($owner, 'B');
        $other = User::factory()->create();
        $this->addOrganizationMember($organizationA, $other, ['products.view']);
        $this->activate($owner, $organizationA);

        $own = $this->publish($owner, $organizationA, 'own');
        $foreignOrganization = $this->publish($owner, $organizationB, 'foreign-org');
        $this->publish($other, $organizationA, 'foreign-user');

        $this->actingAs($owner)->getJson(route('notifications.feed'))->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('recent.0.id', $own->id);
        $this->actingAs($owner)->patchJson(route('notifications.read', $foreignOrganization))->assertNotFound();
    }

    public function test_read_unread_and_mark_all_mutations_only_change_owned_notifications(): void
    {
        [$owner, $organization] = $this->ownerAndOrganization();
        $first = $this->publish($owner, $organization, 'first');
        $second = $this->publish($owner, $organization, 'second');

        $this->actingAs($owner)->patchJson(route('notifications.read', $first))->assertOk();
        $this->assertNotNull($first->fresh()->read_at);
        $this->actingAs($owner)->patchJson(route('notifications.unread', $first))->assertOk();
        $this->assertNull($first->fresh()->read_at);
        $this->actingAs($owner)->postJson(route('notifications.read-all'))->assertOk();
        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNotNull($second->fresh()->read_at);
    }

    public function test_filters_and_server_side_pagination_are_applied(): void
    {
        [$owner, $organization] = $this->ownerAndOrganization();
        foreach (range(1, 23) as $index) {
            $this->publish($owner, $organization, 'item-'.$index, $index === 23 ? NotificationCategory::Backup : NotificationCategory::Sales);
        }

        $this->actingAs($owner)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Notifications/Index')->has('notifications.data', 20)->where('notifications.total', 23));
        $this->actingAs($owner)->get(route('notifications.index', ['category' => 'backup', 'status' => 'unread']))
            ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 1)->where('notifications.total', 1));
    }

    public function test_preferences_persist_and_critical_security_cannot_be_suppressed(): void
    {
        [$owner, $organization] = $this->ownerAndOrganization();
        $this->actingAs($owner)->put(route('notifications.preferences.update'), [
            'sound_enabled' => true, 'sound_volume' => 0.35, 'disabled_categories' => ['sales', 'security'],
        ])->assertRedirect();

        $this->assertDatabaseHas('user_notification_preferences', ['user_id' => $owner->id, 'sound_enabled' => true, 'sound_volume' => 0.35]);
        $publisher = app(NotificationPublisher::class);
        $publisher->publishToUser($owner, $organization, NotificationCategory::Sales, NotificationSeverity::Info, 'Hidden', 'Hidden', null, 'test', 1, 'sales');
        $publisher->publishToUser($owner, $organization, NotificationCategory::Security, NotificationSeverity::Critical, 'Required', 'Required', '/security', 'test', 2, 'security');
        $this->assertDatabaseMissing('user_notifications', ['title' => 'Hidden']);
        $this->assertDatabaseHas('user_notifications', ['title' => 'Required']);
    }

    public function test_deduplication_and_sensitive_metadata_redaction_are_server_side(): void
    {
        [$owner, $organization] = $this->ownerAndOrganization();
        config()->set('notifications.platform_to_tenants', true); // delivery mechanics, with platform routing enabled
        $publisher = app(NotificationPublisher::class);
        foreach (range(1, 2) as $ignored) {
            $publisher->publishToUser($owner, $organization, NotificationCategory::System, NotificationSeverity::Warning,
                'Alert', 'password=must-not-leak', '/system-health', 'health', 7, 'degraded', ['access_token' => 'secret', 'safe' => 'visible']);
        }

        $this->assertSame(1, UserNotification::query()->count());
        $notification = UserNotification::query()->firstOrFail();
        $this->assertStringNotContainsString('must-not-leak', $notification->message);
        $this->assertSame(['safe' => 'visible'], $notification->metadata);
    }

    public function test_recipient_resolution_requires_active_membership_and_permission(): void
    {
        [$owner, $organization] = $this->ownerAndOrganization();
        $allowed = User::factory()->create();
        $denied = User::factory()->create();
        $inactive = User::factory()->create();
        $this->addOrganizationMember($organization, $allowed, ['integrations.view']);
        $this->addOrganizationMember($organization, $denied, ['products.view']);
        $this->addOrganizationMember($organization, $inactive, ['integrations.view'], 'inactive');

        $publisher = app(NotificationPublisher::class);
        $publisher->publish($organization, $publisher->recipientsWithPermission($organization, 'integrations.view'),
            NotificationCategory::WooCommerce, NotificationSeverity::Success, 'Sync', 'Done', null, 'sync', 1, 'completed');

        $this->assertDatabaseHas('user_notifications', ['user_id' => $owner->id]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $allowed->id]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $denied->id]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $inactive->id]);
    }

    public function test_operational_producers_create_one_notification_per_woo_and_backup_event(): void
    {
        [$owner, $organization] = $this->ownerAndOrganization();
        $producer = app(OperationalNotificationProducer::class);
        $run = new WooCommerceSyncRun;
        $run->id = 91;
        $run->status = WooCommerceSyncRun::STATUS_COMPLETED;
        $run->products_read = 8;
        $run->products_created = 2;
        $run->products_updated = 6;
        $run->products_skipped = 0;
        $run->products_failed = 0;
        $backup = new OrganizationBackup;
        $backup->id = 72;

        $producer->wooCommerce($organization, $run);
        $producer->wooCommerce($organization, $run);
        $producer->backupCompleted($organization, $backup);
        $producer->backupCompleted($organization, $backup);

        $this->assertSame(2, UserNotification::query()->where('user_id', $owner->id)->count());
        $this->assertDatabaseHas('user_notifications', ['source_type' => 'woocommerce_sync_run', 'source_id' => '91']);
        $this->assertDatabaseHas('user_notifications', ['source_type' => 'organization_backup', 'source_id' => '72']);
    }

    public function test_health_monitor_notifies_only_on_meaningful_transition(): void
    {
        [$owner, $organization] = $this->ownerAndOrganization();
        config()->set('notifications.platform_to_tenants', true); // transition logic, with platform routing enabled
        $monitor = app(SystemHealthNotificationMonitor::class);
        $monitor->evaluate($organization);
        $this->assertSame(0, UserNotification::query()->count(), 'Initial health state is only a baseline.');

        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        Cache::flush();
        $monitor->evaluate($organization);
        $monitor->evaluate($organization);

        $this->assertSame(1, UserNotification::query()->where('user_id', $owner->id)->where('source_type', 'system_health')->count());
        $this->assertDatabaseHas('user_notifications', ['severity' => 'critical']);
    }

    public function test_platform_health_events_are_not_delivered_to_tenant_users_by_default(): void
    {
        [$owner, $organization] = $this->ownerAndOrganization();
        $this->assertFalse(config('notifications.platform_to_tenants'));
        $monitor = app(SystemHealthNotificationMonitor::class);
        $monitor->evaluate($organization);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        Cache::flush();
        $monitor->evaluate($organization);

        // Monitoring still runs and records the transition; nothing reaches the tenant feed.
        $this->assertSame(1, (int) data_get(SystemHealthHeartbeat::query()->find('health:organization:'.$organization->getKey())?->metadata, 'sequence'));
        $this->assertSame(0, UserNotification::query()->where('category', 'system')->count());

        // Historical platform rows are kept but hidden; business notifications are unaffected.
        $historical = new UserNotification;
        $historical->forceFill(['user_id' => $owner->getKey(), 'organization_id' => $organization->getKey(), 'category' => 'system', 'severity' => 'critical',
            'title' => 'État système critique', 'message' => 'Ancien', 'source_type' => 'system_health', 'source_id' => '1', 'event_type' => 'old', 'dedupe_key' => 'old'])->save();
        $this->publish($owner, $organization, 'business');
        $this->actingAs($owner)->getJson(route('notifications.feed'))->assertOk()
            ->assertJsonPath('unread_count', 1)->assertJsonCount(1, 'recent')->assertJsonPath('recent.0.category', 'sales');
        $this->actingAs($owner)->get(route('notifications.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('notifications.total', 1)->where('categories', fn ($categories) => ! collect($categories)->contains('system')));
        $this->assertDatabaseHas('user_notifications', ['id' => $historical->getKey()]);
    }

    private function publish(User $user, $organization, string $source, NotificationCategory $category = NotificationCategory::Sales): UserNotification
    {
        app(NotificationPublisher::class)->publishToUser($user, $organization, $category, NotificationSeverity::Info,
            'Notification '.$source, 'Message '.$source, '/platform', 'test', $source, 'created');

        return UserNotification::query()->where('user_id', $user->id)->where('source_id', $source)->firstOrFail();
    }

    private function ownerAndOrganization(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        return [$owner, $organization];
    }
}
