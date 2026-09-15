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

    public function test_amount_in_words_and_issuer_metadata_are_present_and_positioned_after_totals(): void
    {
        [$owner, $organization, $store, $variant] = $this->base();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $issued = $this->issueQuotation($owner, $quotation);

        $html = app(QuotationDocumentRenderer::class)->html($issued);
        $this->assertSame(1, substr_count($html, 'class="totals-row"'));
        $this->assertSame(1, substr_count($html, 'class="lower-cluster"'));
        $this->assertTrue(strpos($html, 'class="lower-cluster"') > strpos($html, 'class="totals-row"'));
        $this->assertStringContainsString('Émise par', $html);
        $this->assertMatchesRegularExpression('/Émise par .+ le \d{2}\/\d{2}\/\d{4} à \d{2}:\d{2}/', $html);
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

    public function test_long_quotation_paginates_and_lower_cluster_and_stamp_render_exactly_once(): void
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
        $this->assertSame(1, substr_count($html, 'class="lower-cluster"'));
        $this->assertSame(1, substr_count($html, 'data:image/png;base64,'));
        $this->assertTrue(strpos($html, 'class="lower-cluster"') > strpos($html, 'class="items"'));
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
