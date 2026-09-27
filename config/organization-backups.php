<?php

return [
    'disk' => env('ORG_BACKUP_DISK', 'organization_backups'),
    'retention_min' => 7,
    'retention_max' => 90,
];
