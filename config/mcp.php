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

        // MCP-W7 suppliers, non-stock articles and quotation supplier sourcing. Each maps to the ERP
        // permission the canonical action enforces; the action re-checks it (and store membership) per call.
        'suppliers.view' => ['permission' => 'suppliers.view', 'label' => 'View suppliers'],
        'suppliers.create' => ['permission' => 'suppliers.manage', 'label' => 'Create suppliers'],
        'suppliers.update' => ['permission' => 'suppliers.manage', 'label' => 'Update suppliers'],
        'non_stock_items.view' => ['permission' => 'quotations.view', 'label' => 'View non-stock (external) articles'],
        'non_stock_items.create' => ['permission' => 'non_stock_items.manage', 'label' => 'Create non-stock articles'],
        'non_stock_items.update' => ['permission' => 'non_stock_items.manage', 'label' => 'Update non-stock articles'],
        'quotation_sourcing.view' => ['permission' => 'quotations.view', 'label' => 'View quotation supplier sourcing'],
        'quotation_sourcing.create' => ['permission' => 'quotations.update', 'label' => 'Create supplier availability requests on draft quotations'],
        // Recording a response needs procurement.manage, cancelling needs quotations.update or procurement.manage;
        // the canonical actions decide per call.
        'quotation_sourcing.update' => ['any_permission' => ['procurement.manage', 'quotations.update'], 'label' => 'Record or cancel supplier availability responses'],

        // MCP-W7 invoices and payments. Issuing also needs invoices.create (the canonical draft step) and
        // recording a payment on another day needs payments.backdate — the canonical actions check both.
        'invoices.view' => ['permission' => 'invoices.view', 'label' => 'View invoices'],
        'invoices.issue' => ['permission' => 'invoices.issue', 'label' => 'Issue invoices for fully paid sales orders'],
        'payments.view' => ['permission' => 'payments.view', 'label' => 'View payments'],
        'payments.create' => ['permission' => 'payments.create', 'label' => 'Record payments on confirmed sales orders'],

        // MCP purchasing: supplier purchase orders and goods receipts, mapped to the procurement permissions
        // the canonical actions enforce (they re-check them, and store membership, per call).
        'purchase_orders.view' => ['permission' => 'procurement.view', 'label' => 'View supplier purchase orders'],
        'purchase_orders.create' => ['permission' => 'procurement.manage', 'label' => 'Place supplier purchase orders for sales order requirements'],
        'procurement_receipts.create' => ['permission' => 'procurement.receive', 'label' => 'Record supplier goods receipts (stock in, reserved for the sales order)'],

        // W6 Work Center. Follow-up scopes mirror their ERP permissions. Attention and timelines span
        // document kinds: they need ANY listed permission, and each record is then filtered by the
        // canonical service (document view permission + active store), never widened by the scope.
        'follow_ups.view' => ['permission' => 'follow_ups.view', 'label' => 'View my work (follow-ups)'],
        'follow_ups.create' => ['permission' => 'follow_ups.create', 'label' => 'Create follow-ups'],
        'follow_ups.update' => ['permission' => 'follow_ups.update', 'label' => 'Edit, reschedule, complete, cancel or reopen follow-ups'],
        'follow_ups.manage_all' => ['permission' => 'follow_ups.manage_all', 'label' => 'Team workload: see and manage the team\'s work, assign attention'],
        'attention.view' => ['any_permission' => ['quotations.view', 'invoices.view', 'sales_orders.view'], 'label' => 'View attention signals on documents you can see'],
        'attention.update' => ['any_permission' => ['quotations.view', 'invoices.view', 'sales_orders.view'], 'label' => 'Act on attention: claim, snooze, dismiss'],
        'timeline.view' => ['any_permission' => ['customers.view', 'quotations.view', 'sales_orders.view', 'invoices.view'], 'label' => 'View business activity timelines'],
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
