<?php

namespace App\Services;

use App\Contracts\PdfGenerator;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationDocumentStamp;
use App\Models\Quotation;

/**
 * Renders "Aperçu sur document" for the stamp settings page: the REAL Invoice
 * (preferably) or Devis PDF pipeline — same Blade template, same Dompdf
 * generator as a real download — with the stamp's position/size/rotation
 * overridden by the (possibly not-yet-saved) values currently being edited,
 * so the user sees exactly what will apply before relying on it.
 *
 * Never mutates or persists anything: no DocumentStampApposition is created,
 * only a one-off render. Uses the organization's own most recently issued
 * Invoice/Devis as the representative document (already authorized — it
 * belongs to this organization); if none exists yet (a brand new
 * organization configuring its stamp before issuing anything), falls back to
 * a small built-in sample payload rendered through the exact same
 * `documents.invoice.v1` template and seller identity.
 */
class OrganizationDocumentStampPreviewService
{
    public function __construct(
        private readonly PdfGenerator $pdf,
        private readonly DocumentTemplateRegistry $templates,
        private readonly DocumentSellerProfile $sellerProfile,
        private readonly DocumentValueFormatter $format,
        private readonly InvoiceDocumentRenderer $invoiceRenderer,
        private readonly QuotationDocumentRenderer $quotationRenderer,
        private readonly DocumentStampRenderer $stamps,
    ) {}

    /** @return array{bytes:string, filename:string, mime:string} */
    public function render(
        Organization $organization,
        OrganizationDocumentStamp $stamp,
        ?string $anchor,
        ?float $offsetXMm,
        ?float $offsetYMm,
        ?float $displayWidthMm,
        ?float $rotationDeg = null,
    ): array {
        $stampOverride = $this->stamps->preview($stamp, $anchor, $offsetXMm, $offsetYMm, $displayWidthMm, $rotationDeg);

        $invoice = Invoice::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', InvoiceStatus::Issued->value)
            ->latest('issued_at')->latest('id')
            ->first();
        if ($invoice) {
            $payload = $this->invoiceRenderer->payload($invoice);
            $payload['stamp'] = $stampOverride;

            return $this->build($this->templates->invoiceView($invoice->template_version), $payload);
        }

        $quotation = Quotation::query()
            ->where('organization_id', $organization->getKey())
            ->whereNotNull('issued_at')
            ->latest('issued_at')->latest('id')
            ->first();
        if ($quotation) {
            $payload = $this->quotationRenderer->payload($quotation);
            $payload['stamp'] = $stampOverride;

            return $this->build($this->templates->quotationView($quotation->template_version), $payload);
        }

        $payload = $this->samplePayload($organization);
        $payload['stamp'] = $stampOverride;

        return $this->build($this->templates->invoiceView('v1'), $payload);
    }

    /** @param array<string, mixed> $payload */
    private function build(string $view, array $payload): array
    {
        return [
            'bytes' => $this->pdf->generate(view($view, $payload)->render()),
            'filename' => 'Apercu-cachet.pdf',
            'mime' => 'application/pdf',
        ];
    }

    /** @return array<string, mixed> */
    private function samplePayload(Organization $organization): array
    {
        $seller = $this->sellerProfile->organizationIdentity($organization) + [
            'fax' => null, 'website' => null, 'patente_number' => null,
            'bank' => ['name' => null, 'rib' => null],
            'footer_text' => null, 'additional_identifiers' => [],
        ];

        return [
            'kind' => 'invoice',
            'title' => __('documents.invoice', locale: config('documents.locale')),
            'watermark' => __('documents.draft', locale: config('documents.locale')).' — '.'APERÇU',
            'has_discount' => false,
            'document' => [
                'number' => 'FA-APERÇU',
                'date' => $this->format->date(now()),
                'order_number' => null,
                'currency' => 'MAD',
                'representative' => null,
                'payment_method' => null,
                'notes' => null,
            ],
            'seller' => $seller,
            'buyer' => [
                'name' => 'Client exemple',
                'company' => 'Client exemple SARL',
                'email' => null,
                'phone' => null,
                'tax_identifier' => null,
                'address' => null,
            ],
            'lines' => [[
                'reference' => 'REF-001',
                'description' => 'Article ou prestation exemple',
                'variant' => null,
                'sku' => null,
                'presentation' => 'Unité',
                'quantity' => '1,00',
                'unit_price_ht' => '1 000,00',
                'line_total_ht' => '1 000,00',
                'discount' => '0,00',
                'has_discount' => false,
                'tax_rate' => '20,00%',
                'total' => '1 200,00',
            ]],
            'tax_lines' => [[
                'label' => 'TVA', 'rate' => '20,00%', 'base' => '1 000,00', 'amount' => '200,00',
            ]],
            'totals' => [
                'subtotal' => '1 000,00', 'discount' => '0,00', 'net' => '1 000,00',
                'tax' => '200,00', 'total' => '1 200,00',
            ],
            'amount_in_words' => 'MILLE DEUX CENTS DIRHAMS',
            'template_version' => 'v1',
            'metadata' => ['issued_at' => null, 'issued_by' => null],
        ];
    }
}
