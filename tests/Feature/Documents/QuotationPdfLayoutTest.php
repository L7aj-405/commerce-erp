<?php

namespace Tests\Feature\Documents;

use App\Services\QuotationDocumentRenderer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Tests\Support\QuotationTestCase;

/**
 * Regression coverage for the shared lower-page layout (totals / amount in
 * words / issuer line / stamp / footer) on the Devis template — the same
 * structure as InvoicePdfPaginationTest, but asserting the Devis-specific
 * wording is used instead of the Invoice one.
 */
class QuotationPdfLayoutTest extends QuotationTestCase
{
    /** @return array{User, \App\Models\Organization, \App\Models\Store, \App\Models\ProductVariant} */
    private function base(): array
    {
        $owner = \App\Models\User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'Micro', 'MIC-LAYOUT', ['default_sale_price' => '1000.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->activate($owner, $organization, $store);

        return [$owner, $organization, $store, $variant];
    }

    public function test_a_short_quotation_is_one_page_and_uses_devis_wording_not_invoice_wording(): void
    {
        [$owner, $organization, $store, $variant] = $this->base();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $issued = $this->issueQuotation($owner, $quotation);

        $this->assertSame(1, $this->pageCount($issued));

        $html = app(QuotationDocumentRenderer::class)->html($issued);
        $this->assertStringContainsString('Devis arrêté à la somme de :', $html);
        $this->assertStringNotContainsString('Arrêtée la présente facture', $html);
        $this->assertStringContainsString('class="words-value"', $html);
        $this->assertStringContainsString('DIRHAMS', $html);
    }

    /**
     * The flexible gap above the totals must be ADAPTIVE, but modest. A
     * too-large fake spacer strands the totals on page 2 for very short Devis.
     */
    public function test_the_flexible_gap_above_totals_is_modest_and_shrinks_as_line_count_grows(): void
    {
        [$owner, $organization, $store, $variant] = $this->base();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $issued = $this->issueQuotation($owner, $quotation);
        $shortSpacerMm = $this->itemsSpacerMm(app(QuotationDocumentRenderer::class)->html($issued));
        $this->assertGreaterThan(20.0, $shortSpacerMm);
        $this->assertLessThanOrEqual(42.0, $shortSpacerMm);

        $longerQuotation = $this->createQuotation($owner, $organization, $store);
        for ($i = 1; $i <= 12; $i++) {
            $this->addNonStockQuotationLine($owner, $longerQuotation, ['name' => "Article {$i}"]);
        }
        $longerIssued = $this->issueQuotation($owner, $longerQuotation);
        $this->assertSame(1, $this->pageCount($longerIssued), '12 real item rows must still fit on one page.');
        $longerSpacerMm = $this->itemsSpacerMm(app(QuotationDocumentRenderer::class)->html($longerIssued));
        $this->assertLessThan($shortSpacerMm, $longerSpacerMm);
        $this->assertLessThanOrEqual(5.0, $longerSpacerMm);
    }

    private function itemsSpacerMm(string $html): float
    {
        preg_match('/\.items-spacer \{ min-height: ([\d.]+)mm; \}/', $html, $matches);
        $this->assertNotEmpty($matches, 'Expected an .items-spacer min-height rule in the rendered HTML.');

        return (float) $matches[1];
    }

    public function test_amount_in_words_and_issuer_metadata_are_present_and_positioned_after_totals(): void
    {
        [$owner, $organization, $store, $variant] = $this->base();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $issued = $this->issueQuotation($owner, $quotation);

        $html = app(QuotationDocumentRenderer::class)->html($issued);
        $this->assertSame(1, substr_count($html, 'class="closing"'));
        $this->assertTrue(strpos($html, 'class="closing"') > strpos($html, 'class="items"'));
        $this->assertStringContainsString('Émise par', $html);
        $this->assertMatchesRegularExpression('/Émise par .+ le \d{2}\/\d{2}\/\d{4} à \d{2}:\d{2}/', $html);
    }

    public function test_two_or_three_line_quotation_keeps_totals_on_the_first_page_when_they_fit(): void
    {
        [$owner, $organization, $store] = $this->base();
        $quotation = $this->createQuotation($owner, $organization, $store);

        for ($i = 1; $i <= 3; $i++) {
            $this->addNonStockQuotationLine($owner, $quotation, ['name' => "Article court {$i}"]);
        }

        $issued = $this->issueQuotation($owner, $quotation);

        $this->assertSame(1, $this->pageCount($issued));
    }

    public function test_wrapped_descriptions_paginate_naturally_and_keep_a_single_closing_block(): void
    {
        [$owner, $organization, $store] = $this->base();
        $quotation = $this->createQuotation($owner, $organization, $store);

        for ($i = 1; $i <= 24; $i++) {
            $this->addNonStockQuotationLine($owner, $quotation, [
                'name' => "Article {$i} — description longue pour vérifier que Dompdf laisse les lignes enveloppées pousser naturellement les pages sans isoler les totaux.",
            ]);
        }

        $issued = $this->issueQuotation($owner, $quotation);
        $html = app(QuotationDocumentRenderer::class)->html($issued);

        $this->assertGreaterThanOrEqual(2, $this->pageCount($issued));
        $this->assertSame(1, substr_count($html, 'class="closing"'));
        $this->assertTrue(strpos($html, 'class="closing"') > strpos($html, 'class="items"'));
    }

    public function test_clean_website_display_removes_protocol_and_trailing_slash(): void
    {
        [$owner, $organization, $store, $variant] = $this->base();
        $organization->settings = array_replace_recursive($organization->settings ?? [], [
            'document_profile' => ['website' => 'https://www.avprofessional-store.ma/'],
        ]);
        $organization->save();

        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $issued = $this->issueQuotation($owner, $quotation);

        $html = app(QuotationDocumentRenderer::class)->html($issued);

        $this->assertStringContainsString('Web :</span> <span class="info-value">www.avprofessional-store.ma</span>', $html);
        $this->assertStringNotContainsString('https://www.avprofessional-store.ma/', $html);
    }

    public function test_unstamped_quotation_renders_with_no_stamp_markup(): void
    {
        [$owner, $organization, $store, $variant] = $this->base();
        Storage::fake('local');
        $this->configureOrganizationStamp($organization); // configured, never applied to this quotation
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $issued = $this->issueQuotation($owner, $quotation);

        $html = app(QuotationDocumentRenderer::class)->html($issued);
        $this->assertStringNotContainsString('data:image', $html);
    }

    public function test_stamped_quotation_renders_the_stamp_once(): void
    {
        [$owner, $organization, $store, $variant] = $this->base();
        Storage::fake('local');
        $this->configureOrganizationStamp($organization, ['rotation_deg' => -4]);
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $issued = $this->issueQuotation($owner, $quotation);
        $this->actingAs($owner)->post(route('quotations.stamp', $issued))->assertRedirect();

        $html = app(QuotationDocumentRenderer::class)->html($issued->fresh());
        $this->assertSame(1, substr_count($html, 'data:image/png;base64,'));
        $this->assertStringContainsString('rotate(-4deg)', $html);
    }

    public function test_long_quotation_paginates_and_closing_section_and_stamp_render_exactly_once(): void
    {
        [$owner, $organization, $store] = $this->base();
        Storage::fake('local');
        $this->configureOrganizationStamp($organization);
        $quotation = $this->createQuotation($owner, $organization, $store);
        for ($i = 1; $i <= 40; $i++) {
            $this->addNonStockQuotationLine($owner, $quotation, [
                'name' => "Prestation catalogue numéro {$i} — désignation de longueur moyenne",
                'unit_price' => (string) (100 + $i),
                'quantity' => (string) (($i % 3) + 1),
            ]);
        }
        $issued = $this->issueQuotation($owner, $quotation);
        $this->actingAs($owner)->post(route('quotations.stamp', $issued))->assertRedirect();
        $issued = $issued->fresh();

        $this->assertGreaterThanOrEqual(2, $this->pageCount($issued));

        $html = app(QuotationDocumentRenderer::class)->html($issued);
        $this->assertSame(1, substr_count($html, 'class="closing"'));
        $this->assertSame(1, substr_count($html, 'data:image/png;base64,'));
        $this->assertTrue(strpos($html, 'class="closing"') > strpos($html, 'class="items"'));
    }

    private function pageCount(\App\Models\Quotation $quotation): int
    {
        $html = app(QuotationDocumentRenderer::class)->html($quotation);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->setChroot([resource_path('views')]);
        $pdf = new Dompdf($options);
        $pdf->setPaper('a4', 'portrait');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        return $pdf->getCanvas()->get_page_count();
    }
}
