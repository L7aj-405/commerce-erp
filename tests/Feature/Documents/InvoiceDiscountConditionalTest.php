<?php

namespace Tests\Feature\Documents;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\User;
use App\Services\InvoiceDocumentRenderer;
use Tests\Support\DocumentTestCase;

class InvoiceDiscountConditionalTest extends DocumentTestCase
{
    public function test_invoice_without_any_discount_hides_the_remise_column_and_row(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $invoice = $this->createInvoice($owner, $order);

        $payload = app(InvoiceDocumentRenderer::class)->payload($invoice);
        $this->assertFalse($payload['has_discount']);

        $html = app(InvoiceDocumentRenderer::class)->html($invoice);
        $itemsThead = $this->itemsThead($html);
        $this->assertStringNotContainsString('Remise', $itemsThead);
        $this->assertStringContainsString('Total HT', $html);
        $this->assertStringNotContainsString('Sous-total HT', $html);
    }

    public function test_invoice_with_a_line_discount_shows_the_remise_column_and_row(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);

        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCustomLine($owner, $order, [
            'description' => 'Prestation', 'unit_price_excl_tax' => '1000.0000',
            'discount_type' => 'percentage', 'discount_value' => '10.0000',
        ]);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();

        $invoice = $this->createInvoice($owner, $order);
        $payload = app(InvoiceDocumentRenderer::class)->payload($invoice);
        $this->assertTrue($payload['has_discount']);
        $this->assertNotSame('0.0000', $invoice->discount_total);

        $html = app(InvoiceDocumentRenderer::class)->html($invoice);
        $this->assertStringContainsString('Remise', $this->itemsThead($html));
        $this->assertStringContainsString('Sous-total HT', $html);
    }

    private function itemsThead(string $html): string
    {
        $start = strpos($html, '<table class="items">');
        $end = strpos($html, '</thead>', $start ?: 0);

        return substr($html, (int) $start, (int) $end - (int) $start);
    }
}
