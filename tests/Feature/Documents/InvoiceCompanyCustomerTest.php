<?php

namespace Tests\Feature\Documents;

use App\Services\InvoiceDocumentRenderer;
use Tests\Support\DocumentTestCase;

class InvoiceCompanyCustomerTest extends DocumentTestCase
{
    public function test_company_billing_identity_is_snapshotted_and_rendered(): void
    {
        $owner = \App\Models\User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $customer = $this->createCustomer($organization, 'SOCIÉTÉ ATLAS', [
            'type' => 'business',
            'company_name' => 'SOCIÉTÉ ATLAS SARL',
            'tax_identifier' => 'ICE-0009988',
            'billing_address' => '45 Boulevard Zerktouni, Casablanca',
            'phone' => '0522334455',
        ]);

        $order = $this->createDraftOrder($owner, $organization, $store, $customer);
        $this->addCustomLine($owner, $order, ['description' => 'Prestation', 'unit_price_excl_tax' => '1000.0000']);
        $order = app(\App\Actions\Sales\ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();

        $invoice = $this->createInvoice($owner, $order);

        $this->assertSame('SOCIÉTÉ ATLAS SARL', $invoice->customer_company);
        $this->assertSame('ICE-0009988', $invoice->customer_tax_identifier);
        $this->assertSame('45 Boulevard Zerktouni, Casablanca', $invoice->billing_address);

        $payload = app(InvoiceDocumentRenderer::class)->payload($invoice);
        $this->assertSame('SOCIÉTÉ ATLAS SARL', $payload['buyer']['company']);
        $this->assertSame('ICE-0009988', $payload['buyer']['tax_identifier']);

        $html = app(InvoiceDocumentRenderer::class)->html($invoice);
        $this->assertStringContainsString('DESTINATAIRE', strtoupper($html));
        $this->assertStringContainsString('SOCIÉTÉ ATLAS SARL', $html);
        $this->assertStringContainsString('ICE-0009988', $html);
        $this->assertStringContainsString('45 Boulevard Zerktouni', $html);
    }
}
