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
use App\Models\SalesOrderProcurement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\Support\QuotationTestCase;

/** Phase 2 Sprint 1 (PR5): purchase order LINE links on a supplier invoice draft use a real procurement chain. */
class SupplierInvoicePurchaseOrderTest extends QuotationTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private Supplier $supplier;

    private TaxRate $tva;

    private ProductVariant $xlr;

    private ProductVariant $other;

    private PurchaseOrder $po;

    protected function setUp(): void
    {
        parent::setUp();
        config(['supplier_invoices.enabled' => true]);
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
    }

    /** @param array<string,mixed> $line @return array<string,mixed> */
    private function data(array $line): array
    {
        return [
            'store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'purchase_order_id' => $this->po->id,
            'invoice_number' => 'PO-1', 'invoice_date' => '2026-11-20', 'currency_code' => $this->po->currency_code,
            'lines' => [array_replace(['cost_nature' => 'inventory', 'description' => 'XLR', 'quantity' => '2', 'unit_price_ht' => '10.00', 'tax_rate_id' => $this->tva->id], $line)],
        ];
    }

    public function test_a_line_linked_to_a_purchase_order_line_inherits_its_variant(): void
    {
        $poLine = $this->po->lines->sole();

        $result = app(SaveSupplierInvoiceDraftAction::class)->execute($this->owner, $this->org, $this->data(['purchase_order_line_id' => $poLine->id]));

        $line = $result['invoice']->lines->sole();
        $this->assertSame($poLine->id, $line->purchase_order_line_id);
        $this->assertSame($this->xlr->id, $line->product_variant_id);
        $this->assertSame($this->po->id, $result['invoice']->purchase_order_id);
    }

    public function test_a_variant_that_contradicts_the_purchase_order_line_is_refused(): void
    {
        try {
            app(SaveSupplierInvoiceDraftAction::class)->execute($this->owner, $this->org, $this->data(['purchase_order_line_id' => $this->po->lines->sole()->id, 'product_variant_id' => $this->other->id]));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines.0.product_variant_id', $e->errors());
        }
        $this->assertSame(0, SupplierInvoice::query()->count());
    }
}
