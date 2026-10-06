<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Models\Organization;
use App\Models\SystemHealthHeartbeat;
use App\Services\SystemHealthService;

final class SystemHealthNotificationMonitor
{
    public function __construct(private readonly SystemHealthService $health, private readonly NotificationPublisher $notifications) {}

    public function evaluateAll(): void
    {
        Organization::query()->where('status', 'active')->eachById(fn (Organization $organization) => $this->evaluate($organization));
    }

    public function evaluate(Organization $organization): void
    {
        $status = (string) $this->health->snapshot($organization, true)['status'];
        $key = 'health:organization:'.$organization->getKey();
        $state = SystemHealthHeartbeat::query()->find($key);
        $previous = data_get($state?->metadata, 'status');
        $sequence = (int) data_get($state?->metadata, 'sequence', 0);

        SystemHealthHeartbeat::beat($key, ['status' => $status, 'sequence' => $previous && $previous !== $status ? $sequence + 1 : $sequence]);
        if (! $previous || $previous === $status) {
            return;
        }

        $severity = match ($status) {
            'critical' => NotificationSeverity::Critical,
            'degraded' => NotificationSeverity::Warning,
            default => NotificationSeverity::Success,
        };
        $title = match ($status) {
            'critical' => 'État système critique',
            'degraded' => 'État système dégradé',
            default => 'État système rétabli',
        };
        $recipients = $this->notifications->recipientsWithPermission($organization, 'system.health.view');
        $this->notifications->publish($organization, $recipients, NotificationCategory::System, $severity, $title,
            "L’état opérationnel est passé de {$previous} à {$status}.", '/system-health', 'system_health', $organization->getKey(), 'transition.'.($sequence + 1).'.'.$status,
            ['previous_status' => $previous, 'status' => $status]);
    }
}
