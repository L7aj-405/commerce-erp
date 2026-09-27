<?php

namespace Tests\Feature\Finance;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Enums\CustomerType;
use App\Models\User;
use Tests\Support\DocumentTestCase;

class InvoiceSnapshotTest extends DocumentTestCase
{
    public function test_invoice_from_order_uses_the_order_snapshot_not_the_current_product_price_or_tax(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $tax20 = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $tax7 = $this->createTaxRate($organization, 'TVA 7', '7.0000');
        $customer = $this->createCustomer($organization, 'Ste Client', [
            'type' => CustomerType::Company->value, 'company_name' => 'Client SARL', 'tax_identifier' => 'ICE-999', 'billing_address' => '12 Rue Atlas',
        ]);
        $variant = $this->createProduct($organization, 'Microphone Shure', 'MIC-1', [
            'default_sale_price' => '1000.0000',
            'tax_rate_id' => $tax20->getKey(),
        ])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');

        $order = $this->createDraftOrder($owner, $organization, $store, $customer);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '2.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();

        // The product changes AFTER the order is confirmed.
        $variant->forceFill(['default_sale_price' => '1300.0000', 'regular_sale_price' => '1300.0000', 'tax_rate_id' => $tax7->getKey()])->save();

        $invoice = $this->createInvoice($owner, $order);
        $line = $invoice->lines->first();

        $this->assertSame('1000.0000', $line->unit_price_excl_tax);
        $this->assertSame('1200.0000', $line->unit_price_incl_tax);
        $this->assertSame('20.0000', $line->tax_rate);
        $this->assertSame('TVA 20', $line->tax_name);
        $this->assertSame('2000.0000', $line->subtotal_excl_tax);
        $this->assertSame('400.0000', $line->tax_amount);
        $this->assertSame('2400.0000', $line->total_incl_tax);

        $this->assertSame('2000.0000', $invoice->subtotal_excl_tax);
        $this->assertSame('400.0000', $invoice->tax_total);
        $this->assertSame('2400.0000', $invoice->total_incl_tax);
        // Company billing identity is snapshotted from the customer profile.
        $this->assertSame('ICE-999', $invoice->customer_tax_identifier);

        // The issued invoice reconciles against its immutable line snapshots.
        $issued = $this->issueInvoice($owner, $invoice);
        $this->assertSame('2400.0000', $issued->total_incl_tax);
    }

    public function test_issued_invoice_with_ht_discount_keeps_its_immutable_financial_snapshot(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $tax20 = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $tax7 = $this->createTaxRate($organization, 'TVA 7', '7.0000');
        $variant = $this->createProduct($organization, 'Console', 'CON-SNAP', [
            'public_price_ttc' => '4050.0000',
            'tax_rate_id' => $tax20->getKey(),
        ])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');

        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $variant, $warehouse, [
            'quantity' => '2',
            'discount_type' => 'fixed',
            'discount_value' => '100.0000',
        ]);

        $issued = $this->issueInvoice($owner, $this->createInvoice($owner, $order));
        $this->assertSame('6750.0000', $issued->subtotal_excl_tax);
        $this->assertSame('100.0000', $issued->discount_total);
        $this->assertSame('1330.0000', $issued->tax_total);
        $this->assertSame('7980.0000', $issued->total_incl_tax);

        $variant->forceFill([
            'public_price_ttc' => '9999.0000',
            'default_sale_price' => '8332.5000',
            'regular_sale_price' => '8332.5000',
            'tax_rate_id' => $tax7->getKey(),
        ])->save();

        $issued->refresh();
        $this->assertSame('6750.0000', $issued->subtotal_excl_tax);
        $this->assertSame('100.0000', $issued->discount_total);
        $this->assertSame('1330.0000', $issued->tax_total);
        $this->assertSame('7980.0000', $issued->total_incl_tax);
    }
}
