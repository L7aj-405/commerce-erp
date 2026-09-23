<?php

namespace Tests\Support;

use App\Actions\Documents\CreateFullDeliveryNoteFromSalesOrderAction;
use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Documents\IssueDeliveryNoteAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;

abstract class DocumentTestCase extends PaymentTestCase
{
    /** @return array{User, Organization, Store, SalesOrder, ?Customer} */
    protected function documentFixture(bool $fulfilled = false, ?Customer $customer = null, string $total = '100.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $customer ??= $this->createCustomer($organization, 'Client', [
            'company_name' => 'Client SARL', 'email' => 'billing@example.test', 'phone' => '0600000000',
            'tax_identifier' => 'ICE-123', 'billing_address' => '1 Commerce Street',
        ]);
        $order = $this->createDraftOrder($owner, $organization, $store, $customer, ['sale_date' => '2026-05-29']);
        $this->addCustomLine($owner, $order, ['description' => 'Consulting', 'unit_price_excl_tax' => $total]);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();
        if ($fulfilled) {
            $order = app(FulfillSalesOrderAction::class)->execute($owner, $order)->fresh();
        }

        return [$owner, $organization, $store, $order, $customer];
    }

    protected function createInvoice(User $actor, SalesOrder $order, array $data = []): Invoice
    {
        return app(CreateFullInvoiceFromSalesOrderAction::class)->execute($actor, $order, $data);
    }

    protected function issueInvoice(User $actor, Invoice $invoice): Invoice
    {
        $remaining = app(SalesOrderPaymentCalculator::class)->remainingAmount($invoice->salesOrder);
        if (Decimal::compare($remaining, '0.0000') > 0) {
            $account = $this->createFinancialAccount($invoice->organization);
            $this->recordPayment($actor, $invoice->salesOrder, $account, $remaining);
        }

        return app(IssueInvoiceAction::class)->execute($actor, $invoice)->fresh();
    }

    protected function createDeliveryNote(User $actor, SalesOrder $order, array $data = []): DeliveryNote
    {
        return app(CreateFullDeliveryNoteFromSalesOrderAction::class)->execute($actor, $order, $data);
    }

    protected function issueDeliveryNote(User $actor, DeliveryNote $note): DeliveryNote
    {
        return app(IssueDeliveryNoteAction::class)->execute($actor, $note)->fresh();
    }
}
