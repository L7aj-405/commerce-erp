<?php

return [
    // Shipping proofs / carrier documents (F1.1). Stored on a private disk —
    // never a public path — and streamed back only through an authorized
    // controller. Use a shared disk (e.g. s3) when running more than one node.
    'attachments' => [
        'disk' => env('FULFILLMENT_ATTACHMENTS_DISK', 'local'),
        'max_kb' => (int) env('FULFILLMENT_ATTACHMENT_MAX_KB', 10240),
        'max_files' => 5,
        // Allowed MIME types (detected from content) => stored extension.
        'mime_types' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ],
    ],
];
