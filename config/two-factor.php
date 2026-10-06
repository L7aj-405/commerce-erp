<?php

return [
    'trusted_device_cookie' => env('TRUSTED_2FA_COOKIE', 'trusted_2fa_device'),
    'trusted_device_durations' => [7, 15, 20, 30],
    'trusted_device_default_days' => (int) env('TRUSTED_2FA_DEFAULT_DAYS', 30),
    'trusted_device_max_days' => (int) env('TRUSTED_2FA_MAX_DAYS', 30),
    'trusted_device_privileged_max_days' => (int) env('TRUSTED_2FA_PRIVILEGED_MAX_DAYS', 15),
    'trusted_device_same_site' => 'lax',
    'trusted_device_cleanup_days' => (int) env('TRUSTED_2FA_CLEANUP_DAYS', 30),
];
