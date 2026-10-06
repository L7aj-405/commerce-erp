<?php

return [
    'fresh_auth_timeout_minutes' => (int) env('SECURITY_FRESH_AUTH_TIMEOUT_MINUTES', 15),

    // A role is privileged when its protected system-role slug is listed here
    // OR when its permission profile contains one of these high-impact keys.
    // Keeping this policy here avoids scattering owner/admin name checks
    // through authentication and trusted-device code.
    'privileged_role_slugs' => ['owner', 'admin'],
    'privileged_permission_keys' => [
        'roles.assign-permissions',
        'organization_backups.restore',
        'system.health.manage',
    ],
];
