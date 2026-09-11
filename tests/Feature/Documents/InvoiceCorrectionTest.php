<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Documents\StartInvoiceCorrectionAction;
use App\Actions\Documents\UpdateInvoiceDraftAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Services\DocumentPdfService;
use App\Services\InvoiceDocumentRenderer;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Validation\ValidationException;
use Tests\Support\DocumentTestCase;

class InvoiceCorrectionTest extends DocumentTestCase
{
    public function test_authorized_user_starts_a_correction_that_copies_the_original_snapshot_and_leaves_it_untouched(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $customer = $this->createCustomer($organization, 'Client Original', [
            'type' => 'business', 'company_name' => 'ORIG SARL', 'tax_identifier' => 'ICE-ORIG', 'billing_address' => 'Ancienne adresse',
        ]);
        $variant = $this->createProduct($organization, 'Widget', 'W-1', ['default_sale_price' => '1000.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');

        $order = $this->createDraftOrder($owner, $organization, $store, $customer);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '2.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        $original = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        // Product price changes AFTER issuance — the correction must ignore it.
        $variant->forceFill(['default_sale_price' => '9999.0000', 'regular_sale_price' => '9999.0000'])->save();

        $correction = app(StartInvoiceCorrectionAction::class)->execute($owner, $original, "Erreur d'adresse client");

        $this->assertSame(InvoiceStatus::Draft, $correction->status);
        $this->assertNull($correction->invoice_number);
        $this->assertSame($original->id, $correction->corrected_invoice_id);
        $this->assertSame("Erreur d'adresse client", $correction->correction_reason);
        $this->assertSame($original->sales_order_id, $correction->sales_order_id);

        // Snapshot fields copied from the original, not rebuilt from live data.
        $this->assertSame('ORIG SARL', $correction->customer_company);
        $this->assertSame('ICE-ORIG', $correction->customer_tax_identifier);
        $this->assertSame($original->representative_name, $correction->representative_name);
        $this->assertSame($original->payment_method_summary, $correction->payment_method_summary);
        $this->assertEquals($original->seller_snapshot, $correction->seller_snapshot);
        $this->assertSame($original->total_incl_tax, $correction->total_incl_tax);

        $line = $correction->lines()->firstOrFail();
        $this->assertSame('1000.0000', $line->unit_price_excl_tax);
        $this->assertSame('2000.0000', $line->subtotal_excl_tax);
        $this->assertSame('2400.0000', $line->total_incl_tax);

        // Original is completely unchanged.
        $this->assertSame(InvoiceStatus::Issued, $original->fresh()->status);
        $this->assertNotNull($original->fresh()->invoice_number);
        $this->assertDatabaseHas('audit_logs', ['event' => 'invoice.correction_started', 'auditable_id' => $correction->id]);
    }

    public function test_issuing_the_correction_supersedes_the_original_and_gives_the_correction_a_new_number(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $original = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $originalNumber = $original->invoice_number;

        $correction = app(StartInvoiceCorrectionAction::class)->execute($owner, $original, 'Représentant incorrect');
        app(UpdateInvoiceDraftAction::class)->execute($owner, $correction, [
            'invoice_date' => $correction->invoice_date->toDateString(),
            'representative_name' => 'REPRÉSENTANT CORRIGÉ',
        ]);
        $issued = app(IssueInvoiceAction::class)->execute($owner, $correction->fresh())->fresh();

        $this->assertSame(InvoiceStatus::Issued, $issued->status);
        $this->assertNotNull($issued->invoice_number);
        $this->assertNotSame($originalNumber, $issued->invoice_number);
        $this->assertSame('REPRÉSENTANT CORRIGÉ', $issued->representative_name);

        $this->assertSame(InvoiceStatus::Superseded, $original->fresh()->status);
        $this->assertSame($originalNumber, $original->fresh()->invoice_number); // number kept

        $this->assertDatabaseHas('audit_logs', ['event' => 'invoice.correction_issued', 'auditable_id' => $issued->id]);

        // The corrected PDF renders from the correction's own snapshot.
        $html = app(InvoiceDocumentRenderer::class)->html($issued);
        $this->assertStringContainsString('REPRÉSENTANT CORRIGÉ', $html);
        $pdf = app(DocumentPdfService::class)->invoice($issued);
        $this->assertStringStartsWith('%PDF-', $pdf['bytes']);
        // The superseded original still has its own official PDF.
        $this->assertStringStartsWith('%PDF-', app(DocumentPdfService::class)->invoice($original->fresh())['bytes']);
    }

    public function test_an_issued_invoice_cannot_be_silently_edited(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $issued = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $this->expectException(ValidationException::class);
        app(UpdateInvoiceDraftAction::class)->execute($owner, $issued, [
            'invoice_date' => $issued->invoice_date->toDateString(), 'customer_name' => 'Silent edit',
        ]);
    }

    public function test_a_draft_invoice_cannot_be_corrected_and_a_duplicate_correction_is_rejected(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $draft = $this->createInvoice($owner, $order);

        try {
            app(StartInvoiceCorrectionAction::class)->execute($owner, $draft, 'x');
            $this->fail('A draft Invoice must not be correctable.');
        } catch (ValidationException) {
        }

        $issued = $this->issueInvoice($owner, $draft->fresh());
        app(StartInvoiceCorrectionAction::class)->execute($owner, $issued, 'Première correction');

        $this->expectException(ValidationException::class);
        app(StartInvoiceCorrectionAction::class)->execute($owner, $issued->fresh(), 'Deuxième correction');
    }

    public function test_show_links_the_history_and_moves_sharing_to_the_correction(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $original = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $correction = app(StartInvoiceCorrectionAction::class)->execute($owner, $original, 'Adresse erronée');
        $issued = app(IssueInvoiceAction::class)->execute($owner, $correction->fresh())->fresh();

        // Superseded original: no sharing, history present, correct badge.
        $this->actingAs($owner)->get(route('invoices.show', $original))->assertOk()->assertInertia(fn (AssertableJson $page) => $page
            ->where('invoice.status', 'superseded')
            ->where('sharing', null)
            ->where('can.correct', false)
            ->where('history.0.role', 'original')
            ->where('history.1.role', 'correction')
            ->where('history.1.invoice_number', $issued->invoice_number)
            ->where('history.1.reason', 'Adresse erronée'));

        // The issued correction shares its own PDF.
        $this->actingAs($owner)->get(route('invoices.show', $issued))->assertInertia(fn (AssertableJson $page) => $page
            ->where('isCorrection', true)
            ->where('sharing.pdfUrl', fn ($url) => str_contains($url, "/invoices/{$issued->id}/shared-pdf")));
    }

    public function test_tenant_isolation_and_permission_on_the_correction_endpoint(): void
    {
        [$owner, $organization, $store, $order] = $this->documentFixture();
        $issued = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        // Outsider from another organization.
        $outsider = User::factory()->create();
        $otherOrg = $this->createOrganization($outsider);
        $otherStore = $this->createStore($otherOrg, $outsider);
        $this->activate($outsider, $otherOrg, $otherStore);
        $this->actingAs($outsider)->post(route('invoices.corrections.store', $issued), ['reason' => 'x'])->assertNotFound();

        // Member without issue permission.
        $clerk = User::factory()->create();
        $this->addOrganizationMember($organization, $clerk, ['invoices.view'], roleName: 'Clerk');
        $this->addStoreMember($store, $clerk);
        $this->activate($clerk, $organization, $store);
        $this->actingAs($clerk)->post(route('invoices.corrections.store', $issued), ['reason' => 'x'])->assertForbidden();

        // Reason is required.
        $this->actingAs($owner)->post(route('invoices.corrections.store', $issued), ['reason' => ''])->assertSessionHasErrors('reason');

        $this->assertSame(InvoiceStatus::Issued, $issued->fresh()->status);
        $this->assertSame(0, Invoice::query()->where('corrected_invoice_id', $issued->id)->count());
    }
}
