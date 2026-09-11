<?php

namespace Tests\Feature\Documents;

use App\Models\Payment;
use Tests\Support\DocumentTestCase;

class InvoiceRepresentativePaymentMethodTest extends DocumentTestCase
{
    public function test_draft_defaults_representative_to_the_order_creator_and_summarises_order_payment_methods(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(); // total 100.00, created_by = $owner

        $cash = $this->createFinancialAccount($organization, ['code' => 'CASH', 'type' => 'cash']);
        $card = $this->createFinancialAccount($organization, ['code' => 'TPE', 'type' => 'card_clearing', 'name' => 'TPE']);
        $this->recordPayment($owner, $order, $cash, '60.0000', ['method' => 'cash']);
        $this->recordPayment($owner, $order, $card, '40.0000', ['method' => 'card']);

        $invoice = $this->createInvoice($owner, $order);

        $this->assertSame($owner->name, $invoice->representative_name);
        $this->assertSame('ESPÈCES / TPE', $invoice->payment_method_summary);

        // The payment methods drive a display string only — no invoice-level payment is created.
        $this->assertSame(2, Payment::query()->where('organization_id', $organization->id)->count());
    }

    public function test_summary_collapses_duplicate_methods_and_orders_them_canonically(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(false, null, '200.0000');

        $card = $this->createFinancialAccount($organization, ['code' => 'TPE', 'type' => 'card_clearing', 'name' => 'TPE']);
        $cash = $this->createFinancialAccount($organization, ['code' => 'CASH', 'type' => 'cash']);
        $this->recordPayment($owner, $order, $card, '50.0000', ['method' => 'card']);
        $this->recordPayment($owner, $order, $card, '30.0000', ['method' => 'card']);
        $this->recordPayment($owner, $order, $cash, '120.0000', ['method' => 'cash']);

        $invoice = $this->createInvoice($owner, $order);

        $this->assertSame('ESPÈCES / TPE', $invoice->payment_method_summary);
    }

    public function test_draft_variable_fields_can_be_corrected_before_issuance(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $invoice = $this->createInvoice($owner, $order);

        $updated = app(\App\Actions\Documents\UpdateInvoiceDraftAction::class)->execute($owner, $invoice, [
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'representative_name' => 'AGDAY MOHAMED',
            'payment_method_summary' => 'VIREMENT',
        ]);

        $this->assertSame('AGDAY MOHAMED', $updated->representative_name);
        $this->assertSame('VIREMENT', $updated->payment_method_summary);
    }
}
