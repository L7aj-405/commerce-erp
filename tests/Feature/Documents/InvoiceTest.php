<?php

namespace Tests\Feature\Documents;

use App\Actions\Sales\CancelSalesOrderAction;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\Support\DocumentTestCase;

class InvoiceTest extends DocumentTestCase
{
    public function test_draft_copies_full_sales_and_customer_snapshots_with_independent_date(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $invoice = $this->createInvoice($owner, $order, ['invoice_date' => '2026-06-02', 'notes' => 'Draft review']);
        $line = $invoice->lines->first();
        $source = $order->lines()->firstOrFail();
        $this->assertNull($invoice->invoice_number);
        $this->assertSame('draft', $invoice->status->value);
        $this->assertSame('2026-06-02', $invoice->invoice_date->toDateString());
        $this->assertSame('2026-05-29', $order->sale_date->toDateString());
        $this->assertSame('Client SARL', $invoice->customer_company);
        $this->assertSame('ICE-123', $invoice->customer_tax_identifier);
        foreach (['quantity', 'unit_price_excl_tax', 'subtotal_excl_tax', 'discount_amount', 'tax_rate', 'tax_amount', 'total_incl_tax'] as $field) {
            $this->assertSame($source->{$field}, $line->{$field});
        }
        $this->assertSame($order->total_incl_tax, $invoice->total_incl_tax);
    }

    public function test_draft_and_cancelled_sales_orders_are_rejected(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $draft = $this->createDraftOrder($owner, $organization, $store);
        $this->addCustomLine($owner, $draft);
        foreach ([$draft, app(CancelSalesOrderAction::class)->execute($owner, $draft->fresh())] as $order) {
            try {
                $this->createInvoice($owner, $order->fresh());
                $this->fail('Expected ineligible Sales Order rejection.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('invoices', 0);
            }
        }
    }

    public function test_paid_order_can_be_invoiced_without_payment_mutation(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture();
        $account = $this->createFinancialAccount($organization);
        $payment = $this->recordPayment($owner, $order, $account, '100.0000', ['payment_date' => '2026-07-05']);
        $allocation = PaymentAllocation::query()->firstOrFail();
        $invoice = $this->issueInvoice($owner, $this->createInvoice($owner, $order, ['invoice_date' => '2026-06-02']));
        $this->assertSame('issued', $invoice->status->value);
        $this->assertSame('2026-07-05', $payment->fresh()->payment_date->toDateString());
        $this->assertSame('100.0000', $allocation->fresh()->amount);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_sale_invoice_delivery_and_payment_business_dates_can_all_differ(): void
    {
        [$owner, $organization, , $order] = $this->documentFixture(true);
        $account = $this->createFinancialAccount($organization);
        $payment = $this->recordPayment($owner, $order, $account, '100.0000', ['payment_date' => '2026-07-05']);
        $invoice = $this->createInvoice($owner, $order, ['invoice_date' => '2026-06-02']);
        $note = $this->createDeliveryNote($owner, $order, ['delivery_date' => '2026-06-04']);
        $this->assertSame('2026-05-29', $order->sale_date->toDateString());
        $this->assertSame('2026-06-02', $invoice->invoice_date->toDateString());
        $this->assertSame('2026-06-04', $note->delivery_date->toDateString());
        $this->assertSame('2026-07-05', $payment->payment_date->toDateString());
    }

    public function test_confirmed_orders_are_invoice_eligible_before_or_after_fulfillment(): void
    {
        [$ownerA, , , $unfulfilled] = $this->documentFixture();
        $this->assertSame('draft', $this->createInvoice($ownerA, $unfulfilled)->status->value);
        [$ownerB, , , $fulfilled] = $this->documentFixture(true);
        $this->assertSame('draft', $this->createInvoice($ownerB, $fulfilled)->status->value);
    }

    public function test_invoice_creation_does_not_mutate_sales_totals_or_lines(): void
    {
        [$owner, , , $order] = $this->documentFixture();
        $before = $order->fresh()->only(['subtotal_excl_tax', 'discount_total', 'tax_total', 'total_incl_tax']);
        $lineBefore = $order->lines()->firstOrFail()->getAttributes();
        $this->createInvoice($owner, $order);
        $this->assertSame($before, $order->fresh()->only(array_keys($before)));
        $this->assertSame($lineBefore, $order->lines()->firstOrFail()->getAttributes());
    }
}
