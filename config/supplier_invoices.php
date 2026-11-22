<?php

return [
    // Kill switch: supplier invoices are off until explicitly enabled.
    'enabled' => (bool) env('SUPPLIER_INVOICES_ENABLED', false),

    // Max |rounding_adjustment| (money string) a user may add to the computed total.
    'rounding_tolerance' => '0.05',

    // Fractional digits of the per-line rounding boundary (MAD: 2).
    'money_scale' => 2,

    // Soft duplicate warning: same supplier + same total within N days.
    'duplicate_window_days' => 7,

    // When true the approver must differ from the creator and the last saver.
    'require_distinct_approver' => false,

    // When true approval requires the supplier ICE to be filled.
    'require_supplier_ice_on_approval' => false,

    // Warn when the payment term exceeds N days (null = no warning).
    'payment_term_warning_days' => null,

    // Refuse voiding an approved invoice older than N days (null = no limit).
    'void_max_age_days' => null,

    // Scans / photos of the supplier's invoice (PR6). Private disk only; streamed back through an authorized route.
    'attachments' => [
        'disk' => env('SUPPLIER_INVOICE_ATTACHMENTS_DISK', 'local'),
        'max_kb' => (int) env('SUPPLIER_INVOICE_ATTACHMENT_MAX_KB', 10240),
        'max_files' => 10,
        // Allowed MIME types (detected from content) => stored extension.
        'mime_types' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ],
    ],
];
