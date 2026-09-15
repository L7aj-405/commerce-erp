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
     * Company stamp ("cachet de l'entreprise"). All measurements are in
     * millimetres, resolved against the A4 content box (the PDF templates all
     * use `@page { margin: 20mm 13mm ~28mm }`, so the printable area is
     * ~184mm x ~247mm) — never browser pixels, so the same configuration
     * renders identically regardless of viewport.
     */
    'stamp' => [
        'default_anchor' => 'bottom_left',
        'default_offset_x_mm' => 0,
        'default_offset_y_mm' => 5,
        'default_display_width_mm' => 35,
        'default_rotation_deg' => 0,
        // A wide rectangular stamp needs real headroom; 100mm is generous for
        // an A4 whose content box is ~184mm wide. The per-field max below is
        // only a sanity cap on the raw value — the true authority is
        // DocumentStampBoundary::assertFitsSafeArea(), which rejects any
        // width/offset/rotation combination that would actually overflow the
        // page, regardless of these bounds.
        'min_display_width_mm' => 20,
        'max_display_width_mm' => 100,
        // Keeps the stamp inside the content box regardless of anchor/offset —
        // clamped in DocumentStampBoundary rather than trusted from input.
        'max_offset_x_mm' => 120,
        'max_offset_y_mm' => 180,
        // 0° = horizontal. Negative = counter-clockwise, positive = clockwise.
        'min_rotation_deg' => -20,
        'max_rotation_deg' => 20,
        'max_upload_kb' => 2048,
        'min_image_dimension' => 100,
        'max_image_dimension' => 3000,
        // The printable content box every document template's @page margin
        // produces (Invoice/Devis: 210-13-13 x 297-20-30). Used only by
        // DocumentStampBoundary::assertFitsSafeArea() to reject a
        // width/offset/rotation combination that would clip off the page.
        'content_box_width_mm' => 184,
        'content_box_height_mm' => 247,
        'safety_margin_mm' => 3,
    ],

    /*
     * Lifetime of the temporary signed link that lets an issued Invoice PDF be
     * shared (e.g. pasted into WhatsApp). The link carries an HMAC signature over
     * the exact invoice id + expiry — it grants read-only access to that one PDF
     * and cannot be altered to reach another invoice.
     */
    'share_link_ttl_days' => 14,
];
