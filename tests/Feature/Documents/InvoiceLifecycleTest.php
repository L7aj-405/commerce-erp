<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\CancelInvoiceDraftAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Documents\UpdateInvoiceDraftAction;
use App\Actions\Sales\CancelSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\InvoiceSequence;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\Support\DocumentTestCase;

class InvoiceLifecycleTest extends DocumentTestCase
{
    public function test_number_is_allocated_only_on_issue_and_double_issue_is_rejected_without_consuming_another_number(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        $draft = $this->createInvoice($owner, $order);
        $this->assertNull($draft->invoice_number);
        $issued = $this->issueInvoice($owner, $draft);
        $this->assertSame('INV-000001', $issued->invoice_number);
        $this->assertDatabaseHas('audit_logs', ['event' => 'invoice.issued', 'auditable_id' => $issued->id]);
        $this->expectException(ValidationException::class);
        try {
            app(IssueInvoiceAction::class)->execute($owner, $issued);
        } finally {
            $this->assertSame(2, InvoiceSequence::query()->findOrFail($organization->id)->next_number);
        }
    }

    public function test_draft_billing_information_can_be_corrected_and_draft_can_be_cancelled(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $draft = $this->createInvoice($owner, $order);
        $draft = app(UpdateInvoiceDraftAction::class)->execute($owner, $draft, [
            'invoice_date' => '2026-06-02', 'customer_name' => 'Correct Legal Name', 'customer_company' => 'Correct SARL',
            'customer_email' => 'correct@example.test', 'customer_phone' => '0611111111',
            'customer_tax_identifier' => 'ICE-CORRECT', 'billing_address' => 'Correct address', 'notes' => 'Reviewed',
        ]);
        $this->assertSame('Correct Legal Name', $draft->customer_name);
        $this->assertSame('ICE-CORRECT', $draft->customer_tax_identifier);
        $cancelled = app(CancelInvoiceDraftAction::class)->execute($owner, $draft, 'Customer requested cancellation');
        $this->assertSame('cancelled', $cancelled->status->value);
        $this->assertNull($cancelled->invoice_number);
        foreach (['invoice.draft_created', 'invoice.draft_updated', 'invoice.draft_cancelled'] as $event) {
            $this->assertDatabaseHas('audit_logs', ['event' => $event, 'auditable_id' => $draft->id]);
        }
    }

    public function test_issued_invoice_is_immutable_through_normal_update_and_cancel_actions(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $issued = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        foreach ([
            fn () => app(UpdateInvoiceDraftAction::class)->execute($owner, $issued, [
                'invoice_date' => now()->toDateString(), 'customer_name' => 'Attack', 'customer_company' => null,
                'customer_email' => null, 'customer_phone' => null, 'customer_tax_identifier' => null,
                'billing_address' => null, 'notes' => null,
            ]),
            fn () => app(CancelInvoiceDraftAction::class)->execute($owner, $issued, 'Attack'),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Expected issued Invoice immutability.');
            } catch (ValidationException) {
                $this->assertSame('issued', $issued->fresh()->status->value);
                $this->assertSame('Client', $issued->fresh()->customer_name);
            }
        }
    }

    public function test_source_order_cannot_be_cancelled_behind_an_active_or_issued_invoice(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $draft = $this->createInvoice($owner, $order);
        foreach (['draft', 'issued'] as $expectedStatus) {
            try {
                app(CancelSalesOrderAction::class)->execute($owner, $order->fresh(), 'Attack');
                $this->fail('Expected Sales Order cancellation to be blocked by its Invoice lifecycle.');
            } catch (ValidationException) {
                $this->assertSame('confirmed', $order->fresh()->status->value);
                $this->assertSame($expectedStatus, $draft->fresh()->status->value);
            }
            if ($expectedStatus === 'draft') {
                $draft = $this->issueInvoice($owner, $draft);
            }
        }
    }

    public function test_duplicate_full_invoice_is_rejected_server_side(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $this->createInvoice($owner, $order);
        $this->expectException(ValidationException::class);
        $this->createInvoice($owner, $order);
    }

    public function test_issue_revalidates_persisted_snapshot_totals_before_allocating_a_number(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $draft = $this->createInvoice($owner, $order);
        $line = $draft->lines()->firstOrFail();
        $line->total_incl_tax = '1.0000';
        $line->save();
        try {
            $this->issueInvoice($owner, $draft);
            $this->fail('Expected tampered Invoice snapshot rejection.');
        } catch (ValidationException) {
            $this->assertNull($draft->fresh()->invoice_number);
            $this->assertDatabaseCount('invoice_sequences', 0);
        }
    }

    public function test_issued_snapshot_survives_customer_and_catalog_changes(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $customer = $this->createCustomer($organization, 'Original Customer', ['company_name' => 'Original Company', 'billing_address' => 'Original address']);
        $warehouse = $this->createWarehouse($organization);
        $tax = $this->createTaxRate($organization, 'VAT 20', '20.0000');
        $product = $this->createProduct($organization, 'Original Product', attributes: ['default_sale_price' => '100.0000', 'tax_rate_id' => $tax->id]);
        $variant = $product->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant);
        $order = $this->createDraftOrder($owner, $organization, $store, $customer);
        $this->addCatalogLine($owner, $order, $variant, $warehouse);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        $issued = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $customer->display_name = 'Renamed Customer';
        $customer->company_name = 'Renamed Company';
        $customer->billing_address = 'New address';
        $customer->save();
        $product->name = 'Renamed Product';
        $product->save();
        $variant->default_sale_price = '999.0000';
        $variant->save();
        $tax->name = 'Changed Tax';
        $tax->rate = '10.0000';
        $tax->save();
        $this->assertSame('Original Customer', $issued->fresh()->customer_name);
        $this->assertSame('Original Company', $issued->fresh()->customer_company);
        $this->assertSame('Original address', $issued->fresh()->billing_address);
        $line = $issued->lines()->firstOrFail();
        $this->assertSame('Original Product', $line->product_name);
        $this->assertSame('100.0000', $line->unit_price_excl_tax);
        $this->assertSame('VAT 20', $line->tax_name);
        $this->assertSame('20.0000', $line->tax_rate);
        $this->assertSame('120.0000', $line->total_incl_tax);
    }
}
