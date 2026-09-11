<?php

namespace Tests\Feature\Documents;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\DeliveryNoteDocumentRenderer;
use App\Services\InvoiceDocumentRenderer;
use Tests\Support\DocumentTestCase;

class DocumentRenderingTest extends DocumentTestCase
{
    public function test_issued_invoice_rendering_never_refreshes_live_commercial_or_seller_data(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $organization->settings = ['document_profile' => ['legal_name' => 'Original Seller', 'address' => 'Original seller address']];
        $organization->save();
        $customer = $this->createCustomer($organization, 'Original Customer', ['company_name' => 'Original Customer SARL', 'billing_address' => 'Original customer address']);
        $warehouse = $this->createWarehouse($organization);
        $tax = $this->createTaxRate($organization, 'Original VAT', '20.0000');
        $product = $this->createProduct($organization, 'Original Product', 'SKU-ORIGINAL', [
            'default_sale_price' => '100.0000', 'purchase_price' => '40.0000', 'tax_rate_id' => $tax->id,
        ]);
        $variant = $product->variants->firstOrFail();
        $this->openStock($owner, $organization, $warehouse, $variant);
        $order = $this->createDraftOrder($owner, $organization, $store, $customer);
        $this->addCatalogLine($owner, $order, $variant, $warehouse);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order);
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order));

        $customer->display_name = 'Changed Customer';
        $customer->company_name = 'Changed Customer Company';
        $customer->billing_address = 'Changed customer address';
        $customer->save();
        $product->name = 'Changed Product';
        $product->save();
        $variant->sku = 'SKU-CHANGED';
        $variant->default_sale_price = '999.0000';
        $variant->save();
        $tax->name = 'Changed Tax';
        $tax->rate = '10.0000';
        $tax->save();
        $organization->settings = ['document_profile' => ['legal_name' => 'Changed Seller', 'address' => 'Changed seller address']];
        $organization->save();

        $html = app(InvoiceDocumentRenderer::class)->html($invoice->fresh());
        foreach (['Original Seller', 'Original seller address', 'Original Customer SARL', 'Original customer address', 'Original Product', 'SKU-ORIGINAL', 'Original VAT', '20 %', '100,00', '120,00'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        foreach (['Changed Seller', 'Changed customer address', 'Changed Product', 'SKU-CHANGED', 'Changed Tax', '999,00', 'purchase_price', '40,00'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }

    public function test_draft_print_uses_snapshots_and_a_watermark(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        $organization->settings = ['document_profile' => ['legal_name' => 'Original Seller SARL', 'tax_identifier' => 'ICE-OLD']];
        $organization->save();
        $invoice = $this->createInvoice($owner, $order, ['invoice_date' => '2026-06-01']);

        $organization->settings = ['document_profile' => ['legal_name' => 'Renamed Seller SARL', 'tax_identifier' => 'ICE-NEW']];
        $organization->save();
        $html = app(InvoiceDocumentRenderer::class)->html($invoice->fresh());

        $this->assertStringContainsString('BROUILLON', $html);
        $this->assertStringContainsString('Original Seller SARL', $html);
        $this->assertStringNotContainsString('Renamed Seller SARL', $html);
        $this->assertStringContainsString('Consulting', $html);
        $this->assertStringContainsString('01/06/2026', $html);
        $this->assertStringContainsString('100,00', $html);
        $this->assertStringNotContainsString('product_variant_id', $html);

        $issuedHtml = app(InvoiceDocumentRenderer::class)->html($this->issueInvoice($owner, $invoice));
        $this->assertStringContainsString('1/2026', $issuedHtml);
        $this->assertStringNotContainsString('BROUILLON', $issuedHtml);
    }

    public function test_delivery_note_renderer_contains_no_prices_or_invoice_totals(): void
    {
        [$owner, $organization, , $order, $customer] = $this->documentFixture(true);
        $organization->settings = ['document_profile' => ['legal_name' => 'Original Delivery Seller']];
        $organization->save();
        $note = $this->issueDeliveryNote($owner, $this->createDeliveryNote($owner, $order, ['delivery_date' => '2026-06-02']));
        $customer->display_name = 'Changed Recipient';
        $customer->billing_address = 'Changed address';
        $customer->save();
        $organization->settings = ['document_profile' => ['legal_name' => 'Changed Delivery Seller']];
        $organization->save();
        $movementCount = InventoryMovement::query()->count();
        $html = app(DeliveryNoteDocumentRenderer::class)->html($note);

        $this->assertStringContainsString('Consulting', $html);
        $this->assertStringContainsString('02/06/2026', $html);
        $this->assertStringContainsString('Client SARL', $html);
        $this->assertStringContainsString('Original Delivery Seller', $html);
        $this->assertStringNotContainsString('Changed Recipient', $html);
        $this->assertStringNotContainsString('Changed Delivery Seller', $html);
        $this->assertStringNotContainsString('Prix unitaire', $html);
        $this->assertStringNotContainsString('Total TTC', $html);
        $this->assertStringNotContainsString('100,00', $html);
        $this->assertSame($movementCount, InventoryMovement::query()->count());
    }
}
