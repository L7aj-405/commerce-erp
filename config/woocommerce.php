<?php

return [
    // Centralised REST API version — never hardcode elsewhere.
    'api_version' => 'wc/v3',

    // Items fetched per page (products, variations, categories). The WP REST
    // API caps `per_page` at 100; larger values are clamped to 100.
    'per_page' => (int) env('WOOCOMMERCE_PER_PAGE', 100),

    // HTTP client behaviour (seconds).
    'connect_timeout' => 10,
    'request_timeout' => 30,

    /*
    | Rate-limit-aware request policy (shared by every call of one client —
    | one client per sync run / connection test). The remote server's limits
    | are always respected: Retry-After is honoured, never shortened.
    */

    // Minimum spacing between two WooCommerce requests (pacing, no bursts).
    'min_request_interval_ms' => (int) env('WOOCOMMERCE_MIN_REQUEST_INTERVAL_MS', 250),

    // Retries of ONE request after a 429 / 500 / 502 / 503 / 504 / connection error.
    'max_retries' => (int) env('WOOCOMMERCE_MAX_RETRIES', 5),

    // Fallback exponential backoff when no Retry-After is sent: base · 2^(n-1)
    // (1s → 2s → 4s → 8s → 16s), ± jitter ratio, capped at max_retry_delay.
    'retry_base_delay_ms' => (int) env('WOOCOMMERCE_RETRY_BASE_DELAY_MS', 1000),
    'retry_jitter' => (float) env('WOOCOMMERCE_RETRY_JITTER', 0.25),

    // Longest single wait. A Retry-After above it is NOT shortened: the run stops
    // as "rate limited" and reports when WooCommerce allows requests again.
    'max_retry_delay_seconds' => (int) env('WOOCOMMERCE_MAX_RETRY_DELAY_SECONDS', 120),

    // Total backoff budget of one sync run (must stay well below the queue job
    // timeout of 3600 s and DB_QUEUE_RETRY_AFTER of 3900 s).
    'max_total_backoff_seconds' => (int) env('WOOCOMMERCE_MAX_TOTAL_BACKOFF_SECONDS', 900),

    // "Test connection" is interactive (an HTTP request): one short retry only.
    'interactive_max_retries' => 1,
    'interactive_max_retry_delay_seconds' => 3,

    // Hard ceiling on pages walked in a single run — a safety net, not a real limit.
    'max_pages' => 2000,
];
