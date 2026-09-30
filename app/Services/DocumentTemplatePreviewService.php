<?php

namespace App\Services;

use App\Contracts\PdfGenerator;
use App\Models\Organization;

/**
 * Read-only Studio preview rendered through the real commercial Blade and
 * Dompdf pipeline. It never publishes a template or mutates a document.
 */
class DocumentTemplatePreviewService
{
    public function __construct(
        private readonly PdfGenerator $pdf,
        private readonly DocumentTemplateRegistry $templates,
        private readonly DocumentSellerProfile $sellerProfiles,
        private readonly DocumentStampRenderer $stamps,
    ) {}

    /** @param array<string, mixed> $template */
    public function render(Organization $organization, array $template): string
    {
        $profile = data_get($organization->settings, 'document_profile', []);
        $seller = $this->sellerProfiles->organizationIdentity($organization) + [
            'fax' => $profile['fax'] ?? null,
            'website' => $profile['website'] ?? null,
            'patente_number' => $profile['patente_number'] ?? null,
            'bank' => ['name' => $profile['bank_name'] ?? null, 'rib' => $profile['bank_rib'] ?? null],
            'footer_text' => $profile['footer_text'] ?? null,
            'additional_identifiers' => $profile['additional_identifiers'] ?? [],
        ];
        $presentation = $this->sellerProfiles->presentationSettingsForTemplate($organization, $template);
        $seller = array_replace($seller, $presentation);

        $stampStyle = data_get($presentation, 'pdf_style.stamp', []);
        $stamp = null;
        if ((bool) ($stampStyle['visible'] ?? true)) {
            $activeStamp = $organization->activeDocumentStamp()->first();
            if ($activeStamp) {
                $stamp = $this->stamps->preview(
                    $activeStamp,
                    $stampStyle['position_anchor'] ?? null,
                    isset($stampStyle['offset_x_mm']) ? (float) $stampStyle['offset_x_mm'] : null,
                    isset($stampStyle['offset_y_mm']) ? (float) $stampStyle['offset_y_mm'] : null,
                    isset($stampStyle['display_width_mm']) ? (float) $stampStyle['display_width_mm'] : null,
                    isset($stampStyle['rotation_deg']) ? (float) $stampStyle['rotation_deg'] : null,
                );
                if ($stamp) {
                    $stamp['display_height_mm'] = $stampStyle['display_height_mm'] ?? null;
                    $stamp['opacity'] = (int) ($stampStyle['opacity'] ?? 100);
                    $stamp['preserve_aspect_ratio'] = (bool) ($stampStyle['preserve_aspect_ratio'] ?? true);
                }
            }
        }

        $payload = [
            'kind' => 'invoice',
            'title' => __('documents.invoice', locale: config('documents.locale')),
            'watermark' => null,
            'has_discount' => true,
            'document' => [
                'number' => '2/2026', 'version' => 2, 'date' => '29/09/2026',
                'order_number' => 'SO-000123', 'currency' => 'MAD',
                'representative' => 'AGDAY MOHAMED', 'payment_method' => 'Espèces', 'notes' => null,
            ],
            'seller' => $seller,
            'buyer' => [
                'name' => 'AGDAY MOHAMED', 'company' => '10xScale', 'email' => null,
                'phone' => '0773749576', 'tax_identifier' => '111111111111111',
                'address' => 'Abouab Gueliz, Marrakech',
            ],
            'lines' => [
                ['reference' => 'HS71', 'description' => 'YAMAHA ENCEINTE DE STUDIO - HS7', 'variant' => null, 'sku' => 'HS71', 'presentation' => 'Unité', 'quantity' => '2', 'unit_price_ht' => '3 375,00', 'line_total_ht' => '6 750,00', 'discount' => '83,33', 'has_discount' => true, 'tax_rate' => '20,00%', 'total' => '8 000,00'],
                ['reference' => 'CBL10', 'description' => 'Câble audio professionnel', 'variant' => null, 'sku' => 'CBL10', 'presentation' => 'Pièce', 'quantity' => '1', 'unit_price_ht' => '125,00', 'line_total_ht' => '125,00', 'discount' => '0,00', 'has_discount' => false, 'tax_rate' => '20,00%', 'total' => '150,00'],
            ],
            'tax_lines' => [['label' => 'TVA', 'rate' => '20,00%', 'base' => '6 791,67', 'amount' => '1 358,33']],
            'totals' => ['subtotal' => '6 875,00', 'discount' => '83,33', 'discount_label' => 'Remise (10 %)', 'net' => '6 791,67', 'tax' => '1 358,33', 'total' => '8 150,00'],
            'amount_in_words' => 'HUIT MILLE CENT CINQUANTE DIRHAMS',
            'template_version' => 'v1',
            'metadata' => ['issued_at' => null, 'issued_by' => null],
            'stamp' => $stamp,
        ];

        return $this->pdf->generate(
            view($this->templates->invoiceView('v1'), $payload)->render(),
            ['pageNumbers' => (bool) data_get($template, 'pagination.visible', true), 'pagination' => data_get($template, 'pagination', [])],
        );
    }
}
