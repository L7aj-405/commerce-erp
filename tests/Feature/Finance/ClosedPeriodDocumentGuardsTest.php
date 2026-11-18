<?php

namespace Tests\Feature\Finance;

use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Finance\ManageFinanceMonthReviewAction;
use App\Actions\Finance\SetFinancialAccountOpeningPositionAction;
use App\Actions\Returns\CreateCustomerReturnAction;
use App\Actions\Returns\ReceiveCustomerReturnAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountOpeningPosition;
use App\Models\Invoice;
use App\Models\InvoiceFamily;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\FinancePeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DocumentTestCase;

/**
 * Phase 1 / Step 4 (F-03) — a closed month refuses invoice issuance, credit-note creation/issuance and
 * opening-position changes dated inside it. The existing policy (FinanceClosedPeriodGuard) is applied as is:
 * business date of the organization, server-side, inside the transaction, no silent override.
 *
 * Frozen clock: 2026-11-15 10:00 UTC (Morocco is on UTC+0 then). September 2026 is the "closed past month".
 */
class ClosedPeriodDocumentGuardsTest extends DocumentTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 10:00:00', 'UTC'));
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->setTimezone($this->org, 'Africa/Casablanca');
        $this->store = $this->createStore($this->org, $this->owner);
        $this->customer = $this->createCustomer($this->org, 'Client', ['company_name' => 'Client SARL', 'tax_identifier' => 'ICE-1']);
        $this->activate($this->owner, $this->org, $this->store);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    // ---- 1 & 2. Invoice issuance ---------------------------------------------------------------------

    public function test_invoice_issuance_in_an_open_period_is_allowed_even_when_another_month_is_closed(): void
    {
        $this->markClosed($this->org, '2026-09');

        $today = $this->issue($this->paidInvoice());
        $october = $this->issue($this->paidInvoice('2026-10-12'));

        $this->assertSame('issued', $today->status->value);
        $this->assertSame('2026-10-12', $october->invoice_date->toDateString());
    }

    public function test_invoice_issuance_in_a_closed_period_is_refused_with_no_partial_write_then_allowed_after_reopening(): void
    {
        $invoice = $this->paidInvoice('2026-09-20');
        $order = $invoice->salesOrder;
        $familyBefore = InvoiceFamily::query()->whereKey($invoice->invoice_family_id)->value('canonical_invoice_number');
        $auditBefore = DB::table('audit_logs')->where('event', 'invoice.issued')->count();
        $this->closeForReal('2026-09');

        $this->assertClosedRefusal(fn () => $this->issue($invoice), 'invoice_date');

        $fresh = $invoice->fresh();
        $this->assertSame('draft', $fresh->status->value);
        $this->assertNull($fresh->invoice_number);
        $this->assertNull($fresh->issued_at);
        $this->assertSame('2026-09-20', $fresh->invoice_date->toDateString(), 'The date is never silently moved.');
        $this->assertSame($familyBefore, InvoiceFamily::query()->whereKey($invoice->invoice_family_id)->value('canonical_invoice_number'), 'No number was consumed.');
        $this->assertSame($auditBefore, DB::table('audit_logs')->where('event', 'invoice.issued')->count());
        $this->assertSame($invoice->total_incl_tax, $fresh->total_incl_tax, 'Money amounts untouched.');
        $this->assertSame($order->total_incl_tax, $order->fresh()->total_incl_tax);
        $this->assertSame(0, Invoice::query()->where('status', 'issued')->count());

        // Correction path = reopen (audited, reason), then the very same draft issues with its own date.
        app(ManageFinanceMonthReviewAction::class)->reopen($this->owner, $this->org->fresh(), FinancePeriod::fromMonth('2026-09'), 'Facture oubliée');
        $issued = $this->issue($invoice->fresh());
        $this->assertSame('2026-09-20', $issued->invoice_date->toDateString());
        $this->assertSame('1/2026', $issued->invoice_number);
    }

    public function test_explicit_backdating_into_a_closed_month_is_refused_but_into_an_open_past_month_is_allowed(): void
    {
        $this->markClosed($this->org, '2026-09');

        $this->assertClosedRefusal(fn () => $this->issue($this->paidInvoice('2026-09-30')), 'invoice_date');
        $this->assertSame('2026-10-01', $this->issue($this->paidInvoice('2026-10-01'))->invoice_date->toDateString());
    }

    public function test_the_guard_is_organization_local_a_closed_month_of_another_organization_never_blocks(): void
    {
        [$ownerB, $orgB] = $this->documentFixture();
        $this->markClosed($orgB, '2026-09');

        $this->assertSame('issued', $this->issue($this->paidInvoice('2026-09-20'))->status->value);
    }

    public function test_test_mode_invoices_belong_to_no_production_month(): void
    {
        $this->actingAs($this->owner);
        $this->markClosed($this->org, '2026-09');   // a PRODUCTION closing

        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();
        $this->get('/test-mode')->assertOk();   // resolves the Test context for direct action calls
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $this->customer);
        $this->addCustomLine($this->owner, $order, ['description' => 'Test service', 'unit_price_excl_tax' => '50.0000']);
        $order = $order->fresh();
        $this->assertNotNull($order->sandbox_id);
        $draft = $this->createInvoice($this->owner, $order, ['invoice_date' => '2026-09-20']);
        $this->assertNotNull($draft->sandbox_id);

        $issued = $this->issue($draft);

        $this->assertSame('issued', $issued->status->value, 'Production closings never block Test Mode documents.');
        $this->assertSame('2026-09-20', $issued->invoice_date->toDateString());
    }

    // ---- 7. Business-date boundary and organization timezone -----------------------------------------

    public function test_the_period_is_looked_up_with_the_organization_business_date_not_the_utc_date(): void
    {
        // 2025-12-31 23:30 UTC = 2026-01-01 00:30 in Casablanca (UTC+1 then) = 2025-12-31 18:30 in New York.
        $this->travelTo(Carbon::parse('2025-12-31 23:30:00', 'UTC'));
        $newYork = $this->secondOrganization('America/New_York');
        // Prepare everything (payments are dated today and would themselves be refused once December is closed).
        $maDraft = $this->paidInvoice();
        $nyOrder = $this->confirmedOrderFor($newYork, '100.0000');
        $this->payFor($newYork['owner'], $nyOrder);
        $nyDraft = $this->createInvoice($newYork['owner'], $nyOrder);
        $this->markClosed($this->org, '2025-12');
        $this->markClosed($newYork['org'], '2025-12');

        // Casablanca: the business date is 2026-01-01 → December being closed does NOT matter.
        $this->assertSame('2026-01-01', $maDraft->invoice_date->toDateString());
        $this->assertSame('issued', $this->issue($maDraft)->status->value);

        // New York: the business date is still 2025-12-31 → closed December refuses the default-dated invoice.
        $this->assertSame('2025-12-31', $nyDraft->invoice_date->toDateString());
        $this->assertClosedRefusal(fn () => app(IssueInvoiceAction::class)->execute($newYork['owner'], $nyDraft), 'invoice_date');
    }

    // ---- 10. Permissions ------------------------------------------------------------------------------

    public function test_authorization_is_checked_before_and_independently_of_the_period(): void
    {
        $invoice = $this->paidInvoice('2026-09-20');
        $this->markClosed($this->org, '2026-09');
        $viewer = $this->userWithPermissions(['invoices.view', 'sales_orders.view']);

        try {
            app(IssueInvoiceAction::class)->execute($viewer, $invoice);
            $this->fail('An unauthorized user must be refused.');
        } catch (HttpException $e) {
            $this->assertContains($e->getStatusCode(), [403, 404], 'Refused by authorization, not by the period rule.');
        }
        $this->assertSame('draft', $invoice->fresh()->status->value);
    }

    // ---- 4. Credit notes ------------------------------------------------------------------------------

    public function test_credit_note_creation_and_issuance_in_an_open_period_are_allowed_when_another_month_is_closed(): void
    {
        $order = $this->invoicedFulfilledCatalogOrder('2.0000');
        $this->markClosed($this->org, '2026-09');

        $return = $this->createReturn($order, '1');
        $note = CreditNote::query()->where('customer_return_id', $return->id)->sole();
        $this->assertSame('draft', $note->status);

        app(ReceiveCustomerReturnAction::class)->execute($this->owner, $return);
        $this->assertSame('issued', $note->fresh()->status);
        $this->assertSame('AV-00001/2026', $note->fresh()->credit_note_number);
    }

    public function test_credit_note_creation_in_a_closed_current_month_is_refused_with_no_partial_write(): void
    {
        $order = $this->invoicedFulfilledCatalogOrder('2.0000');
        $this->markClosed($this->org, '2026-11');   // simulated: the current month cannot be closed through the UI

        $this->assertClosedRefusal(fn () => $this->createReturn($order, '1'), 'credit_note_date');

        $this->assertSame(0, DB::table('customer_returns')->count());
        $this->assertSame(0, DB::table('credit_notes')->count());
        $this->assertSame(0, DB::table('credit_note_lines')->count());
    }

    public function test_receiving_a_return_whose_credit_note_would_fall_in_a_closed_month_is_refused_with_no_partial_write(): void
    {
        $order = $this->invoicedFulfilledCatalogOrder('2.0000');
        $return = $this->createReturn($order, '1');
        $stockBefore = DB::table('inventory_balances')->sum('on_hand');
        $movementsBefore = DB::table('inventory_movements')->count();
        DB::table('finance_month_reviews')->insert(['organization_id' => $this->org->id, 'period' => '2026-11-01', 'status' => 'closed',
            'closed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertClosedRefusal(fn () => app(ReceiveCustomerReturnAction::class)->execute($this->owner, $return), 'credit_note_date');

        $this->assertSame('draft', $return->fresh()->status, 'The reception is all-or-nothing: the return stays a draft.');
        $note = CreditNote::query()->where('customer_return_id', $return->id)->sole();
        $this->assertSame('draft', $note->status);
        $this->assertNull($note->credit_note_number);
        $this->assertEquals($stockBefore, DB::table('inventory_balances')->sum('on_hand'), 'No stock was moved.');
        $this->assertSame($movementsBefore, DB::table('inventory_movements')->count());

        DB::table('finance_month_reviews')->where('organization_id', $this->org->id)->update(['status' => 'open']);
        app(ReceiveCustomerReturnAction::class)->execute($this->owner, $return->fresh());
        $this->assertSame('issued', $note->fresh()->status);
    }

    public function test_a_return_without_an_invoice_has_no_credit_note_and_is_not_blocked_by_the_period(): void
    {
        // No accounting document is produced, so the closed-period rule has nothing to protect.
        $order = $this->unInvoicedFulfilledCatalogOrder('1.0000');
        $this->markClosed($this->org, '2026-11');

        $return = $this->createReturn($order, '1');

        $this->assertSame(0, CreditNote::query()->where('customer_return_id', $return->id)->count());
    }

    // ---- 5. Opening positions -------------------------------------------------------------------------

    public function test_opening_position_changes_in_a_closed_month_are_refused_in_both_directions(): void
    {
        $account = $this->createFinancialAccount($this->org, ['name' => 'Banque', 'code' => 'BANK']);
        $set = fn (string $date, string $amount = '500.0000') => app(SetFinancialAccountOpeningPositionAction::class)->execute(
            $this->owner, $account, ['effective_date' => $date, 'amount' => $amount, 'currency_code' => 'MAD', 'reference' => null, 'notes' => null]);

        $set('2026-10-01');                                   // open month: allowed
        $this->markClosed($this->org, '2026-09');
        $this->markClosed($this->org, '2026-10');

        // Editing a position that currently sits in a closed month is refused…
        $this->assertClosedRefusal(fn () => $set('2026-11-01', '900.0000'), 'effective_date');
        // …and so is moving one INTO a closed month (new account, nothing exists yet).
        $other = $this->createFinancialAccount($this->org, ['name' => 'Caisse 2', 'code' => 'C2']);
        $this->assertClosedRefusal(fn () => app(SetFinancialAccountOpeningPositionAction::class)->execute(
            $this->owner, $other, ['effective_date' => '2026-09-01', 'amount' => '10.0000', 'currency_code' => 'MAD']), 'effective_date');

        $this->assertSame('500.0000', (string) FinancialAccountOpeningPosition::query()->withoutGlobalScopes()->where('financial_account_id', $account->id)->value('amount'), 'No partial write.');
        $this->assertSame(0, FinancialAccountOpeningPosition::query()->withoutGlobalScopes()->where('financial_account_id', $other->id)->count());

        // A November position (open) is fine.
        $set2 = app(SetFinancialAccountOpeningPositionAction::class)->execute(
            $this->owner, $other, ['effective_date' => '2026-11-02', 'amount' => '10.0000', 'currency_code' => 'MAD']);
        $this->assertSame('2026-11-02', $set2->effective_date->toDateString());
    }

    public function test_opening_position_still_needs_its_own_permission(): void
    {
        $account = $this->createFinancialAccount($this->org, ['name' => 'Banque', 'code' => 'BANK']);
        $user = $this->userWithPermissions(['financial_accounts.view']);

        $this->expectException(HttpException::class);
        app(SetFinancialAccountOpeningPositionAction::class)->execute($user, $account, ['effective_date' => '2026-11-02', 'amount' => '1.0000', 'currency_code' => 'MAD']);
    }

    // ---- 12. Ledger consistency -----------------------------------------------------------------------

    public function test_a_refused_issuance_leaves_the_receivable_and_billing_totals_unchanged(): void
    {
        $issued = $this->issue($this->paidInvoice());
        $totalBefore = DB::table('invoices')->where('status', 'issued')->sum('total_incl_tax');
        $draft = $this->paidInvoice('2026-09-20');
        $this->markClosed($this->org, '2026-09');

        $this->assertClosedRefusal(fn () => $this->issue($draft), 'invoice_date');

        $this->assertEquals($totalBefore, DB::table('invoices')->where('status', 'issued')->sum('total_incl_tax'));
        $this->assertSame(1, DB::table('invoices')->where('status', 'issued')->count());
        $this->assertNotNull($issued);
    }

    // ---------------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------------

    private function issue(Invoice $invoice): Invoice
    {
        return app(IssueInvoiceAction::class)->execute($this->owner, $invoice)->fresh();
    }

    /** A DRAFT invoice whose order is fully paid (the existing payment-before-issue policy is untouched). */
    private function paidInvoice(?string $invoiceDate = null): Invoice
    {
        $order = $this->confirmedOrder('100.0000');
        $this->recordPayment($this->owner, $order, $this->cashAccount(), '100.0000');

        return $this->createInvoice($this->owner, $order->fresh(), $invoiceDate ? ['invoice_date' => $invoiceDate] : []);
    }

    private function cashAccount(): FinancialAccount
    {
        return FinancialAccount::query()->withoutGlobalScopes()->where('organization_id', $this->org->id)->where('code', 'CAISSE')->first()
            ?? $this->createFinancialAccount($this->org, ['name' => 'Caisse', 'code' => 'CAISSE']);
    }

    private function confirmedOrder(string $total): SalesOrder
    {
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $this->customer, ['sale_date' => '2026-05-29']);
        $this->addCustomLine($this->owner, $order, ['description' => 'Prestation', 'unit_price_excl_tax' => $total]);

        return app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
    }

    private function markClosed(Organization $organization, string $month): void
    {
        DB::table('finance_month_reviews')->insert([
            'organization_id' => $organization->id, 'period' => $month.'-01', 'status' => 'closed',
            'closed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function closeForReal(string $month): void
    {
        app(ManageFinanceMonthReviewAction::class)->close($this->owner, $this->org->fresh(), FinancePeriod::fromMonth($month), 'Clôture', true);
    }

    private function assertClosedRefusal(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail('Expected the closed period to refuse the operation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
            $this->assertStringContainsString('est clôturée', $e->errors()[$field][0]);
            $this->assertStringContainsString('réouvrez', $e->errors()[$field][0], 'The message names the correction path.');
        }
    }

    /** @param list<string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $role = $this->createRole($this->org, $permissions, 'Limité');
        $membership = new OrganizationMembership;
        $membership->organization_id = $this->org->getKey();
        $membership->user_id = $user->getKey();
        $membership->role_id = $role->getKey();
        $membership->status = 'active';
        $membership->save();
        $this->addStoreMember($this->store, $user);
        $this->activate($user, $this->org, $this->store);

        return $user;
    }

    /** @return array{owner: User, org: Organization, store: Store, customer: Customer} */
    private function secondOrganization(?string $timezone): array
    {
        $owner = User::factory()->create();
        $org = $this->createOrganization($owner, 'Org '.Str::random(4));
        $this->setTimezone($org, $timezone);
        $store = $this->createStore($org, $owner);
        $customer = $this->createCustomer($org, 'Client B', ['company_name' => 'B SARL', 'tax_identifier' => 'ICE-B']);
        $this->activate($owner, $org, $store);
        $this->createFinancialAccount($org, ['name' => 'Caisse', 'code' => 'CAISSE']);
        $this->activate($this->owner, $this->org, $this->store);

        return compact('owner', 'org', 'store', 'customer');
    }

    /** @param array{owner: User, org: Organization, store: Store, customer: Customer} $w */
    private function confirmedOrderFor(array $w, string $total): SalesOrder
    {
        $order = $this->createDraftOrder($w['owner'], $w['org'], $w['store'], $w['customer'], ['sale_date' => '2026-05-29']);
        $this->addCustomLine($w['owner'], $order, ['description' => 'Prestation', 'unit_price_excl_tax' => $total]);

        return app(ConfirmSalesOrderAction::class)->execute($w['owner'], $order)->fresh();
    }

    private function payFor(User $owner, SalesOrder $order): void
    {
        $account = FinancialAccount::query()->withoutGlobalScopes()->where('organization_id', $order->organization_id)->firstOrFail();
        $this->recordPayment($owner, $order->fresh(), $account, (string) $order->total_incl_tax);
    }

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

    private function enableReturns(): void
    {
        $settings = $this->org->settings ?? [];
        $settings['return_policy'] = ['enabled' => true, 'window_value' => 7, 'window_unit' => 'days', 'window_minutes' => 10080,
            'allow_partial' => true, 'allow_full' => true, 'manager_override_allowed' => false, 'require_reason' => true, 'default_disposition' => 'restock'];
        $this->org->settings = $settings;
        $this->org->save();
    }

    private function fulfilledCatalogOrder(string $quantity): SalesOrder
    {
        $this->enableReturns();
        $warehouse = $this->createWarehouse($this->org);
        $variant = $this->createProduct($this->org, 'Produit retourné', 'F03-1'.Str::random(3), ['default_sale_price' => '100.0000'])->variants->first();
        $this->openStock($this->owner, $this->org, $warehouse, $variant, '10.0000');
        $order = $this->createDraftOrder($this->owner, $this->org, $this->store, $this->customer, ['sale_date' => now()->toDateString()]);
        $this->addCatalogLine($this->owner, $order, $variant, $warehouse, ['quantity' => $quantity]);
        $order = app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
        $order = app(FulfillSalesOrderAction::class)->execute($this->owner, $order)->fresh();
        DB::table('sales_orders')->where('id', $order->getKey())->update(['pos_warehouse_id' => $warehouse->getKey()]);

        return $order->fresh();
    }

    private function unInvoicedFulfilledCatalogOrder(string $quantity): SalesOrder
    {
        return $this->fulfilledCatalogOrder($quantity);
    }

    private function invoicedFulfilledCatalogOrder(string $quantity): SalesOrder
    {
        $order = $this->fulfilledCatalogOrder($quantity);
        $this->issueInvoice($this->owner, $this->createInvoice($this->owner, $order));

        return $order->fresh();
    }

    private function createReturn(SalesOrder $order, string $quantity)
    {
        return app(CreateCustomerReturnAction::class)->execute($this->owner, $order->fresh(),
            [['sales_order_line_id' => $order->lines()->first()->getKey(), 'quantity' => $quantity]], 'Retour client', 'restock', (string) Str::uuid());
    }
}
