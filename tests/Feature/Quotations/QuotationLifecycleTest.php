<?php

namespace Tests\Feature\Quotations;

use App\Actions\Quotations\DecideQuotationAction;
use App\Actions\Quotations\IssueQuotationAction;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\User;
use App\Services\DocumentPdfService;
use App\Services\QuotationDocumentRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Support\QuotationTestCase;

class QuotationLifecycleTest extends QuotationTestCase
{
    /** @return array{User, \App\Models\Organization, \App\Models\Store, \App\Models\ProductVariant} */
    private function base(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'Micro', 'MIC-1', ['default_sale_price' => '1000.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->activate($owner, $organization, $store);

        return [$owner, $organization, $store, $variant];
    }

    public function test_a_draft_quotation_snapshots_the_customer_and_has_no_official_number(): void
    {
        [$owner, $organization, $store, $variant] = $this->base();
        $customer = $this->createCustomer($organization, 'Client Devis', [
            'company_name' => 'DEVIS SARL', 'tax_identifier' => 'ICE-D', 'billing_address' => 'Rue A',
        ]);

        $quotation = $this->createQuotation($owner, $organization, $store, ['customer_id' => $customer->id]);

        $this->assertSame(QuotationStatus::Draft, $quotation->status);
        $this->assertNull($quotation->quotation_number);
        $this->assertSame('DEVIS SARL', $quotation->customer_company);
        $this->assertSame('ICE-D', $quotation->customer_tax_identifier);
        $this->assertNotNull($quotation->seller_snapshot['legal_name'] ?? null);
        $this->assertDatabaseHas('audit_logs', ['event' => 'quotation.created', 'auditable_id' => $quotation->id]);

        // Customer identity changes later — the Devis keeps its snapshot.
        $customer->forceFill(['company_name' => 'RENAMED SARL'])->save();
        $this->assertSame('DEVIS SARL', $quotation->fresh()->customer_company);
    }

    public function test_issuing_allocates_a_dedicated_annual_devis_number(): void
    {
        [$owner, $organization, $store, $variant] = $this->base();
        $q1 = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $q1, $variant);
        $q2 = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $q2, $variant);

        $year = (int) now()->format('Y');
        $issued1 = $this->issueQuotation($owner, $q1);
        $issued2 = $this->issueQuotation($owner, $q2);

        $this->assertSame("DEV-1/{$year}", $issued1->quotation_number);
        $this->assertSame("DEV-2/{$year}", $issued2->quotation_number);
        $this->assertSame(QuotationStatus::Issued, $issued1->status);

        // The Devis sequence is independent from the Invoice sequence.
        $this->assertDatabaseHas('quotation_sequences', ['organization_id' => $organization->id, 'year' => $year, 'next_number' => 3]);
        $this->assertDatabaseMissing('invoice_sequences', ['organization_id' => $organization->id]);
    }

    public function test_concurrent_number_allocation_is_row_locked_and_refused_outside_a_transaction(): void
    {
        [$owner, $organization] = $this->base();
        $this->expectException(\LogicException::class);
        app(\App\Services\QuotationNumberGenerator::class)->next($organization, 2026);
    }

    public function test_number_allocation_inside_a_transaction_increments_the_locked_counter(): void
    {
        [$owner, $organization] = $this->base();
        $a = DB::transaction(fn () => app(\App\Services\QuotationNumberGenerator::class)->next($organization, 2026));
        $b = DB::transaction(fn () => app(\App\Services\QuotationNumberGenerator::class)->next($organization, 2026));
        $this->assertSame('DEV-1/2026', $a);
        $this->assertSame('DEV-2/2026', $b);
    }

    public function test_an_empty_draft_cannot_be_issued(): void
    {
        [$owner, $organization, $store] = $this->base();
        $quotation = $this->createQuotation($owner, $organization, $store);

        $this->expectException(ValidationException::class);
        app(IssueQuotationAction::class)->execute($owner, $quotation);
    }

    public function test_changing_settings_does_not_mutate_an_already_issued_devis(): void
    {
        [$owner, $organization, $store, $variant] = $this->base();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $issued = $this->issueQuotation($owner, $quotation);
        $frozenAccent = $issued->seller_snapshot['accent_color'];
        $frozenSeller = $issued->seller_snapshot;

        $organization->forceFill(['settings' => array_merge($organization->settings ?? [], [
            'quotation_profile' => ['accent_color' => '#123456', 'footer_text' => 'NOUVEAU PIED', 'default_validity_days' => 7],
            'document_profile' => ['legal_name' => 'AUTRE SOCIÉTÉ'],
        ])])->save();

        $this->assertEquals($frozenSeller, $issued->fresh()->seller_snapshot);
        $this->assertSame($frozenAccent, $issued->fresh()->seller_snapshot['accent_color']);
    }

    public function test_pdf_renders_from_the_devis_snapshot_and_is_reproducible(): void
    {
        [$owner, $organization, $store, $variant] = $this->base();
        $quotation = $this->createQuotation($owner, $organization, $store, ['representative_name' => 'REPR DEVIS']);
        $this->addCatalogQuotationLine($owner, $quotation, $variant, ['quantity' => '2']);
        $issued = $this->issueQuotation($owner, $quotation);

        $html = app(QuotationDocumentRenderer::class)->html($issued);
        $this->assertStringContainsString('Devis', $html);
        $this->assertStringContainsString('REPR DEVIS', $html);
        $this->assertStringContainsString($issued->quotation_number, $html);

        $pdf = app(DocumentPdfService::class)->quotation($issued);
        $this->assertStringStartsWith('%PDF-', $pdf['bytes']);

        // A draft renders the SAME real Dompdf document — BROUILLON state, no
        // official number — so "Aperçu PDF" and issued "Voir PDF" share one
        // rendering architecture.
        $draft = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $draft, $variant);
        $draftPdf = app(DocumentPdfService::class)->quotation($draft);
        $this->assertStringStartsWith('%PDF-', $draftPdf['bytes']);
        $this->assertSame('Devis-brouillon.pdf', $draftPdf['filename']);
        $this->assertStringContainsString('BROUILLON', app(QuotationDocumentRenderer::class)->html($draft));
    }

    public function test_accept_then_reject_lifecycle_records_actor_and_timestamp(): void
    {
        [$owner, $organization, $store, $variant] = $this->base();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $issued = $this->issueQuotation($owner, $quotation);

        $accepted = app(DecideQuotationAction::class)->accept($owner, $issued);
        $this->assertSame(QuotationStatus::Accepted, $accepted->status);
        $this->assertNotNull($accepted->accepted_at);
        $this->assertSame($owner->id, $accepted->accepted_by_user_id);

        $rejected = app(DecideQuotationAction::class)->reject($owner, $accepted->fresh(), 'Prix trop élevé');
        $this->assertSame(QuotationStatus::Rejected, $rejected->status);
        $this->assertSame('Prix trop élevé', $rejected->rejection_reason);
        $this->assertNull($rejected->accepted_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'quotation.rejected', 'auditable_id' => $rejected->id]);
    }

    public function test_email_and_sharing_are_issued_only(): void
    {
        Mail::fake();
        [$owner, $organization, $store, $variant] = $this->base();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);

        // Draft: email endpoint forbidden by policy, no share block.
        $this->actingAs($owner)->post(route('quotations.email', $quotation), ['email' => 'x@example.test'])->assertForbidden();
        $this->actingAs($owner)->get(route('quotations.show', $quotation))
            ->assertInertia(fn ($page) => $page->where('sharing', null));

        $this->configureOrganizationMail($organization);
        $issued = $this->issueQuotation($owner, $quotation);
        $this->actingAs($owner)->post(route('quotations.email', $issued), ['email' => 'client@example.test'])->assertRedirect();
        Mail::assertSent(\App\Mail\QuotationDocumentMail::class);

        $this->actingAs($owner)->get(route('quotations.show', $issued))
            ->assertInertia(fn ($page) => $page->where('sharing.pdfUrl', fn ($u) => str_contains((string) $u, "/quotations/{$issued->id}/shared-pdf")));
    }

    public function test_tenant_isolation_and_unauthorized_role_on_quotation_endpoints(): void
    {
        [$owner, $organization, $store, $variant] = $this->base();
        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $issued = $this->issueQuotation($owner, $quotation);

        // Foreign tenant → hidden 404.
        $outsider = User::factory()->create();
        $otherOrg = $this->createOrganization($outsider);
        $otherStore = $this->createStore($otherOrg, $outsider);
        $this->activate($outsider, $otherOrg, $otherStore);
        $this->actingAs($outsider)->get(route('quotations.show', $issued))->assertNotFound();
        $this->actingAs($outsider)->post(route('quotations.issue', $issued))->assertNotFound();

        // Member without quotation permissions → 403.
        $clerk = User::factory()->create();
        $this->addOrganizationMember($organization, $clerk, ['quotations.view'], roleName: 'Clerk');
        $this->addStoreMember($store, $clerk);
        $this->activate($clerk, $organization, $store);
        $this->actingAs($clerk)->post(route('quotations.issue', $this->createQuotation($owner, $organization, $store)))->assertForbidden();
        $this->actingAs($clerk)->post(route('quotations.conversion.store', $issued))->assertForbidden();

        $this->assertSame(0, Quotation::query()->where('organization_id', $otherOrg->id)->count());
    }
}
