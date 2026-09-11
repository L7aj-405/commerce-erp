<?php

return [
    'locale' => 'fr',
    'invoice_template_version' => 'v1',
    'delivery_note_template_version' => 'v1',
    'quotation_template_version' => 'v1',

    /*
     * Fallback Devis options, used only until an organisation configures its own
     * under Paramètres → Documents → Devis (organization.settings.quotation_profile).
     */
    'quotation' => [
        'default_validity_days' => 30,
        'default_terms' => null,
        'default_notes' => null,
        'footer_text' => null,
    ],
    'pdf' => [
        'paper' => 'a4',
        'orientation' => 'portrait',
        'default_font' => 'DejaVu Sans',
        'remote_enabled' => false,
    ],

    /*
     * Lifetime of the temporary signed link that lets an issued Invoice PDF be
     * shared (e.g. pasted into WhatsApp). The link carries an HMAC signature over
     * the exact invoice id + expiry — it grants read-only access to that one PDF
     * and cannot be altered to reach another invoice.
     */
    'share_link_ttl_days' => 14,
];
