<?php

namespace Tests\Feature\Quotations;

use App\Actions\Quotations\ConvertQuotationToSalesOrderAction;
use App\Enums\SalesOrderPaymentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Tests\Support\QuotationTestCase;

/**
 * A Devis is a commercial proposal, not a business event: it must contribute
 * NOTHING to sales CA, invoice totals, encaissements, receivables, cash or bank.
 */
class QuotationFinanceBoundaryTest extends QuotationTestCase
{
    public function test_issuing_a_devis_creates_no_payment_no_invoice_and_no_finance_record(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'Console', 'CON-1', ['default_sale_price' => '5000.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->activate($owner, $organization, $store);

        $paymentsBefore = Payment::query()->count();
        $allocationsBefore = PaymentAllocation::query()->count();

        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant, ['quantity' => '3']);
        $issued = $this->issueQuotation($owner, $quotation);

        $this->assertSame('18000.0000', $issued->total_incl_tax); // 3 x 5000 + 20%
        $this->assertSame($paymentsBefore, Payment::query()->count());
        $this->assertSame($allocationsBefore, PaymentAllocation::query()->count());
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('sales_orders', 0);
        // The Devis does not carry a payment_status / receivable of its own.
        $this->assertArrayNotHasKey('payment_status', $issued->getAttributes());
    }

    public function test_conversion_produces_an_unpaid_draft_order_with_no_finance_impact_yet(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'Console', 'CON-2', ['default_sale_price' => '5000.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '20.0000');

        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addCatalogQuotationLine($owner, $quotation, $variant, ['quantity' => '2']);
        $issued = $this->issueQuotation($owner, $quotation);

        $order = app(ConvertQuotationToSalesOrderAction::class)->execute($owner, $issued, ['warehouse_id' => $warehouse->id])['order'];

        $this->assertSame(SalesOrderStatus::Draft, $order->status);
        $this->assertSame(SalesOrderPaymentStatus::Unpaid, $order->payment_status);
        $this->assertSame(0, Payment::query()->count());
        $this->assertDatabaseCount('invoices', 0);

        // Finance only ever follows an authoritative business event (a confirmed
        // sale / invoice / payment) — never the Devis or its conversion draft.
    }
}
