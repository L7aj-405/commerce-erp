<?php

namespace Tests\Feature\Quotations;

use App\Actions\Quotations\DecideQuotationAction;
use App\Actions\Quotations\IssueQuotationAction;
use App\Enums\QuotationStatus;
use App\Mail\QuotationDocumentMail;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use App\Services\DocumentPdfService;
use App\Services\QuotationDocumentRenderer;
use App\Services\QuotationNumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Support\QuotationTestCase;

class QuotationLifecycleTest extends QuotationTestCase
{
    /** @return array{User, Organization, Store, ProductVariant} */
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
        [, $organization] = $this->base();
        $defaultConnection = DB::getDefaultConnection();
        $guardConnection = 'quotation_sequence_guard_sqlite';
        config(["database.connections.{$guardConnection}" => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        try {
            // RefreshDatabase already owns a transaction on the normal test
            // connection. Use an isolated connection at transaction level 0
            // to exercise the generator's real outside-transaction guard.
            DB::setDefaultConnection($guardConnection);
            $this->expectException(\LogicException::class);
            app(QuotationNumberGenerator::class)->next($organization, 2026);
        } finally {
            DB::setDefaultConnection($defaultConnection);
            DB::purge($guardConnection);
        }
    }

    public function test_number_allocation_inside_a_transaction_increments_the_locked_counter(): void
    {
        [$owner, $organization] = $this->base();
        $a = DB::transaction(fn () => app(QuotationNumberGenerator::class)->next($organization, 2026));
        $b = DB::transaction(fn () => app(QuotationNumberGenerator::class)->next($organization, 2026));
        $this->assertSame('DEV-1/2026', $a);
        $this->assertSame('DEV-2/2026', $b);
    }

    public function test_settings_can_start_devis_numbering_at_a_configured_annual_number_without_draft_consumption(): void
    {
        [$owner, $organization, $store, $variant] = $this->base();

        $this->actingAs($owner)->put(route('quotation-settings.update'), [
            'default_validity_days' => 30,
            'quotation_numbering_year' => 2026,
            'quotation_next_number' => 21,
        ])->assertRedirect();

        $quotation = $this->createQuotation($owner, $organization, $store, ['quotation_date' => '2026-09-21']);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);

        $this->assertNull($quotation->quotation_number);
        $this->assertSame(21, (int) DB::table('quotation_sequences')
            ->where('organization_id', $organization->id)
            ->where('year', 2026)
            ->value('next_number'));

        $issued = $this->issueQuotation($owner, $quotation);

        $this->assertSame('DEV-21/2026', $issued->quotation_number);
        $this->assertSame(22, (int) DB::table('quotation_sequences')
            ->where('organization_id', $organization->id)
            ->where('year', 2026)
            ->value('next_number'));
    }

    public function test_devis_next_number_setting_cannot_move_behind_already_allocated_numbers(): void
    {
        [$owner, $organization, $store, $variant] = $this->base();
        app(QuotationNumberGenerator::class)->configureNextNumber($organization, 2026, 21);
        $quotation = $this->createQuotation($owner, $organization, $store, ['quotation_date' => '2026-09-21']);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $this->issueQuotation($owner, $quotation);

        $this->actingAs($owner)->put(route('quotation-settings.update'), [
            'default_validity_days' => 30,
            'quotation_numbering_year' => 2026,
            'quotation_next_number' => 21,
        ])->assertSessionHasErrors('quotation_next_number');

        $this->actingAs($owner)->put(route('quotation-settings.update'), [
            'default_validity_days' => 30,
            'quotation_numbering_year' => 2026,
            'quotation_next_number' => 20,
        ])->assertSessionHasErrors('quotation_next_number');

        $this->assertSame(22, (int) DB::table('quotation_sequences')
            ->where('organization_id', $organization->id)
            ->where('year', 2026)
            ->value('next_number'));
    }

    public function test_devis_configured_start_is_isolated_by_organization_and_calendar_year(): void
    {
        [$firstOwner, $firstOrganization, $firstStore, $firstVariant] = $this->base();
        [$secondOwner, $secondOrganization, $secondStore, $secondVariant] = $this->base();

        $generator = app(QuotationNumberGenerator::class);
        $generator->configureNextNumber($firstOrganization, 2026, 21);
        $generator->configureNextNumber($firstOrganization, 2027, 145);
        $generator->configureNextNumber($secondOrganization, 2026, 300);

        $first2026 = $this->createQuotation($firstOwner, $firstOrganization, $firstStore, ['quotation_date' => '2026-09-21']);
        $this->addCatalogQuotationLine($firstOwner, $first2026, $firstVariant);
        $first2027 = $this->createQuotation($firstOwner, $firstOrganization, $firstStore, ['quotation_date' => '2027-01-10']);
        $this->addCatalogQuotationLine($firstOwner, $first2027, $firstVariant);
        $second2026 = $this->createQuotation($secondOwner, $secondOrganization, $secondStore, ['quotation_date' => '2026-09-21']);
        $this->addCatalogQuotationLine($secondOwner, $second2026, $secondVariant);

        $this->assertSame('DEV-21/2026', $this->issueQuotation($firstOwner, $first2026)->quotation_number);
        $this->assertSame('DEV-145/2027', $this->issueQuotation($firstOwner, $first2027)->quotation_number);
        $this->assertSame('DEV-300/2026', $this->issueQuotation($secondOwner, $second2026)->quotation_number);
    }

    public function test_devis_revisions_reuse_root_number_without_consuming_the_next_sequence(): void
    {
        [$owner, $organization, $store, $variant] = $this->base();
        app(QuotationNumberGenerator::class)->configureNextNumber($organization, 2026, 21);

        $quotation = $this->createQuotation($owner, $organization, $store, ['quotation_date' => '2026-09-21']);
        $this->addCatalogQuotationLine($owner, $quotation, $variant);
        $original = $this->issueQuotation($owner, $quotation);

        $this->assertSame('DEV-21/2026', $original->quotation_number);
        $this->assertSame(22, (int) DB::table('quotation_sequences')
            ->where('organization_id', $organization->id)
            ->where('year', 2026)
            ->value('next_number'));

        $revision = $this->reviseQuotation($owner, $original->fresh());
        $issuedRevision = $this->issueQuotation($owner, $revision);

        $this->assertSame('DEV-21/2026-R1', $issuedRevision->quotation_number);
        $this->assertSame(22, (int) DB::table('quotation_sequences')
            ->where('organization_id', $organization->id)
            ->where('year', 2026)
            ->value('next_number'));

        $nextQuotation = $this->createQuotation($owner, $organization, $store, ['quotation_date' => '2026-09-23']);
        $this->addCatalogQuotationLine($owner, $nextQuotation, $variant);

        $this->assertSame('DEV-22/2026', $this->issueQuotation($owner, $nextQuotation)->quotation_number);
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
        Mail::assertSent(QuotationDocumentMail::class);

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
