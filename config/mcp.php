<?php

return [
    'rate_limit_per_minute' => (int) env('MCP_RATE_LIMIT_PER_MINUTE', 120),
    'max_expiration_days' => 365,
    'session_ttl_seconds' => 3600,
    'preview_ttl_seconds' => 300,
    'allowed_hosts' => array_values(array_unique(array_filter(array_merge(
        [parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)],
        array_map('trim', explode(',', (string) env('MCP_ALLOWED_HOSTS', 'localhost,127.0.0.1,[::1]'))),
    )))),
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('MCP_ALLOWED_ORIGINS', ''))))),

    // Bulk product operations (MCP-4). Rows are validated synchronously up to
    // sync_validation_max_items; larger operations validate on the queue and
    // the client polls get_product_operation for the preview.
    'operations' => [
        'max_items' => 5000,
        'chunk_size' => 100,
        'sync_validation_max_items' => 500,
        'preview_ttl_seconds' => (int) env('MCP_OPERATION_PREVIEW_TTL_SECONDS', 900),
        'max_item_attempts' => 3,
        'backoff_seconds' => [10, 30, 90],
        'max_active_per_connection' => 3,
        'items_max_per_page' => 200,
        'retention_days' => 30,
        'prepare_per_minute' => (int) env('MCP_OPERATION_PREPARE_PER_MINUTE', 10),
        'execute_per_minute' => (int) env('MCP_OPERATION_EXECUTE_PER_MINUTE', 10),
        'queue' => env('MCP_OPERATION_QUEUE'),
    ],

    // A scope is an additional ceiling. The owning user must also hold the
    // corresponding ERP permission in the connection's organization.
    'scopes' => [
        'products.view' => ['permission' => 'products.view', 'label' => 'View products'],
        'products.create' => ['permission' => 'products.create', 'label' => 'Create products'],
        'products.update' => ['permission' => 'products.update', 'label' => 'Update products'],
        'products.archive' => ['permission' => 'products.archive', 'label' => 'Archive products'],
        'product_cost.view' => ['permission' => 'product_cost.view', 'label' => 'View purchase costs'],
        'product_cost.manage' => ['permission' => 'product_cost.manage', 'label' => 'Manage purchase costs'],
        'inventory.view' => ['permission' => 'inventory.view', 'label' => 'View organization stock'],
        'brands.manage' => ['permission' => 'brands.manage', 'label' => 'Manage brands'],
        'categories.manage' => ['permission' => 'categories.manage', 'label' => 'Manage categories'],
        'units.manage' => ['permission' => 'units.manage', 'label' => 'Manage units'],
        'tax_rates.view' => ['permission' => 'tax_rates.view', 'label' => 'View tax rates'],
    ],

    'presets' => [
        'read_only' => ['products.view', 'inventory.view', 'tax_rates.view'],
        // Cost access is deliberately excluded and must be selected explicitly.
        'product_manager' => [
            'products.view', 'products.create', 'products.update', 'products.archive',
            'inventory.view', 'brands.manage', 'categories.manage', 'units.manage', 'tax_rates.view',
        ],
    ],
];
