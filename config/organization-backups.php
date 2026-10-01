<?php

return [
    'disk' => env('ORG_BACKUP_DISK', 'organization_backups'),
    'retention_min' => 7,
    'retention_max' => 90,
    'signing_key' => env('ORG_BACKUP_SIGNING_KEY'),
    'encryption_key' => env('ORG_BACKUP_ENCRYPTION_KEY'),
    'key_id' => env('ORG_BACKUP_KEY_ID', 'primary'),
    'allow_legacy_unsigned_restore' => (bool) env('ORG_BACKUP_ALLOW_LEGACY_UNSIGNED_RESTORE', false),
    'temporary_file_ttl_hours' => (int) env('ORG_BACKUP_TEMPORARY_FILE_TTL_HOURS', 24),
    'pre_restore_retention_days' => (int) env('ORG_BACKUP_PRE_RESTORE_RETENTION_DAYS', 30),
    'limits' => [
        'archive_bytes' => (int) env('ORG_BACKUP_MAX_ARCHIVE_BYTES', 214748364),
        'entries' => (int) env('ORG_BACKUP_MAX_ENTRIES', 256),
        'entry_bytes' => (int) env('ORG_BACKUP_MAX_ENTRY_BYTES', 134217728),
        'uncompressed_bytes' => (int) env('ORG_BACKUP_MAX_UNCOMPRESSED_BYTES', 1073741824),
        'compression_ratio' => (int) env('ORG_BACKUP_MAX_COMPRESSION_RATIO', 500),
        'manifest_bytes' => (int) env('ORG_BACKUP_MAX_MANIFEST_BYTES', 262144),
        'jsonl_line_bytes' => (int) env('ORG_BACKUP_MAX_JSONL_LINE_BYTES', 4194304),
        'rows_per_table' => (int) env('ORG_BACKUP_MAX_ROWS_PER_TABLE', 2000000),
    ],
];
