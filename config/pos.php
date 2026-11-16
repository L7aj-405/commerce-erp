<?php

return [
    /*
    | POS draft lifecycle. Only Sales Orders with source=pos, status=draft and
    | never converted are ever considered; quotation / B2B / manual drafts are
    | untouched. Cleanup only runs for the Production environment (Test Mode
    | drafts are discarded by the Test Mode reset).
    */
    'drafts' => [
        // A cart that still has lines expires after this many hours WITHOUT a meaningful change.
        'inactivity_hours' => max(1, (int) env('POS_DRAFT_INACTIVITY_HOURS', 24)),
        // An empty cart (no lines) is removed after this many minutes of inactivity.
        'empty_grace_minutes' => max(5, (int) env('POS_EMPTY_DRAFT_GRACE_MINUTES', 60)),
        // Maximum drafts handled per scheduled run (and per organization chunk).
        'cleanup_batch_size' => max(1, min(1000, (int) env('POS_DRAFT_CLEANUP_BATCH_SIZE', 200))),
        // Daily schedule, HH:MM, interpreted in cleanup_timezone (the app timezone is UTC).
        'cleanup_time' => env('POS_DRAFT_CLEANUP_TIME', '03:50'),
        'cleanup_timezone' => env('POS_DRAFT_CLEANUP_TIMEZONE', 'Africa/Casablanca'),
        // Master switch for the scheduled run (the dry-run command always works).
        'cleanup_enabled' => (bool) env('POS_DRAFT_CLEANUP_ENABLED', true),
        // Manual deletion reuses the existing `sales_orders.cancel` permission:
        // roles that hold it may discard POS drafts; no new permission is introduced.
        'manual_delete_permission' => 'sales_orders.cancel',
    ],
];
