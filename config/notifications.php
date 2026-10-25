<?php

return [
    'poll_seconds' => (int) env('NOTIFICATION_POLL_SECONDS', 20),
    'retention_days' => (int) env('NOTIFICATION_RETENTION_DAYS', 90),
    'unread_retention_days' => (int) env('NOTIFICATION_UNREAD_RETENTION_DAYS', 180),
    'recent_limit' => 8,
    // Platform / system-health notifications (category "system") are not
    // delivered to — nor shown in — tenant-facing feeds during the pilot.
    // Health monitoring keeps running; a later Platform Operations console
    // will be their audience. Set true only to restore tenant delivery.
    'platform_to_tenants' => (bool) env('NOTIFICATIONS_PLATFORM_TO_TENANTS', false),
];
