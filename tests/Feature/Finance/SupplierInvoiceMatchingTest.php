<?php

namespace Tests\Feature\Finance;

use App\Actions\Procurement\CreatePurchaseOrderFromProcurementRequirementsAction;
use App\Actions\Quotations\ConvertQuotationToSalesOrderAction;
use App\Actions\Quotations\CreateQuotationSupplierRequestAction;
use App\Actions\Quotations\RecordQuotationSupplierAvailabilityAction;
use App\Actions\SupplierInvoices\SaveSupplierInvoiceDraftAction;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrderProcurement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\SupplierInvoices\SupplierInvoiceMatchingService;
use Illuminate\Support\Facades\DB;
use Tests\Support\QuotationTestCase;

/** Phase 2 Sprint 1 (PR8): live matching of a supplier invoice against its purchase order. */
class SupplierInvoiceMatchingTest extends QuotationTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private Supplier $supplier;

    private TaxRate $tva;

    private ProductVariant $xlr;

    private ProductVariant $other;

    private PurchaseOrder $po;

    private PurchaseOrderLine $poLine;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['supplier_invoices.enabled' => true, 'inertia.testing.ensure_pages_exist' => false]);
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->org, $this->owner);
        $warehouse = $this->createWarehouse($this->org, 'Showroom', 'SHW');
        $this->tva = $this->createTaxRate($this->org, 'TVA 20', '20.0000');
        $this->xlr = $this->createProduct($this->org, 'Cordial XLR', 'COR-XLR', ['default_sale_price' => '1000.0000', 'tax_rate_id' => $this->tva->id])->variants->firstOrFail();
        $this->other = $this->createProduct($this->org, 'Autre', 'OTH-1')->variants->firstOrFail();
        $this->activate($this->owner, $this->org, $this->store);
        $this->supplier = new Supplier;
        $this->supplier->organization_id = $this->org->id;
        $this->supplier->name = 'MIFA';
        $this->supplier->active = true;
        $this->supplier->save();

        $quotation = $this->createQuotation($this->owner, $this->org, $this->store);
        $line = $this->addCatalogQuotationLine($this->owner, $quotation, $this->xlr, ['quantity' => '2']);
        $request = app(CreateQuotationSupplierRequestAction::class)->execute($this->owner, $quotation->fresh(), $line, ['supplier_id' => $this->supplier->id]);
        app(RecordQuotationSupplierAvailabilityAction::class)->execute($this->owner, $quotation->fresh(), $request, ['outcome' => 'available', 'confirmed_quantity' => '2']);
        $issued = $this->issueQuotation($this->owner, $quotation->fresh(), false);
        $order = app(ConvertQuotationToSalesOrderAction::class)->execute($this->owner, $this->acceptQuotation($this->owner, $issued), ['warehouse_id' => $warehouse->id])['order'];
        $requirement = SalesOrderProcurement::query()->where('sales_order_id', $order->id)->sole();
        $this->po = app(CreatePurchaseOrderFromProcurementRequirementsAction::class)->execute($this->owner, $this->org, $this->supplier, [$requirement->id])->fresh();
        // Ordered 2 at 10.0000, nothing received yet.
        DB::table('purchase_order_lines')->where('purchase_order_id', $this->po->id)->update(['unit_price' => '10.0000']);
        $this->poLine = $this->po->lines()->firstOrFail();
    }

    private function receive(string $quantity): void
    {
        DB::table('purchase_order_lines')->where('id', $this->poLine->id)->update(['received_quantity' => $quantity]);
    }

    /** @param array<string,mixed> $line */
    private function invoice(array $line = [], ?string $number = null): SupplierInvoice
    {
        $this->sequence++;
        $row = array_replace(['cost_nature' => 'inventory', 'purchase_order_line_id' => $this->poLine->id, 'description' => 'XLR', 'quantity' => '2', 'unit_price_ht' => '10.00', 'tax_rate_id' => $this->tva->id], $line);

        return app(SaveSupplierInvoiceDraftAction::class)->execute($this->owner, $this->org, [
            'store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'purchase_order_id' => $this->po->id,
            'invoice_number' => $number ?? 'M-'.$this->sequence, 'invoice_date' => '2026-11-20', 'currency_code' => $this->po->currency_code,
            'lines' => [$row],
        ])['invoice'];
    }

    private function match(SupplierInvoice $invoice): array
    {
        return app(SupplierInvoiceMatchingService::class)->match($invoice->fresh());
    }

    public function test_an_invoice_without_a_purchase_order_is_no_po(): void
    {
        $invoice = app(SaveSupplierInvoiceDraftAction::class)->execute($this->owner, $this->org, [
            'store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'invoice_number' => 'N-1', 'invoice_date' => '2026-11-20', 'currency_code' => 'MAD',
            'lines' => [['cost_nature' => 'expense', 'description' => 'Transport', 'quantity' => '1', 'unit_price_ht' => '5', 'tax_rate_id' => $this->tva->id]],
        ])['invoice'];

        $result = $this->match($invoice);

        $this->assertSame('no_po', $result['status']);
        $this->assertSame([], $result['lines'][0]['flags']);
    }

    public function test_goods_not_yet_received_make_it_partial_and_a_full_receipt_matches(): void
    {
        $invoice = $this->invoice();

        $result = $this->match($invoice);
        $this->assertSame('po_partial', $result['status']);
        $this->assertSame(['not_received'], $result['lines'][0]['flags']);

        $this->receive('2.0000');
        $this->assertSame(['status' => 'po_matched', 'lines' => [['line_id' => $invoice->lines->first()->id, 'flags' => []]]], $this->match($invoice));
    }

    public function test_price_variance_and_missing_po_price(): void
    {
        $this->receive('2.0000');

        $this->assertSame(['price_variance'], $this->match($this->invoice(['unit_price_ht' => '10.01']))['lines'][0]['flags']);
        $this->assertSame('variance', $this->match($this->invoice(['unit_price_ht' => '10.01']))['status']);

        DB::table('purchase_order_lines')->where('id', $this->poLine->id)->update(['unit_price' => null]);
        $result = $this->match($this->invoice());
        $this->assertSame(['po_price_missing'], $result['lines'][0]['flags']);
        $this->assertSame('po_partial', $result['status']);
    }

    public function test_over_invoiced_counts_other_approved_invoices(): void
    {
        $this->receive('2.0000');
        $first = $this->invoice(['quantity' => '2']);
        $this->assertSame('po_matched', $this->match($first)['status']);

        // A second invoice for the same PO line is only "over" once the first is APPROVED.
        $second = $this->invoice(['quantity' => '1']);
        $this->assertSame('po_matched', $this->match($second)['status']);
        DB::table('supplier_invoices')->where('id', $first->id)->update(['status' => 'approved', 'approved_at' => now()]);

        $result = $this->match($second);
        $this->assertSame('variance', $result['status']);
        $this->assertContains('over_invoiced', $result['lines'][0]['flags']);
        // The approved invoice itself still matches (it does not count itself twice).
        $this->assertSame('po_matched', $this->match($first)['status']);
    }

    public function test_quantity_above_the_order_and_above_the_receipt(): void
    {
        $this->receive('2.0000');
        $result = $this->match($this->invoice(['quantity' => '3']));
        $this->assertSame(['over_invoiced', 'not_received'], $result['lines'][0]['flags']);
        $this->assertSame('variance', $result['status']);
    }

    public function test_an_unlinked_inventory_line_is_not_linked_but_an_expense_line_is_ignored(): void
    {
        $this->receive('2.0000');
        $invoice = app(SaveSupplierInvoiceDraftAction::class)->execute($this->owner, $this->org, [
            'store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'purchase_order_id' => $this->po->id, 'invoice_number' => 'U-1',
            'invoice_date' => '2026-11-20', 'currency_code' => $this->po->currency_code,
            'lines' => [
                ['cost_nature' => 'inventory', 'product_variant_id' => $this->other->id, 'description' => 'Hors BC', 'quantity' => '1', 'unit_price_ht' => '4', 'tax_rate_id' => $this->tva->id],
                ['cost_nature' => 'expense', 'description' => 'Transport', 'quantity' => '1', 'unit_price_ht' => '5', 'tax_rate_id' => $this->tva->id],
            ],
        ])['invoice'];

        $result = $this->match($invoice);

        $this->assertSame('po_partial', $result['status']);
        $this->assertSame(['not_linked'], $result['lines'][0]['flags']);
        $this->assertSame([], $result['lines'][1]['flags']);
    }

    public function test_a_fully_matched_invoice_is_approved_without_acknowledgement_and_a_variance_needs_it(): void
    {
        $this->receive('2.0000');
        DB::table('suppliers')->where('id', $this->supplier->id)->update(['default_payment_term_days' => 30]);
        $matched = $this->invoice();
        $this->actingAs($this->owner)->postJson(route('finance.supplier-invoices.approve', $matched), ['control_total_ttc' => '24.00'])->assertRedirect();
        $this->assertSame('po_matched', $matched->fresh()->match_status_at_approval->value);
        $this->assertNull($matched->fresh()->match_note);

        $variance = $this->invoice(['unit_price_ht' => '11.00']);
        $this->actingAs($this->owner)->postJson(route('finance.supplier-invoices.approve', $variance), ['control_total_ttc' => '26.40'])
            ->assertStatus(422)->assertJsonValidationErrors('acknowledge_unmatched');
        $this->actingAs($this->owner)->postJson(route('finance.supplier-invoices.approve', $variance), [
            'control_total_ttc' => '26.40', 'acknowledge_unmatched' => true, 'match_note' => 'Prix négocié',
        ])->assertRedirect();
        $this->assertSame('variance', $variance->fresh()->match_status_at_approval->value);
        $this->assertSame('Prix négocié', $variance->fresh()->match_note);
    }

    public function test_show_exposes_live_matching(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.show', $invoice))->assertOk()->assertInertia(fn ($page) => $page
            ->where('matching.status', 'po_partial')->where('matching.lines.0.flags.0', 'not_received'));
        $this->actingAs($this->owner)->get(route('finance.supplier-invoices.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('invoices.data.0.match_status', 'po_partial'));
    }
}
