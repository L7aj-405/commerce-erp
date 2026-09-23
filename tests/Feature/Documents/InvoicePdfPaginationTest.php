<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\StartSalesOrderCorrectionAction;
use App\Models\Invoice;
use App\Models\User;
use App\Services\DocumentPdfService;
use App\Services\InvoiceDocumentRenderer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DocumentTestCase;

class InvoicePdfPaginationTest extends DocumentTestCase
{
    public function test_a_short_invoice_is_one_page_and_a_long_invoice_paginates(): void
    {
        [$owner, , , $shortOrder] = $this->documentFixture(); // 1 line
        $shortInvoice = $this->issueInvoice($owner, $this->createInvoice($owner, $shortOrder));
        $this->assertSame(1, $this->pageCount($shortInvoice));

        $longInvoice = $this->issueInvoice($owner, $this->createInvoice($owner, $this->orderWithLines($owner, 40)));
        $this->assertGreaterThanOrEqual(2, $this->pageCount($longInvoice));

        // Source markup: a single <thead> that Dompdf repeats via table-header-group,
        // and the closing section (totals + amount in words) rendered
        // exactly once, as one compact block.
        $html = app(InvoiceDocumentRenderer::class)->html($longInvoice);
        $this->assertStringContainsString('display: table-header-group', $html);
        $this->assertSame(1, substr_count($html, 'class="items"'));
        $this->assertSame(1, substr_count($html, 'class="closing"'));
        $this->assertSame(1, substr_count($html, 'Arrêtée la présente facture'));
        // The closing section follows the article table; any decorative
        // ruled rows live inside that same table, never as invoice data.
        $this->assertTrue(strpos($html, 'class="closing"') > strpos($html, 'class="items"'));
    }

    /**
     * Regression pin for the professional register-style layout: short
     * invoices should fill the item area with empty ruled rows, while real
     * item content must consume that presentation-only fill as the line count
     * grows.
     */
    public function test_empty_ruled_rows_fill_short_invoices_and_shrink_as_line_count_grows(): void
    {
        [$owner, , , $shortOrder] = $this->documentFixture(); // 1 line
        $shortInvoice = $this->issueInvoice($owner, $this->createInvoice($owner, $shortOrder));
        $shortEmptyRows = $this->emptyRuledRowCount(app(InvoiceDocumentRenderer::class)->html($shortInvoice));
        $this->assertGreaterThanOrEqual(8, $shortEmptyRows);

        $twelveLineInvoice = $this->issueInvoice($owner, $this->createInvoice($owner, $this->orderWithLines($owner, 12)));
        $this->assertSame(1, $this->pageCount($twelveLineInvoice), '12 real item rows must still fit on one page.');
        $longerEmptyRows = $this->emptyRuledRowCount(app(InvoiceDocumentRenderer::class)->html($twelveLineInvoice));
        $this->assertLessThan($shortEmptyRows, $longerEmptyRows);
        $this->assertLessThanOrEqual(2, $longerEmptyRows);
    }

    private function emptyRuledRowCount(string $html): int
    {
        return substr_count($html, 'class="empty-ruled-row"');
    }

    public function test_issued_invoice_uses_the_snapshot_logo_as_a_faint_watermark(): void
    {
        [$owner, $organization, $store, $order] = $this->documentFixture();
        // Configure a logo, then issue — the snapshot freezes it.
        $organization->settings = array_replace_recursive($organization->settings ?? [], [
            'document_profile' => ['legal_name' => 'AV PRO SARL', 'logo_path' => 'document-profiles/test-logo.png'],
        ]);
        $organization->save();
        Storage::fake('public');
        Storage::disk('public')->put(
            'document-profiles/test-logo.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
        );

        $withLogo = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->assertNotEmpty($withLogo->seller_snapshot['logo'] ?? null);
        $body = $this->body(app(InvoiceDocumentRenderer::class)->html($withLogo));
        $this->assertStringContainsString('class="watermark-logo"', $body);
        $this->assertStringContainsString('opacity: 0.05', app(InvoiceDocumentRenderer::class)->html($withLogo));

        // No logo configured -> no watermark div at all.
        [$owner2, , , $order2] = $this->documentFixture();
        $plain = $this->issueInvoice($owner2, $this->createInvoice($owner2, $order2));
        $body2 = $this->body(app(InvoiceDocumentRenderer::class)->html($plain));
        $this->assertStringNotContainsString('class="watermark-logo"', $body2);
        $this->assertStringNotContainsString('class="watermark-text"', $body2);
    }

    public function test_draft_and_superseded_use_a_text_state_watermark(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $draft = $this->createInvoice($owner, $order);
        $this->assertStringContainsString('class="watermark-text"', $this->body(app(InvoiceDocumentRenderer::class)->html($draft)));

        $issued = $this->issueInvoice($owner, $draft->fresh());
        app(StartSalesOrderCorrectionAction::class)->execute($owner, $order->fresh(), 'Motif');
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh())->fresh();
        $replacement = app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order);
        app(IssueInvoiceAction::class)->execute($owner, $replacement);

        $supersededHtml = app(InvoiceDocumentRenderer::class)->html($issued->fresh());
        $this->assertStringContainsString('REMPLACÉE', $supersededHtml);
        $this->assertStringContainsString('class="watermark-text"', $this->body($supersededHtml));
    }

    private function body(string $html): string
    {
        return substr($html, (int) strpos($html, '<body>'));
    }

    public function test_issued_multipage_pdf_renders_and_uses_a_sanitised_filename(): void
    {
        [$owner] = $this->documentFixture();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $this->orderWithLines($owner, 40)));

        $document = app(DocumentPdfService::class)->invoice($invoice->fresh());

        $this->assertStringStartsWith('%PDF-', $document['bytes']);
        $this->assertSame('Facture-'.str_replace('/', '-', $invoice->invoice_number).'.pdf', $document['filename']);
        $this->assertStringNotContainsString('/', substr($document['filename'], strlen('Facture-'), -4));
        $this->assertGreaterThanOrEqual(2, $this->pageCount($invoice->fresh()));
    }

    public function test_no_discount_invoice_omits_the_remise_column_across_pages(): void
    {
        [$owner] = $this->documentFixture();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $this->orderWithLines($owner, 40)));

        $html = app(InvoiceDocumentRenderer::class)->html($invoice);
        $thead = substr($html, strpos($html, '<table class="items">'), 900);
        $this->assertStringNotContainsString('Remise', $thead);
        $this->assertGreaterThanOrEqual(2, $this->pageCount($invoice));
    }

    /**
     * Invoice stamps use `position: fixed`, the same Dompdf mechanism as the
     * running header/footer, so the immutable apposition repeats on every
     * rendered page without creating extra apposition rows.
     */
    public function test_stamp_is_configured_to_repeat_on_every_page_of_a_multipage_invoice(): void
    {
        [$owner, $organization, $store] = $this->documentFixture();
        Storage::fake('local');
        $this->configureOrganizationStamp($organization);
        $order = $this->createDraftOrder($owner, $organization, $store);
        for ($i = 1; $i <= 40; $i++) {
            $this->addCustomLine($owner, $order, [
                'description' => "Prestation catalogue numéro {$i} — désignation de longueur moyenne",
                'reference' => 'REF-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'unit_label' => 'Unité', 'quantity' => (string) (($i % 3) + 1), 'unit_price_excl_tax' => (string) (100 + $i),
            ]);
        }
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->actingAs($owner)->post(route('invoices.stamp', $invoice))->assertRedirect();

        $invoice = $invoice->fresh();
        $this->assertGreaterThanOrEqual(2, $this->pageCount($invoice));

        $html = app(InvoiceDocumentRenderer::class)->html($invoice);
        $this->assertSame(1, substr_count($html, 'data:image/png;base64,'));
        $this->assertSame(1, substr_count($html, 'class="document-stamp-apposition document-stamp-repeat"'));
        $this->assertSame(1, substr_count($html, 'class="document-stamp-image"'));
        $this->assertStringContainsString('position: fixed', $html);
    }

    private function orderWithLines(User $owner, int $count)
    {
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $order = $this->createDraftOrder($owner, $organization, $store);
        for ($i = 1; $i <= $count; $i++) {
            $this->addCustomLine($owner, $order, [
                'description' => "Prestation catalogue numéro {$i} — désignation de longueur moyenne",
                'reference' => 'REF-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'unit_label' => 'Unité',
                'quantity' => (string) (($i % 3) + 1),
                'unit_price_excl_tax' => (string) (100 + $i),
            ]);
        }

        return app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
    }

    private function pageCount(Invoice $invoice): int
    {
        $html = app(InvoiceDocumentRenderer::class)->html($invoice);
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
