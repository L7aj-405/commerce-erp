<?php

namespace Tests\Feature\Documents;

use App\Contracts\PdfGenerator;
use App\Mail\InvoiceDocumentMail;
use App\Models\DocumentStampApposition;
use App\Models\User;
use App\Services\InvoiceDocumentRenderer;
use App\Services\QuotationDocumentRenderer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\QuotationTestCase;

class DocumentStampAppositionTest extends QuotationTestCase
{
    public function test_unstamped_invoice_pdf_never_shows_a_stamp(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        Storage::fake('local');
        $this->configureOrganizationStamp($organization); // configured, but never applied
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $html = app(InvoiceDocumentRenderer::class)->html($invoice);

        $this->assertStringNotContainsString('data:image', $html);
    }

    public function test_explicit_apposition_persists_and_the_real_pdf_contains_the_stamp(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        Storage::fake('local');
        $this->configureOrganizationStamp($organization, ['position_anchor' => 'bottom_left', 'display_width_mm' => 35]);
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $this->actingAs($owner)->post(route('invoices.stamp', $invoice))->assertRedirect();

        $apposition = DocumentStampApposition::query()
            ->where('stampable_type', $invoice->getMorphClass())
            ->where('stampable_id', $invoice->id)
            ->first();
        $this->assertNotNull($apposition);
        $this->assertSame('bottom_left', $apposition->position_anchor);
        $this->assertDatabaseHas('audit_logs', ['event' => 'invoice.stamped', 'auditable_id' => $invoice->id]);

        $html = app(InvoiceDocumentRenderer::class)->html($invoice->fresh());
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('position: absolute', $html);
    }

    public function test_stamped_pdf_contains_the_frozen_rotation(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        Storage::fake('local');
        $this->configureOrganizationStamp($organization, ['rotation_deg' => -4]);
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->actingAs($owner)->post(route('invoices.stamp', $invoice))->assertRedirect();

        $apposition = $invoice->fresh()->stampApposition;
        $this->assertSame('-4.00', (string) $apposition->rotation_deg);

        $html = app(InvoiceDocumentRenderer::class)->html($invoice->fresh());
        $this->assertStringContainsString('rotate(-4deg)', $html);
        $this->assertStringContainsString('transform-origin: center', $html);
    }

    /**
     * Explicit regression scenario: Stamp V1 (45mm / -4°) stamps Invoice A.
     * The organization then replaces its configuration with Stamp V2
     * (60mm / +3°). Invoice A must keep rendering at exactly 45mm / -4° — a
     * newly stamped Invoice B picks up 60mm / +3°. Same rule for a Devis.
     */
    public function test_historical_stamped_documents_are_unaffected_by_a_later_rotation_and_size_change(): void
    {
        [$owner, $organization, $store, $order] = $this->documentFixture();
        Storage::fake('local');
        $this->configureOrganizationStamp($organization, ['display_width_mm' => 45, 'rotation_deg' => -4]);
        $invoiceA = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->actingAs($owner)->post(route('invoices.stamp', $invoiceA))->assertRedirect();

        $quotation = $this->createQuotation($owner, $organization, $store);
        $variant = $this->createProduct($organization, 'Micro', 'MIC-ROT', ['default_sale_price' => '500.0000'])->variants->first();
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $issuedQuotation = $this->issueQuotation($owner, $quotation);
        $this->actingAs($owner)->post(route('quotations.stamp', $issuedQuotation))->assertRedirect();

        // The organization changes its configuration: V2 is 60mm / +3°.
        $this->configureOrganizationStamp($organization, ['display_width_mm' => 60, 'rotation_deg' => 3]);

        $invoiceApposition = $invoiceA->fresh()->stampApposition;
        $this->assertSame('45.00', (string) $invoiceApposition->display_width_mm);
        $this->assertSame('-4.00', (string) $invoiceApposition->rotation_deg);

        $quotationApposition = $issuedQuotation->fresh()->stampApposition;
        $this->assertSame('45.00', (string) $quotationApposition->display_width_mm);
        $this->assertSame('-4.00', (string) $quotationApposition->rotation_deg);

        $invoiceHtml = app(InvoiceDocumentRenderer::class)->html($invoiceA->fresh());
        $this->assertStringContainsString('rotate(-4deg)', $invoiceHtml);
        $this->assertStringNotContainsString('rotate(3deg)', $invoiceHtml);

        $quotationHtml = app(QuotationDocumentRenderer::class)->html($issuedQuotation->fresh());
        $this->assertStringContainsString('rotate(-4deg)', $quotationHtml);

        // A NEW invoice, stamped after the change, uses V2.
        $invoiceB = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->actingAs($owner)->post(route('invoices.stamp', $invoiceB))->assertRedirect();
        $invoiceBApposition = $invoiceB->fresh()->stampApposition;
        $this->assertSame('60.00', (string) $invoiceBApposition->display_width_mm);
        $this->assertSame('3.00', (string) $invoiceBApposition->rotation_deg);
    }

    public function test_stamping_requires_a_configured_organization_stamp(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $this->actingAs($owner)->post(route('invoices.stamp', $invoice))
            ->assertSessionHasErrors('stamp');

        $this->assertDatabaseCount('document_stamp_appositions', 0);
    }

    public function test_a_document_can_only_be_stamped_once(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        Storage::fake('local');
        $this->configureOrganizationStamp($organization);
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $this->actingAs($owner)->post(route('invoices.stamp', $invoice))->assertRedirect();
        $this->actingAs($owner)->post(route('invoices.stamp', $invoice))->assertSessionHasErrors('stamp');

        $this->assertSame(1, DocumentStampApposition::query()->count());
    }

    public function test_draft_invoice_cannot_be_stamped(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        Storage::fake('local');
        $this->configureOrganizationStamp($organization);
        $draft = $this->createInvoice($owner, $order);

        $this->actingAs($owner)->post(route('invoices.stamp', $draft))->assertForbidden();
    }

    public function test_stamped_invoice_keeps_its_stamp_after_the_organization_replaces_its_active_stamp(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        Storage::fake('local');
        $this->configureOrganizationStamp($organization, ['position_anchor' => 'bottom_left']);
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->actingAs($owner)->post(route('invoices.stamp', $invoice))->assertRedirect();

        $htmlBefore = app(InvoiceDocumentRenderer::class)->html($invoice->fresh());

        // September -> October: the organization replaces its active stamp.
        $this->configureOrganizationStamp($organization, ['position_anchor' => 'top_right', 'display_width_mm' => 50]);

        $htmlAfter = app(InvoiceDocumentRenderer::class)->html($invoice->fresh());

        // The already-stamped Invoice renders byte-identical stamp placement —
        // its frozen apposition, not the org's now-different active stamp.
        $this->assertSame($htmlBefore, $htmlAfter);
        $apposition = $invoice->fresh()->stampApposition;
        $this->assertSame('bottom_left', $apposition->position_anchor);

        // A NEW invoice may pick up the newly active stamp when explicitly stamped.
        $newInvoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->actingAs($owner)->post(route('invoices.stamp', $newInvoice))->assertRedirect();
        $this->assertSame('top_right', $newInvoice->fresh()->stampApposition->position_anchor);
    }

    public function test_invoice_correction_does_not_inherit_the_original_apposition(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        Storage::fake('local');
        $this->configureOrganizationStamp($organization);
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->actingAs($owner)->post(route('invoices.stamp', $invoice))->assertRedirect();

        $this->actingAs($owner)->post(route('invoices.corrections.store', $invoice), ['reason' => 'Erreur de montant'])
            ->assertRedirect();

        $correction = $invoice->fresh()->correction;
        $this->assertNotNull($correction);
        $this->assertNull($correction->stampApposition);
        // The original keeps its own apposition untouched.
        $this->assertNotNull($invoice->fresh()->stampApposition);
    }

    public function test_quotation_revision_does_not_inherit_the_previous_apposition(): void
    {
        [$owner, $organization, $store, $variant] = $this->base();
        Storage::fake('local');
        $this->configureOrganizationStamp($organization);
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $issued = $this->issueQuotation($owner, $quotation);

        $this->actingAs($owner)->post(route('quotations.stamp', $issued))->assertRedirect();

        $revision = $this->reviseQuotation($owner, $issued->fresh());

        $this->assertNull($revision->stampApposition);
        $this->assertNotNull($issued->fresh()->stampApposition);
        $this->assertDatabaseHas('audit_logs', ['event' => 'quotation.stamped', 'auditable_id' => $issued->id]);
    }

    public function test_superseded_quotation_revision_cannot_be_stamped(): void
    {
        [$owner, $organization, $store, $variant] = $this->base();
        Storage::fake('local');
        $this->configureOrganizationStamp($organization);
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $issued = $this->issueQuotation($owner, $quotation);
        $this->reviseQuotation($owner, $issued->fresh());

        $this->actingAs($owner)->post(route('quotations.stamp', $issued->fresh()))->assertForbidden();
    }

    public function test_stamp_permission_is_enforced_separately_from_email_permission(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        Storage::fake('local');
        $this->configureOrganizationStamp($organization);
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $canEmailOnly = User::factory()->create();
        $this->addOrganizationMember($organization, $canEmailOnly, ['invoices.view', 'invoices.email']);
        $this->activate($canEmailOnly, $organization, $invoice->store);

        $this->actingAs($canEmailOnly)->post(route('invoices.stamp', $invoice))->assertForbidden();
        $this->assertDatabaseCount('document_stamp_appositions', 0);
    }

    /**
     * The document email flow and the direct download/preview flow both go
     * through the exact same DocumentPdfService -> *DocumentRenderer -> html()
     * pipeline (there is no separate "email" renderer) — so proving the
     * stamp appears in the HTML handed to the PDF generator on BOTH paths
     * proves the email attachment uses the identical stamped representation.
     */
    public function test_email_attachment_uses_the_same_stamped_representation_as_the_downloaded_pdf(): void
    {
        Mail::fake();
        [$owner, $organization, , $order] = $this->documentFixture();
        Storage::fake('local');
        $this->configureOrganizationMail($organization);
        $this->configureOrganizationStamp($organization);
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->actingAs($owner)->post(route('invoices.stamp', $invoice))->assertRedirect();

        $captured = new class implements PdfGenerator
        {
            /** @var list<string> */
            public array $htmls = [];

            public function generate(string $html, array $options = []): string
            {
                $this->htmls[] = $html;

                return 'FAKE-PDF-BYTES';
            }
        };
        $this->app->instance(PdfGenerator::class, $captured);

        $this->actingAs($owner)->get(route('invoices.download', $invoice))->assertOk();
        $this->actingAs($owner)->post(route('invoices.email', $invoice), ['email' => 'accounts@example.test'])->assertRedirect();

        Mail::assertSent(InvoiceDocumentMail::class);
        $this->assertCount(2, $captured->htmls);
        foreach ($captured->htmls as $html) {
            $this->assertStringContainsString('data:image/png;base64,', $html);
        }
    }
}
