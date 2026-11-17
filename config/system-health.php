<?php

return [
    'app_version' => env('APP_VERSION'),
    // Observed deployment identity only. APP_COMMIT_SHA wins; otherwise the variable Coolify injects
    // (SOURCE_COMMIT). Never hardcode a SHA here. 'commit_source' says which variable supplied it.
    'commit_sha' => env('APP_COMMIT_SHA') ?: env('SOURCE_COMMIT'),
    'commit_source' => env('APP_COMMIT_SHA') ? 'APP_COMMIT_SHA' : (env('SOURCE_COMMIT') ? 'SOURCE_COMMIT' : null),
    // ISO-8601 build timestamp injected by the image build (APP_BUILD_AT); falls back to the Vite manifest mtime.
    'build_at' => env('APP_BUILD_AT'),
    'poll_seconds' => (int) env('SYSTEM_HEALTH_POLL_SECONDS', 20),
    'queue_heartbeat_stale_minutes' => (int) env('SYSTEM_HEALTH_QUEUE_STALE_MINUTES', 3),
    'scheduler_heartbeat_stale_minutes' => (int) env('SYSTEM_HEALTH_SCHEDULER_STALE_MINUTES', 3),
    'backup_stale_hours' => (int) env('SYSTEM_HEALTH_BACKUP_STALE_HOURS', 36),
    'woocommerce_stale_minutes' => (int) env('SYSTEM_HEALTH_WOOCOMMERCE_STALE_MINUTES', 75),
    'disk_warning_percent' => (int) env('SYSTEM_HEALTH_DISK_WARNING_PERCENT', 80),
    'disk_critical_percent' => (int) env('SYSTEM_HEALTH_DISK_CRITICAL_PERCENT', 90),
    'cache_seconds' => (int) env('SYSTEM_HEALTH_CACHE_SECONDS', 10),
];
