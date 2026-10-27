<?php

namespace Tests\Feature\Quotations;

use App\Actions\Quotations\CancelQuotationSupplierRequestAction;
use App\Actions\Quotations\CreateQuotationSupplierRequestAction;
use App\Actions\Quotations\IssueQuotationAction;
use App\Actions\Quotations\RecordQuotationSupplierAvailabilityAction;
use App\Enums\QuotationStatus;
use App\Enums\QuotationSupplierAvailabilityStatus as Status;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\QuotationDocumentRenderer;
use App\Services\QuotationSourcing;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\QuotationTestCase;

class QuotationSupplierSourcingTest extends QuotationTestCase
{
    private User $owner;

    private Organization $organization;

    private Store $store;

    private ProductVariant $variant;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00'));

        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->organization, $this->owner);
        $tax = $this->createTaxRate($this->organization, 'TVA 20', '20.0000');
        $this->variant = $this->createProduct($this->organization, 'Micro', 'MIC-SRC', [
            'default_sale_price' => '1000.0000',
            'tax_rate_id' => $tax->id,
        ])->variants->firstOrFail();
        $this->activate($this->owner, $this->organization, $this->store);

        // Internal available stock: 2.
        $warehouse = $this->createWarehouse($this->organization);
        $this->openStock($this->owner, $this->organization, $warehouse, $this->variant, '2.0000');

        $this->supplier = $this->supplier($this->organization, 'FOURNISSEUR-INTERNE-XYZ');
    }

    public function test_a_catalog_line_fully_in_stock_requires_no_supplier_request_and_issues_normally(): void
    {
        $quotation = $this->createQuotation($this->owner, $this->organization, $this->store);
        $line = $this->addCatalogQuotationLine($this->owner, $quotation, $this->variant, ['quantity' => '2']);

        $evaluation = $this->evaluate($quotation)[$line->id];
        $this->assertFalse($evaluation['requires_sourcing']);
        $this->assertSame('in_stock', $evaluation['state']);
        $this->assertSame('0.0000', $evaluation['shortage']);

        $issued = $this->issue($this->owner, $quotation);
        $this->assertSame(QuotationStatus::Issued, $issued->status);
        $this->assertSame(0, QuotationSupplierAvailabilityRequest::query()->count());
        $this->assertDatabaseMissing('audit_logs', ['event' => 'quotation_supplier_availability.manually_confirmed']);
    }

    public function test_shortage_is_requested_minus_available_and_stock_is_consumed_in_line_order(): void
    {
        $quotation = $this->createQuotation($this->owner, $this->organization, $this->store);
        $first = $this->addCatalogQuotationLine($this->owner, $quotation, $this->variant, ['quantity' => '5']);
        $second = $this->addCatalogQuotationLine($this->owner, $quotation, $this->variant, ['quantity' => '1']);

        $evaluation = $this->evaluate($quotation);
        $this->assertSame('2.0000', $evaluation[$first->id]['internal_available']);
        $this->assertSame('3.0000', $evaluation[$first->id]['shortage']);
        $this->assertSame('to_source', $evaluation[$first->id]['state']);
        // The 2 units already cover line 1: line 2 is fully short.
        $this->assertSame('1.0000', $evaluation[$second->id]['shortage']);
    }

    public function test_a_shortage_line_can_create_a_supplier_request_defaulting_to_the_shortage(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');

        $request = $this->requestSupplier($quotation, $line, ['note' => 'Appeler avant midi']);

        $this->assertSame(Status::Pending, $request->status);
        $this->assertSame('3.0000', $request->requested_quantity);
        $this->assertSame($this->owner->id, $request->requested_by_user_id);
        $this->assertSame($this->store->id, $request->store_id);
        $this->assertSame($this->variant->id, $request->product_variant_id);
        $this->assertNotNull($request->requested_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'quotation_supplier_availability.requested', 'auditable_id' => $request->id]);

        // The supplier becomes a known supplier of the product.
        $this->assertTrue(ProductSupplier::query()
            ->where('product_id', $this->variant->product_id)->where('supplier_id', $this->supplier->id)->exists());

        // A meaningless duplicate pending request is refused.
        $this->expectException(ValidationException::class);
        $this->requestSupplier($quotation, $line);
    }

    public function test_an_in_stock_line_refuses_a_supplier_request(): void
    {
        $quotation = $this->createQuotation($this->owner, $this->organization, $this->store);
        $line = $this->addCatalogQuotationLine($this->owner, $quotation, $this->variant, ['quantity' => '1']);

        $this->expectException(ValidationException::class);
        $this->requestSupplier($quotation, $line);
    }

    public function test_a_pending_request_blocks_normal_issue(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $this->requestSupplier($quotation, $line);

        $this->assertIssueBlocked($quotation);
    }

    public function test_an_available_request_covering_the_shortage_allows_issue(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $request = $this->requestSupplier($quotation, $line);
        $request = $this->record($quotation, $request, 'available', '3');
        $this->assertSame(Status::Available, $request->status);

        $this->assertSame(QuotationStatus::Issued, $this->issue($this->owner, $quotation)->status);
        // Normal supplier-confirmed flow: no manual confirmation is involved.
        $this->assertSame(Status::Available, $request->fresh()->status);
        $this->assertSame('3.0000', $request->fresh()->confirmed_quantity);
        $this->assertSame(0, $this->manualConfirmations($line)->count());
        $this->assertDatabaseMissing('audit_logs', ['event' => 'quotation_supplier_availability.manually_confirmed']);
    }

    public function test_a_supplier_confirming_more_than_the_shortage_is_sufficient(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $this->record($quotation, $this->requestSupplier($quotation, $line), 'available', '5');

        $this->assertSame(QuotationStatus::Issued, $this->issue($this->owner, $quotation)->status);
    }

    public function test_partial_availability_does_not_allow_normal_issue(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $request = $this->record($quotation, $this->requestSupplier($quotation, $line), 'available', '2');

        $this->assertSame(Status::Partial, $request->status);
        $this->assertSame('partial', $this->evaluate($quotation)[$line->id]['state']);
        $this->assertIssueBlocked($quotation);
    }

    public function test_an_unavailable_request_does_not_allow_normal_issue(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $request = $this->record($quotation, $this->requestSupplier($quotation, $line), 'unavailable');

        $this->assertSame(Status::Unavailable, $request->status);
        $this->assertSame('0.0000', $request->confirmed_quantity);
        $this->assertIssueBlocked($quotation);
    }

    public function test_a_cancelled_request_no_longer_counts(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $request = $this->record($quotation, $this->requestSupplier($quotation, $line), 'available', '3');
        app(CancelQuotationSupplierRequestAction::class)->execute($this->owner, $quotation, $request);

        $this->assertDatabaseHas('audit_logs', ['event' => 'quotation_supplier_availability.cancelled', 'auditable_id' => $request->id]);
        $this->assertSame('to_source', $this->evaluate($quotation)[$line->id]['state']);
        $this->assertIssueBlocked($quotation);
    }

    public function test_a_quantity_increase_invalidates_an_insufficient_prior_confirmation(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $this->record($quotation, $this->requestSupplier($quotation, $line), 'available', '3');
        $this->assertTrue($this->evaluate($quotation)[$line->id]['resolved']);

        // 5 → 8: shortage becomes 6, the confirmation for 3 is no longer enough.
        $this->updateCatalogLine($quotation, $line, $this->variant, '8');

        $evaluation = $this->evaluate($quotation)[$line->id];
        $this->assertSame('6.0000', $evaluation['shortage']);
        $this->assertSame('3.0000', $evaluation['covered_quantity']);
        $this->assertFalse($evaluation['resolved']);
        $this->assertSame('insufficient', $evaluation['state']);
        $this->assertIssueBlocked($quotation);
    }

    public function test_a_confirmation_for_another_article_does_not_count_after_the_line_article_changes(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $this->record($quotation, $this->requestSupplier($quotation, $line), 'available', '5');

        $other = $this->createProduct($this->organization, 'Enceinte', 'ENC-SRC', ['default_sale_price' => '100.0000'])->variants->firstOrFail();
        $this->updateCatalogLine($quotation, $line, $other, '5');

        $this->assertSame('to_source', $this->evaluate($quotation)[$line->id]['state']);
        $this->assertIssueBlocked($quotation);
    }

    public function test_an_authorized_user_can_manually_confirm_and_issue_with_actor_time_and_audit(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $pending = $this->requestSupplier($quotation, $line);
        $manager = $this->member(['quotations.view', 'quotations.update', 'quotations.issue', 'quotations.override_supplier_availability']);

        $issued = app(IssueQuotationAction::class)->execute($manager, $quotation->fresh(), true);
        $this->assertSame(QuotationStatus::Issued, $issued->status);

        // The pending supplier request is left exactly as it was.
        $pending->refresh();
        $this->assertSame(Status::Pending, $pending->status);
        $this->assertNull($pending->confirmed_quantity);
        $this->assertNull($pending->resolved_by_user_id);

        // A separate manual confirmation covers the shortage.
        $manual = $this->manualConfirmations($line)->sole();
        $this->assertNotSame($pending->id, $manual->id);
        $this->assertSame('3.0000', $manual->confirmed_quantity);
        $this->assertSame($manager->id, $manual->resolved_by_user_id);
        $this->assertEquals(now()->toDateTimeString(), $manual->resolved_at->toDateTimeString());
        // The line was being sourced from exactly one supplier: it is kept as context.
        $this->assertSame($this->supplier->id, $manual->supplier_id);

        $log = \App\Models\AuditLog::query()
            ->where('event', 'quotation_supplier_availability.manually_confirmed')
            ->where('auditable_id', $manual->id)->sole();
        $this->assertSame($manager->id, $log->actor_id);
        $this->assertSame($this->organization->id, $log->organization_id);
        $this->assertSame($quotation->id, $log->new_values['quotation_id']);
        $this->assertSame($line->id, $log->new_values['quotation_line_id']);
        $this->assertSame('3.0000', $log->new_values['manually_confirmed_quantity']);
        $this->assertSame($this->supplier->id, $log->new_values['supplier_id']);
        $this->assertNotEmpty($log->new_values['confirmed_at']);

        // The original request audit event is untouched.
        $this->assertDatabaseHas('audit_logs', ['event' => 'quotation_supplier_availability.requested', 'auditable_id' => $pending->id]);

        $issuedLog = \App\Models\AuditLog::query()->where('event', 'quotation.issued')->where('auditable_id', $quotation->id)->firstOrFail();
        $this->assertSame([$manual->id], $issuedLog->new_values['supplier_availability_manually_confirmed_requests']);
    }

    public function test_a_partial_supplier_response_is_preserved_and_a_separate_manual_confirmation_covers_the_rest(): void
    {
        // Stock 2, requested 8 → shortage 6. Supplier confirms only 2.
        [$quotation, $line] = $this->shortageQuotation('8');
        $partial = $this->record($quotation, $this->requestSupplier($quotation, $line), 'available', '2');
        $this->assertSame(Status::Partial, $partial->status);
        $partialRecordedLog = \App\Models\AuditLog::query()
            ->where('event', 'quotation_supplier_availability.recorded')->where('auditable_id', $partial->id)->sole();

        $this->assertIssueBlocked($quotation);
        $issued = $this->issue($this->owner, $quotation, true);
        $this->assertSame(QuotationStatus::Issued, $issued->status);

        // Supplier truth unchanged: still partial, still 2.
        $partial->refresh();
        $this->assertSame(Status::Partial, $partial->status);
        $this->assertSame('2.0000', $partial->confirmed_quantity);
        $this->assertSame('6.0000', $partial->requested_quantity);
        $this->assertSame($partialRecordedLog->new_values, \App\Models\AuditLog::query()->whereKey($partialRecordedLog->id)->sole()->new_values);

        // Separate manual confirmation for the uncovered 4.
        $manual = $this->manualConfirmations($line)->sole();
        $this->assertSame('4.0000', $manual->confirmed_quantity);
        $this->assertSame($this->owner->id, $manual->resolved_by_user_id);

        // Combined coverage: 2 (supplier) + 4 (manual) = 6.
        $evaluation = app(QuotationSourcing::class)->evaluate($issued->fresh())[$line->id];
        $this->assertSame('2.0000', $evaluation['supplier_confirmed_quantity']);
        $this->assertSame('4.0000', $evaluation['manually_confirmed_quantity']);
        $this->assertSame('6.0000', $evaluation['covered_quantity']);
        $this->assertTrue($evaluation['resolved']);
        $this->assertSame('confirmed_manually', $evaluation['state']);

        $statuses = collect($evaluation['requests'])->pluck('confirmed_quantity', 'status')->all();
        $this->assertSame(['partial' => '2.0000', 'confirmed_manually' => '4.0000'], $statuses);
    }

    public function test_a_full_supplier_confirmation_plus_an_earlier_manual_confirmation_sum_to_cover_a_larger_shortage(): void
    {
        // Two suppliers: one confirms 3 fully, another only partially → combined with
        // a later manual top-up the line issues; each source keeps its own quantity.
        [$quotation, $line] = $this->shortageQuotation('9'); // shortage 7
        $this->record($quotation, $this->requestSupplier($quotation, $line, ['requested_quantity' => '3']), 'available', '3');
        $second = $this->supplier($this->organization, 'Second');
        $partial = app(CreateQuotationSupplierRequestAction::class)->execute($this->owner, $quotation, $line, ['supplier_id' => $second->id, 'requested_quantity' => '4']);
        $this->record($quotation, $partial, 'available', '1');

        $evaluation = $this->evaluate($quotation)[$line->id];
        $this->assertSame('4.0000', $evaluation['covered_quantity']);
        $this->assertFalse($evaluation['resolved']);

        $this->issue($this->owner, $quotation, true);

        $manual = $this->manualConfirmations($line)->sole();
        $this->assertSame('3.0000', $manual->confirmed_quantity);
        // Two different suppliers are involved: none is attributed to the attestation.
        $this->assertNull($manual->supplier_id);
        $this->assertSame('1.0000', $partial->fresh()->confirmed_quantity);
        $this->assertSame(Status::Partial, $partial->fresh()->status);
    }

    public function test_partial_responses_alone_never_satisfy_the_gate_even_if_they_add_up(): void
    {
        // Requested 6 from a supplier who gives 5; stock later rises so the shortage is 3.
        [$quotation, $line] = $this->shortageQuotation('8');
        $this->record($quotation, $this->requestSupplier($quotation, $line), 'available', '5');
        $this->openStock($this->owner, $this->organization, $this->createWarehouse($this->organization, 'Depot'), $this->variant, '3.0000');

        $evaluation = $this->evaluate($quotation)[$line->id];
        $this->assertSame('3.0000', $evaluation['shortage']);
        $this->assertSame('partial', $evaluation['state']);
        $this->assertIssueBlocked($quotation);

        // The override attests availability without inventing quantity.
        $this->issue($this->owner, $quotation, true);
        $this->assertSame('0.0000', $this->manualConfirmations($line)->sole()->confirmed_quantity);
    }

    public function test_manual_confirmation_without_any_request_creates_a_traceable_confirmation(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');

        $this->issue($this->owner, $quotation, true);

        // Only the manual attestation exists: no fabricated supplier response.
        $request = QuotationSupplierAvailabilityRequest::query()->where('quotation_line_id', $line->id)->sole();
        $this->assertSame(Status::ConfirmedManually, $request->status);
        $this->assertNull($request->supplier_id);
        $this->assertSame('3.0000', $request->confirmed_quantity);
        $this->assertSame($this->owner->id, $request->resolved_by_user_id);
        $this->assertSame(0, QuotationSupplierAvailabilityRequest::query()
            ->whereIn('status', [Status::Pending->value, Status::Available->value, Status::Partial->value, Status::Unavailable->value])->count());
        $this->assertDatabaseMissing('audit_logs', ['event' => 'quotation_supplier_availability.recorded']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'quotation_supplier_availability.requested']);
    }

    public function test_an_unauthorized_user_cannot_use_manual_confirmation(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $this->requestSupplier($quotation, $line);
        $seller = $this->member(['quotations.view', 'quotations.update', 'quotations.issue']);

        try {
            app(IssueQuotationAction::class)->execute($seller, $quotation->fresh(), true);
            $this->fail('A user without the override permission must not confirm availability.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // HTTP transport enforces the same backend rule.
        $this->actingAs($seller)
            ->post(route('quotations.issue', $quotation), ['confirm_supplier_availability' => true])
            ->assertForbidden();

        $this->assertSame(QuotationStatus::Draft, $quotation->fresh()->status);
        $this->assertSame(0, QuotationSupplierAvailabilityRequest::query()->where('status', Status::ConfirmedManually->value)->count());
    }

    public function test_the_override_permission_is_not_granted_to_the_sales_employee_role(): void
    {
        $this->assertNotContains('quotations.override_supplier_availability', config('platform.default_roles.sales-employee.permissions'));
        $this->assertContains('quotations.override_supplier_availability', config('platform.default_roles.admin.permissions'));
    }

    public function test_a_non_stock_item_requires_sourcing_and_issue_never_creates_a_catalog_product(): void
    {
        $quotation = $this->createQuotation($this->owner, $this->organization, $this->store);
        $line = $this->addNonStockQuotationLine($this->owner, $quotation, ['name' => 'Projecteur spécial', 'quantity' => '4']);
        $productsBefore = Product::query()->count();

        $evaluation = $this->evaluate($quotation)[$line->id];
        $this->assertTrue($evaluation['requires_sourcing']);
        $this->assertNull($evaluation['internal_available']);
        $this->assertSame('4.0000', $evaluation['shortage']);
        $this->assertIssueBlocked($quotation);

        $request = $this->requestSupplier($quotation, $line);
        $this->assertSame('4.0000', $request->requested_quantity);
        $this->assertSame($line->non_stock_item_id, $request->non_stock_item_id);
        $this->record($quotation, $request, 'available', '4');

        $this->assertSame(QuotationStatus::Issued, $this->issue($this->owner, $quotation)->status);
        $this->assertSame($productsBefore, Product::query()->count());
        $this->assertSame(0, ProductSupplier::query()->count());
    }

    public function test_the_customer_pdf_contains_no_sourcing_stock_or_supplier_information(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $this->requestSupplier($quotation, $line, ['note' => 'NOTE-SOURCING-CONFIDENTIELLE']);
        $issued = $this->issue($this->owner, $quotation, true);

        $renderer = app(QuotationDocumentRenderer::class);
        $html = $renderer->html($issued->fresh());
        $payload = json_encode($renderer->payload($issued->fresh()));

        foreach ([$html, $payload] as $rendered) {
            $this->assertStringNotContainsString('FOURNISSEUR-INTERNE-XYZ', $rendered);
            $this->assertStringNotContainsString('NOTE-SOURCING-CONFIDENTIELLE', $rendered);
            $this->assertStringNotContainsStringIgnoringCase('Manquant', $rendered);
            $this->assertStringNotContainsStringIgnoringCase('Confirmé manuellement', $rendered);
            $this->assertStringNotContainsStringIgnoringCase('confirmed_manually', $rendered);
            $this->assertStringNotContainsStringIgnoringCase('supplier', $rendered);
            $this->assertStringNotContainsStringIgnoringCase('fournisseur', $rendered);
            $this->assertStringNotContainsString('internal_available', $rendered);
            $this->assertStringNotContainsString('shortage', $rendered);
        }

        // Also the real PDF HTTP route still renders.
        $this->actingAs($this->owner)->get(route('quotations.pdf', $issued))->assertOk();
    }

    public function test_the_revision_recalculates_sourcing_from_its_own_lines(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $this->record($quotation, $this->requestSupplier($quotation, $line), 'available', '3');
        $issued = $this->issue($this->owner, $quotation);

        $revision = $this->reviseQuotation($this->owner, $issued);
        $this->assertSame(QuotationStatus::Draft, $revision->status);
        $revisionLine = $revision->lines->sole();

        // The source quotation's sourcing state is not inherited blindly.
        $this->assertSame('to_source', $this->evaluate($revision)[$revisionLine->id]['state']);
        $this->assertSame(1, QuotationSupplierAvailabilityRequest::query()->where('quotation_id', $issued->id)->count());
        $this->assertSame(0, QuotationSupplierAvailabilityRequest::query()->where('quotation_id', $revision->id)->count());

        $this->record($revision, $this->requestSupplier($revision, $revisionLine), 'available', '3');
        $issuedRevision = $this->issue($this->owner, $revision);

        $this->assertSame(QuotationStatus::Issued, $issuedRevision->status);
        $this->assertStringEndsWith('-R1', $issuedRevision->quotation_number);
        $this->assertSame(QuotationStatus::Superseded, $issued->fresh()->status);
    }

    public function test_requests_are_frozen_once_the_quotation_is_issued(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $request = $this->record($quotation, $this->requestSupplier($quotation, $line), 'available', '3');
        $this->issue($this->owner, $quotation);

        $this->expectException(ValidationException::class);
        app(RecordQuotationSupplierAvailabilityAction::class)->execute($this->owner, $quotation->fresh(), $request, ['outcome' => 'unavailable']);
    }

    public function test_removing_a_draft_line_removes_its_internal_requests(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $this->requestSupplier($quotation, $line);

        app(\App\Actions\Quotations\RemoveQuotationLineAction::class)->execute($this->owner, $quotation, $line);

        $this->assertSame(0, QuotationSupplierAvailabilityRequest::query()->count());
    }

    public function test_recording_a_result_requires_the_supplier_procurement_permission(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $request = $this->requestSupplier($quotation, $line);
        $seller = $this->member(['quotations.view', 'quotations.update']);

        $this->expectException(HttpException::class);
        app(RecordQuotationSupplierAvailabilityAction::class)->execute($seller, $quotation, $request, ['outcome' => 'available', 'confirmed_quantity' => '3']);
    }

    public function test_tenant_and_store_isolation_is_preserved(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');
        $request = $this->requestSupplier($quotation, $line);

        // Another organisation's supplier can never be attached.
        $otherOwner = User::factory()->create();
        $otherOrganization = $this->createOrganization($otherOwner, 'Other');
        $otherStore = $this->createStore($otherOrganization, $otherOwner);
        $foreignSupplier = $this->supplier($otherOrganization, 'Foreign');
        try {
            app(CreateQuotationSupplierRequestAction::class)->execute($this->owner, $quotation, $line, ['supplier_id' => $foreignSupplier->id]);
            $this->fail('A foreign supplier must be rejected.');
        } catch (ValidationException) {
            // expected
        }

        // A foreign actor cannot reach the Devis or its requests.
        $this->activate($otherOwner, $otherOrganization, $otherStore);
        $this->actingAs($otherOwner)
            ->post(route('quotations.sourcing.store', [$quotation->id, $line->id]), ['supplier_id' => $this->supplier->id])
            ->assertNotFound();
        $this->actingAs($otherOwner)
            ->patch(route('quotations.sourcing.record', [$quotation->id, $request->id]), ['outcome' => 'available'])
            ->assertNotFound();

        // A member of the same organisation outside the Devis store is refused.
        $outsider = User::factory()->create();
        $this->addOrganizationMember($this->organization, $outsider, ['quotations.view', 'quotations.update', 'suppliers.view', 'procurement.manage']);
        $otherStoreSameOrg = $this->createStore($this->organization, $this->owner, 'Second');
        $this->addStoreMember($otherStoreSameOrg, $outsider);
        $this->activate($outsider, $this->organization, $otherStoreSameOrg);
        $this->expectException(HttpException::class);
        app(CreateQuotationSupplierRequestAction::class)->execute($outsider, $quotation, $line, ['supplier_id' => $this->supplier->id]);
    }

    public function test_a_request_from_another_quotation_cannot_be_mutated_through_this_one(): void
    {
        [$quotationA, $lineA] = $this->shortageQuotation('5');
        [$quotationB] = $this->shortageQuotation('4');
        $requestA = $this->requestSupplier($quotationA, $lineA);

        $this->actingAs($this->owner)
            ->patch(route('quotations.sourcing.record', [$quotationB->id, $requestA->id]), ['outcome' => 'available'])
            ->assertNotFound();
        $this->assertSame(Status::Pending, $requestA->fresh()->status);
    }

    public function test_the_http_flow_creates_records_and_lists_known_suppliers(): void
    {
        [$quotation, $line] = $this->shortageQuotation('5');

        $this->actingAs($this->owner)
            ->post(route('quotations.sourcing.store', [$quotation->id, $line->id]), ['supplier_id' => $this->supplier->id, 'requested_quantity' => '3'])
            ->assertRedirect();
        $request = QuotationSupplierAvailabilityRequest::query()->sole();

        $this->actingAs($this->owner)
            ->patch(route('quotations.sourcing.record', [$quotation->id, $request->id]), ['outcome' => 'available', 'confirmed_quantity' => '3'])
            ->assertRedirect();
        $this->assertSame(Status::Available, $request->fresh()->status);

        $this->actingAs($this->owner)
            ->getJson(route('quotations.sourcing.supplier-options', [$quotation->id, $line->id]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->supplier->id)
            ->assertJsonPath('data.0.known', true);

        $this->actingAs($this->owner)
            ->get(route('quotations.show', $quotation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sourcing.0.state', 'available')
                ->where('sourcing.0.shortage', '3.0000')
                ->where('can.overrideSupplierAvailability', true));

        $this->actingAs($this->owner)->post(route('quotations.issue', $quotation))->assertRedirect();
        $this->assertSame(QuotationStatus::Issued, $quotation->fresh()->status);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array{Quotation, QuotationLine} */
    private function shortageQuotation(string $quantity): array
    {
        $quotation = $this->createQuotation($this->owner, $this->organization, $this->store);
        $line = $this->addCatalogQuotationLine($this->owner, $quotation, $this->variant, ['quantity' => $quantity]);

        return [$quotation, $line];
    }

    private function updateCatalogLine(Quotation $quotation, QuotationLine $line, ProductVariant $variant, string $quantity): void
    {
        app(\App\Actions\Quotations\SaveQuotationLineAction::class)->execute($this->owner, $quotation, [
            'line_type' => 'catalog',
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
            'discount_type' => 'none',
            'discount_value' => '0',
        ], $line);
    }

    /** @return \Illuminate\Support\Collection<int, QuotationSupplierAvailabilityRequest> */
    private function manualConfirmations(QuotationLine $line): \Illuminate\Support\Collection
    {
        return QuotationSupplierAvailabilityRequest::query()
            ->where('quotation_line_id', $line->id)
            ->where('status', Status::ConfirmedManually->value)
            ->get();
    }

    private function requestSupplier(Quotation $quotation, QuotationLine $line, array $data = []): QuotationSupplierAvailabilityRequest
    {
        return app(CreateQuotationSupplierRequestAction::class)->execute($this->owner, $quotation, $line, array_replace([
            'supplier_id' => $this->supplier->id,
        ], $data));
    }

    private function record(Quotation $quotation, QuotationSupplierAvailabilityRequest $request, string $outcome, ?string $confirmed = null): QuotationSupplierAvailabilityRequest
    {
        return app(RecordQuotationSupplierAvailabilityAction::class)->execute($this->owner, $quotation, $request, [
            'outcome' => $outcome,
            'confirmed_quantity' => $confirmed,
        ]);
    }

    private function issue(User $actor, Quotation $quotation, bool $confirm = false): Quotation
    {
        return app(IssueQuotationAction::class)->execute($actor, $quotation->fresh(), $confirm);
    }

    private function assertIssueBlocked(Quotation $quotation): void
    {
        try {
            $this->issue($this->owner, $quotation);
            $this->fail('Issue should be blocked by unresolved sourcing.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sourcing', $e->errors());
        }
        $this->assertSame(QuotationStatus::Draft, $quotation->fresh()->status);
        $this->assertNull($quotation->fresh()->quotation_number);
    }

    /** @return array<int, array<string, mixed>> */
    private function evaluate(Quotation $quotation): array
    {
        return app(QuotationSourcing::class)->evaluate($quotation->fresh());
    }

    /** @param list<string> $permissions */
    private function member(array $permissions): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($this->organization, $user, $permissions);
        $this->addStoreMember($this->store, $user);
        $this->activate($user, $this->organization, $this->store);

        return $user;
    }

    private function supplier(Organization $organization, string $name): Supplier
    {
        $supplier = new Supplier;
        $supplier->organization_id = $organization->id;
        $supplier->name = $name;
        $supplier->active = true;
        $supplier->save();

        return $supplier;
    }
}
