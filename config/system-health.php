<?php

return [
    'app_version' => env('APP_VERSION'),
    'commit_sha' => env('APP_COMMIT_SHA'),
    'poll_seconds' => (int) env('SYSTEM_HEALTH_POLL_SECONDS', 20),
    'queue_heartbeat_stale_minutes' => (int) env('SYSTEM_HEALTH_QUEUE_STALE_MINUTES', 3),
    'scheduler_heartbeat_stale_minutes' => (int) env('SYSTEM_HEALTH_SCHEDULER_STALE_MINUTES', 3),
    'backup_stale_hours' => (int) env('SYSTEM_HEALTH_BACKUP_STALE_HOURS', 36),
    'woocommerce_stale_minutes' => (int) env('SYSTEM_HEALTH_WOOCOMMERCE_STALE_MINUTES', 75),
    'disk_warning_percent' => (int) env('SYSTEM_HEALTH_DISK_WARNING_PERCENT', 80),
    'disk_critical_percent' => (int) env('SYSTEM_HEALTH_DISK_CRITICAL_PERCENT', 90),
    'cache_seconds' => (int) env('SYSTEM_HEALTH_CACHE_SECONDS', 10),
];
