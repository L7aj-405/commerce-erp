<?php

return [
    // Centralised REST API version — never hardcode elsewhere.
    'api_version' => 'wc/v3',

    // Products fetched per page during a sync.
    'per_page' => 50,

    // HTTP client behaviour (seconds).
    'connect_timeout' => 10,
    'request_timeout' => 30,

    // Transient-failure retry policy (retries, base backoff ms).
    'retry_times' => 2,
    'retry_backoff_ms' => 500,

    // Hard ceiling on pages walked in a single run — a safety net, not a real limit.
    'max_pages' => 2000,
];
