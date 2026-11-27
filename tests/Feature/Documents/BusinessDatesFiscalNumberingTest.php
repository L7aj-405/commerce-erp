<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateFullDeliveryNoteFromSalesOrderAction;
use App\Actions\Quotations\ConvertQuotationToSalesOrderAction;
use App\Actions\Quotations\CreateQuotationAction;
use App\Actions\Returns\CreateCustomerReturnAction;
use App\Actions\Returns\ReceiveCustomerReturnAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\QuotationDocumentSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\QuotationTestCase;

/**
 * F-02 — business dates and fiscal sequence years.
 *
 * Document dates default to the ORGANIZATION'S business date, never to the server's UTC date, and the
 * year-keyed fiscal sequences (invoice `N/YYYY`, credit note `AV-…/YYYY`, quotation `DEV-N/YYYY`, return,
 * exchange) follow the date of the document. Instants (issued_at, audit) stay UTC.
 *
 * The frozen instant is 2025-12-31 23:30:00 UTC. Morocco was on UTC+1 then (Ramadan 2026 had not begun), so
 * it is 2026-01-01 00:30 in Casablanca: the UTC calendar date (2025-12-31) and the Moroccan business date
 * (2026-01-01) differ across a fiscal-year boundary. This instant is deliberately a PAST period of UTC+1 —
 * since 2026-09-20 Morocco is on UTC+0 and 2027-01-01 00:30 is the same date in UTC and in Casablanca, so it
 * cannot reveal the defect (see MoroccoLegalTimeTest for the legal-time facts).
 */
class BusinessDatesFiscalNumberingTest extends QuotationTestCase
{
    private const FROZEN_UTC = '2025-12-31 23:30:00';

    private User $owner;

    private Organization $org;

    private Store $store;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->setTimezone($this->org, 'Africa/Casablanca');
        $this->store = $this->createStore($this->org, $this->owner);
        $this->customer = $this->createCustomer($this->org, 'Client', ['company_name' => 'Client SARL', 'tax_identifier' => 'ICE-1']);
        $this->activate($this->owner, $this->org, $this->store);
        $this->travelTo(Carbon::parse(self::FROZEN_UTC, 'UTC'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    // ---- Invoices -------------------------------------------------------------------------------------

    public function test_invoice_defaults_to_the_business_date_and_numbers_with_the_business_year_not_the_utc_year(): void
    {
        $invoice = $this->issueInvoice($this->owner, $this->createInvoice($this->owner, $this->confirmedOrder('100.0000')));

        $this->assertSame('2026-01-01', $invoice->invoice_date->toDateString(), 'Business date in Casablanca, not the UTC date 2025-12-31.');
        $this->assertSame('1/2026', $invoice->invoice_number, 'The fiscal sequence year follows the invoice date.');
        $this->assertSame(self::FROZEN_UTC, $invoice->issued_at->utc()->format('Y-m-d H:i:s'), 'The issuance INSTANT stays UTC.');
    }

    public function test_the_default_date_never_asks_for_the_backdating_permission(): void
    {
        $employee = User::factory()->create();
        $this->addDefaultSalesEmployee($this->org, $this->store, $employee);
        $this->assertFalse($employee->hasPermission($this->org, 'invoices.backdate'));
        // The business date is "today": allowed without invoices.backdate, implicit or explicit.
        $implicit = $this->createInvoice($employee, $this->confirmedOrder('100.0000'));
        $this->assertSame('2026-01-01', $implicit->invoice_date->toDateString());
        $explicit = $this->createInvoice($employee, $this->confirmedOrder('100.0000'), ['invoice_date' => '2026-01-01']);
        $this->assertSame('2026-01-01', $explicit->invoice_date->toDateString());

        // The UTC calendar date is NOT today in Casablanca: it is a backdated document and needs the permission.
        $this->expectException(HttpException::class);
        $this->createInvoice($employee, $this->confirmedOrder('100.0000'), ['invoice_date' => '2025-12-31']);
    }

    public function test_an_authorized_backdated_invoice_keeps_its_explicit_date_and_its_own_sequence_year(): void
    {
        $invoice = $this->issueInvoice($this->owner, $this->createInvoice($this->owner, $this->confirmedOrder('100.0000'), ['invoice_date' => '2025-06-15']));

        $this->assertSame('2025-06-15', $invoice->invoice_date->toDateString(), 'An explicit authorized date is never replaced by today.');
        $this->assertSame('1/2025', $invoice->invoice_number, 'Year of the DOCUMENT date (existing policy), not of the day it was issued.');
        $this->assertSame(self::FROZEN_UTC, $invoice->issued_at->utc()->format('Y-m-d H:i:s'), 'Audit instant unchanged by backdating.');
    }

    public function test_an_authorized_future_dated_invoice_keeps_its_date_and_numbers_in_that_year(): void
    {
        $invoice = $this->issueInvoice($this->owner, $this->createInvoice($this->owner, $this->confirmedOrder('100.0000'), ['invoice_date' => '2026-03-01']));

        $this->assertSame('2026-03-01', $invoice->invoice_date->toDateString());
        $this->assertSame('1/2026', $invoice->invoice_number);
    }

    public function test_numbering_is_independent_per_organization_and_follows_each_organizations_timezone(): void
    {
        // Same instant, three organizations: Casablanca (UTC+1 then) -> 2026-01-01, New York -> 2025-12-31, unconfigured -> UTC.
        $newYork = $this->secondOrganization('America/New_York');
        $unconfigured = $this->secondOrganization(null);

        $ma = $this->issueInvoice($this->owner, $this->createInvoice($this->owner, $this->confirmedOrder('100.0000')));
        $ny = $this->issueInvoice($newYork['owner'], $this->createInvoice($newYork['owner'], $this->confirmedOrderFor($newYork, '100.0000')));
        $utc = $this->issueInvoice($unconfigured['owner'], $this->createInvoice($unconfigured['owner'], $this->confirmedOrderFor($unconfigured, '100.0000')));

        $this->assertSame(['2026-01-01', '1/2026'], [$ma->invoice_date->toDateString(), $ma->invoice_number]);
        $this->assertSame(['2025-12-31', '1/2025'], [$ny->invoice_date->toDateString(), $ny->invoice_number], 'A New York organization is still on 31 December.');
        $this->assertSame(['2025-12-31', '1/2025'], [$utc->invoice_date->toDateString(), $utc->invoice_number], 'An unconfigured organization keeps UTC; Morocco is never imposed.');
        $this->assertSame(1, Invoice::query()->withoutGlobalScopes()->where('invoice_number', '1/2026')->count(), 'No cross-organization collision in the 2026 sequence.');
    }

    public function test_issuing_a_document_does_not_change_any_amount(): void
    {
        $order = $this->confirmedOrder('123.4500');
        $invoice = $this->issueInvoice($this->owner, $this->createInvoice($this->owner, $order));

        $this->assertSame('123.4500', $invoice->total_incl_tax);
        $this->assertSame($order->fresh()->total_incl_tax, $invoice->total_incl_tax);
    }

    // ---- Delivery notes -------------------------------------------------------------------------------

    public function test_delivery_note_defaults_to_the_business_date(): void
    {
        $note = app(CreateFullDeliveryNoteFromSalesOrderAction::class)->execute($this->owner, $this->confirmedOrder('100.0000'));
        $this->assertSame('2026-01-01', $note->delivery_date->toDateString());

        $issued = $this->issueDeliveryNote($this->owner, $note);
        $this->assertSame('2026-01-01', $issued->delivery_date->toDateString());
        $this->assertNotNull($issued->delivery_note_number, 'Delivery-note numbering is a year-less sequence; only its date is business-date driven.');
    }

    // ---- Quotations & sales orders --------------------------------------------------------------------

    public function test_quotation_defaults_to_the_business_date_and_numbers_with_its_year(): void
    {
        $quotation = app(CreateQuotationAction::class)->execute($this->owner, $this->org, $this->store, ['currency_code' => 'MAD']);
        $this->assertSame('2026-01-01', $quotation->quotation_date->toDateString());
        $validityDays = (int) app(QuotationDocumentSettings::class)->settings($this->org)['default_validity_days'];
        $this->assertSame(Carbon::parse('2026-01-01')->addDays($validityDays)->toDateString(), $quotation->valid_until->toDateString(), 'Validity counts from the business date.');

        $this->addNonStockQuotationLine($this->owner, $quotation);
        $issued = $this->issueQuotation($this->owner, $quotation);
        $this->assertSame('DEV-1/2026', $issued->quotation_number);
    }

    public function test_converting_a_quotation_dates_the_sales_order_with_the_business_date(): void
    {
        $variant = $this->createProduct($this->org, 'Micro', 'MIC-F02', ['default_sale_price' => '500.0000'])->variants->firstOrFail();
        $warehouse = $this->createWarehouse($this->org);
        $this->openStock($this->owner, $this->org, $warehouse, $variant, '5.0000');
        $quotation = app(CreateQuotationAction::class)->execute($this->owner, $this->org, $this->store, ['currency_code' => 'MAD']);
        $this->addCatalogQuotationLine($this->owner, $quotation, $variant);
        $accepted = $this->acceptQuotation($this->owner, $this->issueQuotation($this->owner, $quotation));

        $order = app(ConvertQuotationToSalesOrderAction::class)->execute($this->owner, $accepted, ['warehouse_id' => $warehouse->getKey()])['order'];

        $this->assertSame('2026-01-01', $order->sale_date->toDateString(), 'Not the UTC date 2025-12-31.');
    }

    public function test_sales_order_business_date_is_the_explicit_date_it_was_given(): void
    {
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $this->customer, ['sale_date' => '2025-11-20']);

        $this->assertSame('2025-11-20', $order->sale_date->toDateString(), 'An explicit sale date is never rewritten to today.');
    }

    // ---- Returns, credit notes, exchanges -------------------------------------------------------------

    public function test_credit_note_and_return_use_the_business_date_and_year(): void
    {
        $order = $this->invoicedFulfilledCatalogOrder('2.0000');

        $return = app(CreateCustomerReturnAction::class)->execute($this->owner, $order->fresh(),
            [['sales_order_line_id' => $order->lines()->first()->getKey(), 'quantity' => '1']], 'Retour client', 'restock', (string) Str::uuid());
        $draft = CreditNote::query()->where('customer_return_id', $return->getKey())->sole();
        $this->assertSame('2026-01-01', $draft->credit_note_date->toDateString(), 'Draft credit note: business date.');
        $this->assertSame('RET-00001/2026', $return->return_number, 'Return sequence year = business year, not the UTC year.');

        app(ReceiveCustomerReturnAction::class)->execute($this->owner, $return);

        $note = $draft->fresh();
        $this->assertSame('issued', $note->status);
        $this->assertSame('2026-01-01', $note->credit_note_date->toDateString());
        $this->assertSame('AV-00001/2026', $note->credit_note_number);
        $this->assertSame(self::FROZEN_UTC, $note->issued_at->utc()->format('Y-m-d H:i:s'), 'issued_at is an instant: UTC.');
    }

    // ---- Instants vs dates ----------------------------------------------------------------------------

    public function test_date_only_values_are_stored_as_plain_calendar_dates_without_an_off_by_one(): void
    {
        $invoice = $this->createInvoice($this->owner, $this->confirmedOrder('100.0000'));

        $this->assertSame('2026-01-01', $invoice->fresh()->invoice_date->toDateString());
        $this->assertStringStartsWith('2026-01-01', (string) DB::table('invoices')->where('id', $invoice->getKey())->value('invoice_date'));
    }

    // ---------------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------------

    private function setTimezone(Organization $organization, ?string $timezone): void
    {
        $settings = $organization->settings ?? [];
        if ($timezone === null) {
            unset($settings['timezone']);
        } else {
            $settings['timezone'] = $timezone;
        }
        $organization->settings = $settings;
        $organization->save();
    }

    private function confirmedOrder(string $total): SalesOrder
    {
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $this->customer, ['sale_date' => '2025-12-30']);
        $this->addCustomLine($this->owner, $order, ['description' => 'Prestation', 'unit_price_excl_tax' => $total]);

        return app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
    }

    /** @return array{owner: User, org: Organization, store: Store, customer: Customer} */
    private function secondOrganization(?string $timezone): array
    {
        $owner = User::factory()->create();
        $org = $this->createOrganization($owner, 'Autre société '.Str::random(4));
        $this->setTimezone($org, $timezone);
        $store = $this->createStore($org, $owner, 'Boutique');
        $customer = $this->createCustomer($org, 'Client B');
        $this->activate($owner, $org, $store);

        return ['owner' => $owner, 'org' => $org, 'store' => $store, 'customer' => $customer];
    }

    /** @param array{owner: User, org: Organization, store: Store, customer: Customer} $world */
    private function confirmedOrderFor(array $world, string $total): SalesOrder
    {
        $this->activate($world['owner'], $world['org'], $world['store']);
        $order = $this->createDraftOrder($world['owner'], $world['org'], $world['store'], $world['customer'], ['sale_date' => '2025-12-30']);
        $this->addCustomLine($world['owner'], $order, ['description' => 'Prestation', 'unit_price_excl_tax' => $total]);

        return app(ConfirmSalesOrderAction::class)->execute($world['owner'], $order)->fresh();
    }

    private function invoicedFulfilledCatalogOrder(string $quantity): SalesOrder
    {
        $settings = $this->org->settings ?? [];
        $settings['return_policy'] = ['enabled' => true, 'window_value' => 7, 'window_unit' => 'days', 'window_minutes' => 10080,
            'allow_partial' => true, 'allow_full' => true, 'manager_override_allowed' => false, 'require_reason' => true, 'default_disposition' => 'restock'];
        $this->org->settings = $settings;
        $this->org->save();
        $warehouse = $this->createWarehouse($this->org);
        $variant = $this->createProduct($this->org, 'Produit retourné', 'F02-1', ['default_sale_price' => '100.0000'])->variants->first();
        $this->openStock($this->owner, $this->org, $warehouse, $variant, '10.0000');

        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $this->customer, ['sale_date' => '2025-12-30']);
        $this->addCatalogLine($this->owner, $order, $variant, $warehouse, ['quantity' => $quantity]);
        $order = app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
        $order = app(FulfillSalesOrderAction::class)->execute($this->owner, $order)->fresh();
        // Returns restock into the order's return warehouse, which the POS flow records; set it for this non-POS fixture.
        DB::table('sales_orders')->where('id', $order->getKey())->update(['pos_warehouse_id' => $warehouse->getKey()]);
        $this->issueInvoice($this->owner, $this->createInvoice($this->owner, $order->fresh()));

        return $order->fresh();
    }
}
