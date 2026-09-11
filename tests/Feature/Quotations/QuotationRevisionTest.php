<?php

namespace Tests\Feature\Quotations;

use App\Actions\Quotations\ConvertQuotationToSalesOrderAction;
use App\Actions\Quotations\RemoveQuotationLineAction;
use App\Actions\Quotations\SaveQuotationLineAction;
use App\Actions\Quotations\StartQuotationRevisionAction;
use App\Actions\Quotations\UpdateQuotationAction;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\User;
use App\Services\DocumentPdfService;
use App\Services\QuotationDocumentRenderer;
use Illuminate\Validation\ValidationException;
use Tests\Support\QuotationTestCase;

class QuotationRevisionTest extends QuotationTestCase
{
    /** @return array{User, \App\Models\Organization, \App\Models\Store, \App\Models\ProductVariant, \App\Models\Warehouse} */
    private function fixture(string $price = '1000.0000', string $stock = '50.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'Micro', 'MIC-1', ['default_sale_price' => $price, 'tax_rate_id' => $tax->id])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, $stock);

        return [$owner, $organization, $store, $variant, $warehouse];
    }

    private function issuedDevis(User $owner, $organization, $store, $variant, array $lineData = ['quantity' => '2']): Quotation
    {
        $quotation = $this->createQuotation($owner, $organization, $store, [
            'customer_name' => 'Client Révision', 'customer_company' => 'REV SARL', 'notes' => 'Note initiale',
        ]);
        $this->addCatalogQuotationLine($owner, $quotation, $variant, $lineData);

        return $this->issueQuotation($owner, $quotation);
    }

    // ---- PDF preview / actions ---------------------------------------------

    public function test_draft_show_exposes_a_real_pdf_preview_url_and_no_share_block(): void
    {
        [$owner, $organization, $store, $variant] = $this->fixture();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);

        $this->actingAs($owner)->get(route('quotations.show', $quotation))->assertInertia(
            fn ($page) => $page
                ->where('previewUrl', route('quotations.pdf', $quotation))
                ->where('sharing', null)
                ->where('history', [])
                ->where('can.issue', true)
                ->where('can.revise', false),
        );
    }

    public function test_issued_show_exposes_view_download_share_and_revise_but_current_only(): void
    {
        [$owner, $organization, $store, $variant] = $this->fixture();
        $issued = $this->issuedDevis($owner, $organization, $store, $variant);

        $this->actingAs($owner)->get(route('quotations.show', $issued))->assertInertia(
            fn ($page) => $page
                ->where('previewUrl', route('quotations.pdf', $issued))
                ->where('isCurrentVersion', true)
                ->where('can.revise', true)
                ->where('sharing.pdfUrl', fn ($u) => str_contains((string) $u, "/quotations/{$issued->id}/shared-pdf")),
        );
    }

    public function test_draft_devis_renders_a_real_brouillon_pdf_from_the_same_pipeline(): void
    {
        [$owner, $organization, $store, $variant] = $this->fixture();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);

        $document = app(DocumentPdfService::class)->quotation($quotation);
        $this->assertStringStartsWith('%PDF-', $document['bytes']);
        $this->assertSame('Devis-brouillon.pdf', $document['filename']);
        $this->assertStringContainsString('BROUILLON', app(QuotationDocumentRenderer::class)->html($quotation));
    }

    // ---- Revision creation -------------------------------------------------

    public function test_revising_an_issued_devis_copies_snapshots_without_touching_the_original(): void
    {
        [$owner, $organization, $store, $variant] = $this->fixture('1000.0000');
        $issued = $this->issuedDevis($owner, $organization, $store, $variant);
        $originalLineIds = $issued->lines->pluck('id')->all();
        $frozenSeller = $issued->seller_snapshot;

        $revision = app(StartQuotationRevisionAction::class)->execute($owner, $issued, 'Le client demande une remise.');

        // New editable draft in the same chain.
        $this->assertSame(QuotationStatus::Draft, $revision->status);
        $this->assertNull($revision->quotation_number);
        $this->assertSame(1, $revision->revision_number);
        $this->assertSame($issued->id, $revision->root_quotation_id);
        $this->assertSame($issued->id, $revision->revised_from_quotation_id);
        $this->assertSame('Le client demande une remise.', $revision->revision_reason);

        // Copied — not re-fetched — customer + seller + line snapshots.
        $this->assertSame('REV SARL', $revision->customer_company);
        $this->assertEquals($frozenSeller, $revision->seller_snapshot);
        $this->assertSame(1, $revision->lines->count());
        $this->assertNotContains($revision->lines->first()->id, $originalLineIds);
        $this->assertSame($issued->lines->first()->unit_price_excl_tax, $revision->lines->first()->unit_price_excl_tax);
        $this->assertSame($issued->total_incl_tax, $revision->total_incl_tax);

        // Original stays exactly as issued.
        $issued->refresh();
        $this->assertSame(QuotationStatus::Issued, $issued->status);
        $this->assertNotNull($issued->quotation_number);
        $this->assertSame($originalLineIds, $issued->lines()->pluck('id')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'quotation.revision_started', 'auditable_id' => $revision->id]);
    }

    public function test_a_later_catalogue_price_change_does_not_move_the_copied_revision_basis(): void
    {
        [$owner, $organization, $store, $variant] = $this->fixture('1000.0000');
        $issued = $this->issuedDevis($owner, $organization, $store, $variant);
        $revision = $this->reviseQuotation($owner, $issued);

        $variant->forceFill(['default_sale_price' => '4242.0000', 'unit_price_ht' => '4242.0000'])->save();

        $this->assertSame('1000.0000', $revision->fresh()->lines->first()->unit_price_excl_tax);
    }

    public function test_revision_reason_is_required(): void
    {
        [$owner, $organization, $store, $variant] = $this->fixture();
        $issued = $this->issuedDevis($owner, $organization, $store, $variant);

        $this->expectException(ValidationException::class);
        app(StartQuotationRevisionAction::class)->execute($owner, $issued, '   ');
    }

    public function test_only_one_open_revision_per_chain_double_submit_is_ignored(): void
    {
        [$owner, $organization, $store, $variant] = $this->fixture();
        $issued = $this->issuedDevis($owner, $organization, $store, $variant);

        $this->actingAs($owner)->post(route('quotations.revise', $issued), ['reason' => 'Une fois'])->assertRedirect();
        $this->actingAs($owner)->post(route('quotations.revise', $issued), ['reason' => 'Deux fois'])->assertSessionHasErrors();

        $this->assertSame(1, Quotation::query()->where('root_quotation_id', $issued->id)->count());
    }

    // ---- Editing the revision draft --------------------------------------

    public function test_revision_draft_supports_full_line_and_header_edits(): void
    {
        [$owner, $organization, $store, $variant] = $this->fixture('1000.0000');
        $other = $this->createProduct($organization, 'Casque', 'CAS-1', ['default_sale_price' => '300.0000', 'tax_rate_id' => $this->createTaxRate($organization, 'TVA 7', '7.0000')->id])->variants->first();
        $issued = $this->issuedDevis($owner, $organization, $store, $variant);
        $revision = $this->reviseQuotation($owner, $issued);
        $line = $revision->lines->first();

        // change quantity + price + discount on the copied line
        app(SaveQuotationLineAction::class)->execute($owner, $revision, [
            'line_type' => 'catalog', 'product_variant_id' => $variant->id,
            'quantity' => '5', 'unit_price' => '900', 'price_input_mode' => 'ht',
            'discount_type' => 'percentage', 'discount_value' => '10',
        ], $line);

        // add a line, then a second one and remove it
        app(SaveQuotationLineAction::class)->execute($owner, $revision, [
            'line_type' => 'catalog', 'product_variant_id' => $other->id,
            'quantity' => '2', 'discount_type' => 'none', 'discount_value' => '0',
        ]);
        $throwaway = app(SaveQuotationLineAction::class)->execute($owner, $revision->fresh(), [
            'line_type' => 'non_stock', 'name' => 'Frais', 'price_input_mode' => 'ht',
            'unit_price' => '50', 'quantity' => '1', 'discount_type' => 'none', 'discount_value' => '0',
        ]);
        app(RemoveQuotationLineAction::class)->execute($owner, $revision->fresh(), $throwaway);

        // header: client, validity, notes
        app(UpdateQuotationAction::class)->execute($owner, $revision->fresh(), [
            'customer_company' => 'REV SARL RÉVISÉ', 'valid_until' => now()->addDays(10)->toDateString(), 'notes' => 'Note révisée',
        ]);

        $revision = $revision->fresh(['lines']);
        $this->assertSame(2, $revision->lines->count());
        $updated = $revision->lines->firstWhere('product_variant_id', $variant->id);
        $this->assertSame('5.0000', $updated->quantity);
        $this->assertSame('900.0000', $updated->unit_price_excl_tax);
        $this->assertTrue((float) $updated->discount_amount > 0);
        $this->assertSame('REV SARL RÉVISÉ', $revision->customer_company);
        $this->assertSame('Note révisée', $revision->notes);

        // the issued original is still untouched
        $this->assertSame(1, $issued->fresh()->lines()->count());
    }

    // ---- Issuing the revision -> historical chain -----------------------

    public function test_issuing_a_revision_numbers_it_R1_supersedes_the_predecessor_and_consumes_no_sequence(): void
    {
        [$owner, $organization, $store, $variant] = $this->fixture();
        $issued = $this->issuedDevis($owner, $organization, $store, $variant);
        $year = (int) now()->format('Y');
        $baseNumber = $issued->quotation_number;

        $revision = $this->reviseQuotation($owner, $issued);
        $issuedRevision = $this->issueQuotation($owner, $revision);

        $this->assertSame("{$baseNumber}-R1", $issuedRevision->quotation_number);
        $this->assertSame(QuotationStatus::Issued, $issuedRevision->status);
        $this->assertSame(QuotationStatus::Superseded, $issued->fresh()->status);

        // No fresh annual number was burned: the original took DEV-1, counter is at 2.
        $this->assertDatabaseHas('quotation_sequences', ['organization_id' => $organization->id, 'year' => $year, 'next_number' => 2]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'quotation.revision_issued', 'auditable_id' => $issuedRevision->id]);

        // A second revision chains to -R2.
        $revision2 = $this->reviseQuotation($owner, $issuedRevision);
        $issuedRevision2 = $this->issueQuotation($owner, $revision2);
        $this->assertSame("{$baseNumber}-R2", $issuedRevision2->quotation_number);
        $this->assertSame(QuotationStatus::Superseded, $issuedRevision->fresh()->status);
    }

    public function test_history_and_current_flag_are_reported_on_show(): void
    {
        [$owner, $organization, $store, $variant] = $this->fixture();
        $issued = $this->issuedDevis($owner, $organization, $store, $variant);
        $issuedRevision = $this->issueQuotation($owner, $this->reviseQuotation($owner, $issued));

        // Superseded original: history present, not current, no share block.
        $this->actingAs($owner)->get(route('quotations.show', $issued))->assertInertia(
            fn ($page) => $page
                ->where('isCurrentVersion', false)
                ->where('sharing', null)
                ->where('can.revise', false)
                ->where('can.convert', false)
                ->where('history', fn ($h) => count($h) === 2 && $h[0]['label'] === 'Version initiale'),
        );

        // Current revision: current, shareable, revisable.
        $this->actingAs($owner)->get(route('quotations.show', $issuedRevision))->assertInertia(
            fn ($page) => $page
                ->where('isCurrentVersion', true)
                ->where('can.revise', true)
                ->where('sharing.pdfUrl', fn ($u) => str_contains((string) $u, "/quotations/{$issuedRevision->id}/shared-pdf"))
                ->where('history', fn ($h) => collect($h)->firstWhere('id', $issuedRevision->id)['is_current'] === true),
        );
    }

    // ---- Conversion safeguards ------------------------------------------

    public function test_a_superseded_revision_cannot_be_converted_but_the_current_one_can(): void
    {
        [$owner, $organization, $store, $variant, $warehouse] = $this->fixture();
        $issued = $this->issuedDevis($owner, $organization, $store, $variant);
        $issuedRevision = $this->issueQuotation($owner, $this->reviseQuotation($owner, $issued));

        try {
            app(ConvertQuotationToSalesOrderAction::class)->execute($owner, $issued->fresh(), ['warehouse_id' => $warehouse->id]);
            $this->fail('A superseded revision must not convert.');
        } catch (ValidationException) {
            // expected
        }

        $result = app(ConvertQuotationToSalesOrderAction::class)->execute($owner, $issuedRevision->fresh(), ['warehouse_id' => $warehouse->id]);
        $this->assertSame(QuotationStatus::Converted, $issuedRevision->fresh()->status);
        $this->assertNotNull($result['order']->getKey());
    }

    public function test_a_superseded_revision_cannot_be_revised_again(): void
    {
        [$owner, $organization, $store, $variant] = $this->fixture();
        $issued = $this->issuedDevis($owner, $organization, $store, $variant);
        $this->issueQuotation($owner, $this->reviseQuotation($owner, $issued));

        $this->expectException(ValidationException::class);
        app(StartQuotationRevisionAction::class)->execute($owner, $issued->fresh(), 'Trop tard');
    }

    // ---- Authorization -------------------------------------------------

    public function test_foreign_tenant_and_unauthorized_member_cannot_revise(): void
    {
        [$owner, $organization, $store, $variant] = $this->fixture();
        $issued = $this->issuedDevis($owner, $organization, $store, $variant);

        $outsider = User::factory()->create();
        $otherOrg = $this->createOrganization($outsider);
        $otherStore = $this->createStore($otherOrg, $outsider);
        $this->activate($outsider, $otherOrg, $otherStore);
        $this->actingAs($outsider)->post(route('quotations.revise', $issued), ['reason' => 'x'])->assertNotFound();

        $clerk = User::factory()->create();
        $this->addOrganizationMember($organization, $clerk, ['quotations.view'], roleName: 'Clerk');
        $this->addStoreMember($store, $clerk);
        $this->activate($clerk, $organization, $store);
        $this->actingAs($clerk)->post(route('quotations.revise', $issued), ['reason' => 'x'])->assertForbidden();

        $this->assertSame(0, Quotation::query()->where('root_quotation_id', $issued->id)->count());
    }
}
