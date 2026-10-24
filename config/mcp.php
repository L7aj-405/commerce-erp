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
        'customers.view' => ['permission' => 'customers.view', 'label' => 'View customers and their sales summary'],
        'customers.create' => ['permission' => 'customers.create', 'label' => 'Create customers'],
        'customers.update' => ['permission' => 'customers.update', 'label' => 'Update customers (including deactivation)'],
        'contacts.view' => ['permission' => 'contacts.view', 'label' => 'View organization contacts'],
        'contacts.create' => ['permission' => 'contacts.create', 'label' => 'Create organization contacts'],
        'contacts.update' => ['permission' => 'contacts.update', 'label' => 'Update organization contacts'],
        'contacts.archive' => ['permission' => 'contacts.archive', 'label' => 'Archive organization contacts'],
        'quotations.view' => ['permission' => 'quotations.view', 'label' => 'View quotations'],
        'quotations.create' => ['permission' => 'quotations.create', 'label' => 'Create quotation drafts'],
        'quotations.update' => ['permission' => 'quotations.update', 'label' => 'Update quotation drafts'],
        'quotations.issue' => ['permission' => 'quotations.issue', 'label' => 'Issue quotations'],
        'quotations.accept' => ['permission' => 'quotations.accept', 'label' => 'Record quotation acceptance'],
        'quotations.convert' => ['permission' => 'quotations.convert', 'label' => 'Convert quotations into draft sales orders'],
        'quotations.email' => ['permission' => 'quotations.email', 'label' => 'Email issued quotations'],
        'sales_orders.view' => ['permission' => 'sales_orders.view', 'label' => 'View sales orders'],
        'sales_orders.create' => ['permission' => 'sales_orders.create', 'label' => 'Create draft sales orders'],
        'sales_orders.update' => ['permission' => 'sales_orders.update', 'label' => 'Update draft sales orders'],
        'sales_orders.confirm' => ['permission' => 'sales_orders.confirm', 'label' => 'Confirm sales orders'],
    ],

    // Quotation documents (MCP-8): signed, connection-bound PDF links.
    'documents' => [
        'link_ttl_seconds' => (int) env('MCP_DOCUMENT_LINK_TTL_SECONDS', 600),
    ],

    // Sales Orders (MCP-7).
    'sales_orders' => [
        'max_items' => 200,
    ],

    // OAuth 2.1 authorization server for remote MCP clients (MCP-6). The issuer
    // and protected resource derive from APP_URL, which must be https in
    // production. Opaque `10xmcp_` connection secrets keep working alongside.
    'oauth' => [
        'enabled' => (bool) env('MCP_OAUTH_ENABLED', true),
        'dynamic_client_registration' => (bool) env('MCP_OAUTH_DYNAMIC_REGISTRATION', true),
        'authorization_code_ttl_seconds' => 120,
        'authorization_request_ttl_seconds' => 600,
        'access_token_ttl_seconds' => (int) env('MCP_OAUTH_ACCESS_TOKEN_TTL_SECONDS', 900),
        'refresh_token_ttl_days' => (int) env('MCP_OAUTH_REFRESH_TOKEN_TTL_DAYS', 30),
        'max_redirect_uris' => 10,
        'register_per_hour' => (int) env('MCP_OAUTH_REGISTER_PER_HOUR', 20),
        'token_per_minute' => (int) env('MCP_OAUTH_TOKEN_PER_MINUTE', 30),
    ],

    // Sales assistant (MCP-5) quotation drafts.
    'quotations' => [
        'max_items' => 200,
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
