<?php

return [
    'poll_seconds' => (int) env('NOTIFICATION_POLL_SECONDS', 20),
    'retention_days' => (int) env('NOTIFICATION_RETENTION_DAYS', 90),
    'unread_retention_days' => (int) env('NOTIFICATION_UNREAD_RETENTION_DAYS', 180),
    'recent_limit' => 8,
];
