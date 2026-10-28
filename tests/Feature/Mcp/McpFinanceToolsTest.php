<?php

namespace Tests\Feature\Mcp;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Enums\InvoiceStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\McpIdempotencyRecord;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Mcp\Finance\FinanceTools;
use App\Services\Mcp\McpConnectionCredentials;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\McpIdempotencyService;
use App\Services\Mcp\Sales\SalesAssistantTools;
use App\Services\Mcp\Sales\SalesOrderTools;
use Carbon\CarbonImmutable;
use Tests\Support\QuotationTestCase;

/** MCP-W7: invoices and payments — canonical actions, scope ∩ ERP permission ∩ store. */
class McpFinanceToolsTest extends QuotationTestCase
{
    private const SCOPES = ['invoices.view', 'invoices.issue', 'payments.view', 'payments.create', 'sales_orders.view', 'customers.view'];

    private User $owner;

    private Organization $organization;

    private Store $storeA;

    private Store $storeB;

    private Customer $customer;

    private FinancialAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00'));

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->organization = $this->createOrganization($this->owner);
        $this->organization->settings = ['allow_mcp_connections' => true];
        $this->organization->save();
        $this->organization->refresh();
        $this->storeA = $this->createStore($this->organization, $this->owner, 'Store A');
        $this->storeB = $this->createStore($this->organization, $this->owner, 'Store B');
        $this->customer = $this->createCustomer($this->organization, 'Client X', ['company_name' => 'CLIENT X SARL', 'tax_identifier' => 'ICE-1', 'billing_address' => 'Rue 1']);
        $this->bank = $this->createFinancialAccount($this->organization, ['type' => 'bank', 'name' => 'Banque', 'code' => 'BANK']);
    }

    /* ================================================================ Payment methods */

    public function test_payment_methods_list_active_compatible_accounts_only(): void
    {
        $this->createFinancialAccount($this->organization, ['type' => 'bank', 'name' => 'Old bank', 'code' => 'OLD', 'status' => 'inactive']);
        [, $foreignOrganization] = $this->otherOrganization();
        $this->createFinancialAccount($foreignOrganization, ['type' => 'bank', 'name' => 'Foreign bank', 'code' => 'FB']);
        $this->connect($this->owner);

        $methods = collect($this->tool('searchPaymentMethods', ['currency_code' => 'MAD'])['data']['methods'])->keyBy('method');
        $this->assertSame(['cash', 'card', 'bank_transfer', 'cheque'], $methods->keys()->all());
        $this->assertSame(['Banque'], array_column($methods['bank_transfer']['financial_accounts'], 'name'));
        $this->assertSame([], $methods['cash']['financial_accounts']);
    }

    /* ================================================================ Payment preview */

    public function test_partial_and_exact_remaining_payment_previews(): void
    {
        $order = $this->confirmedOrder();
        $this->connect($this->owner);

        $partial = $this->tool('previewPayment', ['payment' => $this->payment($order, '4000')])['data'];
        $this->assertTrue($partial['valid'], json_encode($partial['issues']));
        $this->assertSame(['total' => '10000.0000', 'paid_amount' => '0.0000', 'outstanding_amount' => '10000.0000', 'payment_status' => 'unpaid', 'fully_paid' => false], $partial['before']);
        $this->assertSame(['total' => '10000.0000', 'paid_amount' => '4000.0000', 'outstanding_amount' => '6000.0000', 'payment_status' => 'partially_paid', 'fully_paid' => false], $partial['after']);
        $this->assertSame(0, Payment::query()->count(), 'A preview never records anything.');

        $exact = $this->tool('previewPayment', ['payment' => $this->payment($order, '10000')])['data'];
        $this->assertTrue($exact['after']['fully_paid']);
        $this->assertSame('paid', $exact['after']['payment_status']);
    }

    public function test_preview_rejects_invalid_amount_state_account_and_overpayment(): void
    {
        $order = $this->confirmedOrder();
        $this->connect($this->owner);

        // Invalid amount (canonical form rules: > 0, ≤ 4 decimals).
        $this->assertSame('MCP_VALIDATION_FAILED', $this->tool('previewPayment', ['payment' => $this->payment($order, '0')])['error']['code']);
        // Overpayment: the ERP forbids a payment above the remaining balance.
        $over = $this->tool('previewPayment', ['payment' => $this->payment($order, '10000.0001')])['data'];
        $this->assertFalse($over['valid']);
        $this->assertSame('amount_exceeds_outstanding', $over['issues'][0]['code']);
        $this->assertSame(['amount' => '10000.0001', 'outstanding_amount' => '10000.0000'], $over['issues'][0]['details']);
        $this->assertNull($over['preview_fingerprint']);

        // Method not accepted by the account, inactive account, unknown account.
        $this->assertSame('invalid_financial_account', $this->tool('previewPayment', ['payment' => [...$this->payment($order, '10'), 'method' => 'cash']])['data']['issues'][0]['code']);
        $inactive = $this->createFinancialAccount($this->organization, ['type' => 'bank', 'name' => 'Closed', 'code' => 'CLS', 'status' => 'inactive']);
        $this->assertSame('invalid_financial_account', $this->tool('previewPayment', ['payment' => [...$this->payment($order, '10'), 'financial_account_id' => $inactive->id]])['data']['issues'][0]['code']);
        $this->assertSame('invalid_financial_account', $this->tool('previewPayment', ['payment' => [...$this->payment($order, '10'), 'financial_account_id' => 999999]])['data']['issues'][0]['code']);

        // Draft (unconfirmed) order.
        $draft = $this->draftOrder();
        $this->connect($this->owner);
        $state = $this->tool('previewPayment', ['payment' => $this->payment($draft, '10')])['data'];
        $this->assertSame('sales_order_not_confirmed', $state['issues'][0]['code']);
    }

    public function test_payments_reject_cross_store_and_cross_organization_orders(): void
    {
        $orderB = $this->confirmedOrder($this->storeB);
        $sellerA = $this->member([$this->storeA], ['payments.view', 'payments.create', 'invoices.view', 'invoices.create', 'invoices.issue', 'sales_orders.view']);
        $this->connect($sellerA);
        $this->assertSame('MCP_SALES_ORDER_NOT_FOUND', $this->tool('previewPayment', ['payment' => $this->payment($orderB, '10')])['error']['code']);
        $this->assertSame('MCP_SALES_ORDER_NOT_FOUND', $this->tool('previewInvoiceIssue', ['sales_order_id' => $orderB->id])['error']['code']);

        [$otherOwner, $otherOrganization] = $this->otherOrganization();
        $this->connect($otherOwner, self::SCOPES, $otherOrganization);
        $orderA = $this->confirmedOrder();
        $this->connect($otherOwner, self::SCOPES, $otherOrganization);
        $this->assertSame('MCP_SALES_ORDER_NOT_FOUND', $this->tool('previewPayment', ['payment' => $this->payment($orderA, '10')])['error']['code']);
    }

    /* ================================================================ Record payment */

    public function test_record_partial_then_remaining_payment_with_idempotency_and_audit(): void
    {
        $order = $this->confirmedOrder();
        $this->connect($this->owner);

        $first = $this->record($order, '4000', 'pay-1');
        $this->assertTrue($first['ok'], json_encode($first));
        $this->assertSame(['total' => '10000.0000', 'paid_amount' => '4000.0000', 'outstanding_amount' => '6000.0000', 'payment_status' => 'partially_paid', 'fully_paid' => false], $first['data']['sales_order_payment']);
        $this->assertSame('payment', McpIdempotencyRecord::query()->where('tool', 'record_payment')->sole()->target_type);

        // Retry with the same key: no duplicate. Same key, different payload: conflict.
        $replay = $this->tool('recordPayment', ['payment' => $this->payment($order, '4000'), 'preview_fingerprint' => str_repeat('a', 64), 'idempotency_key' => 'pay-1']);
        $this->assertTrue($replay['data']['idempotent_replay']);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame('MCP_IDEMPOTENCY_CONFLICT', $this->tool('recordPayment', ['payment' => $this->payment($order, '1000'), 'preview_fingerprint' => str_repeat('a', 64), 'idempotency_key' => 'pay-1'])['error']['code']);

        $log = AuditLog::query()->where('event', 'payment.posted')->sole();
        $this->assertSame($this->owner->id, $log->actor_id);
        $this->assertSame('mcp', $log->actor_channel);

        // A recorded payment never issues an invoice.
        $this->assertSame(0, Invoice::query()->count());

        $second = $this->record($order, '6000', 'pay-2');
        $this->assertTrue($second['data']['sales_order_payment']['fully_paid']);
        $this->assertSame('0.0000', $second['data']['sales_order_payment']['outstanding_amount']);
        $this->assertSame('paid', $order->fresh()->payment_status->value);
    }

    public function test_a_stale_payment_preview_is_refused(): void
    {
        $order = $this->confirmedOrder();
        $this->connect($this->owner);
        $fingerprint = $this->tool('previewPayment', ['payment' => $this->payment($order, '4000')])['data']['preview_fingerprint'];
        $this->record($order, '1000', 'other'); // balance changed since the preview

        $this->assertSame('MCP_PREVIEW_STALE', $this->tool('recordPayment', ['payment' => $this->payment($order, '4000'), 'preview_fingerprint' => $fingerprint, 'idempotency_key' => 'stale'])['error']['code']);
        $this->assertSame(1, Payment::query()->count());
    }

    /* ================================================================ Invoice issue */

    public function test_invoice_issue_is_blocked_until_fully_paid_then_issued_once(): void
    {
        $order = $this->confirmedOrder();
        $this->connect($this->owner);

        $unpaid = $this->tool('previewInvoiceIssue', ['sales_order_id' => $order->id])['data'];
        $this->assertFalse($unpaid['valid']);
        $this->assertSame('sales_order_not_fully_paid', $unpaid['issues'][0]['code']);
        $this->assertSame(['sales_order_id' => $order->id, 'total' => '10000.0000', 'paid' => '0.0000', 'outstanding' => '10000.0000'], $unpaid['issues'][0]['details']);
        $this->assertSame(0, Invoice::query()->count(), 'The preview never keeps a draft.');

        $this->record($order, '4000', 'p1');
        $partial = $this->tool('previewInvoiceIssue', ['sales_order_id' => $order->id])['data'];
        $this->assertSame('6000.0000', $partial['issues'][0]['details']['outstanding']);
        // No fingerprint → issue refused: MCP cannot bypass the payment rule.
        $this->assertSame('MCP_PREVIEW_STALE', $this->tool('issueInvoice', ['sales_order_id' => $order->id, 'preview_fingerprint' => str_repeat('b', 64), 'idempotency_key' => 'inv-x'])['error']['code']);

        $this->record($order, '6000', 'p2');
        $ready = $this->tool('previewInvoiceIssue', ['sales_order_id' => $order->id])['data'];
        $this->assertTrue($ready['valid'], json_encode($ready['issues']));
        $this->assertSame('issued', $ready['invoice_preview']['status']);
        $this->assertSame('10000.0000', $ready['invoice_preview']['totals']['total_incl_tax']);

        $issued = $this->tool('issueInvoice', ['sales_order_id' => $order->id, 'preview_fingerprint' => $ready['preview_fingerprint'], 'idempotency_key' => 'inv-1']);
        $this->assertTrue($issued['ok'], json_encode($issued));
        $invoice = Invoice::query()->sole();
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertNotNull($invoice->invoice_number);
        $this->assertSame($invoice->invoice_number, $issued['data']['invoice']['invoice_number']);
        $this->assertSame($order->id, $invoice->sales_order_id);
        $this->assertTrue($issued['data']['invoice']['immutable']);
        $this->assertSame('mcp', AuditLog::query()->where('event', 'invoice.issued')->sole()->actor_channel);
        $this->assertSame(2, Payment::query()->count(), 'Issuing records no payment.');
        $this->assertSame('invoice', McpIdempotencyRecord::query()->where('tool', 'issue_invoice')->sole()->target_type);

        // Retry with the same key → replay; a new preview reports the existing invoice; no duplicate.
        $this->assertTrue($this->tool('issueInvoice', ['sales_order_id' => $order->id, 'preview_fingerprint' => $ready['preview_fingerprint'], 'idempotency_key' => 'inv-1'])['data']['idempotent_replay']);
        $again = $this->tool('previewInvoiceIssue', ['sales_order_id' => $order->id])['data'];
        $this->assertSame('invoice_already_issued', $again['issues'][0]['code']);
        $this->assertSame($invoice->id, $again['existing_invoice']['id']);
        $dup = $this->tool('issueInvoice', ['sales_order_id' => $order->id, 'preview_fingerprint' => $ready['preview_fingerprint'], 'idempotency_key' => 'inv-2']);
        $this->assertSame('MCP_INVOICE_ALREADY_ISSUED', $dup['error']['code']);
        $this->assertSame($invoice->id, $dup['error']['details']['invoice_id']);
        $this->assertSame(1, Invoice::query()->count());

        // The issued snapshot is frozen: a later customer change does not alter it.
        $this->customer->forceFill(['company_name' => 'RENAMED'])->save();
        $this->assertSame('CLIENT X SARL', $this->tool('getInvoice', ['invoice_id' => $invoice->id])['data']['customer']['company']);
    }

    public function test_incomplete_seller_profile_is_reported_as_a_structured_issue(): void
    {
        // A fully paid order whose existing invoice draft froze a seller snapshot without a
        // legal name: IssueInvoiceAction refuses (seller_profile), nothing else blocks first.
        $order = $this->confirmedOrder();
        $this->recordPayment($this->owner, $order, $this->bank, '10000.0000', ['method' => 'bank_transfer']);
        $draft = $this->createInvoice($this->owner, $order->fresh());
        $draft->forceFill(['seller_snapshot' => [...$draft->seller_snapshot, 'legal_name' => '']])->save();
        $this->connect($this->owner);

        $preview = $this->tool('previewInvoiceIssue', ['sales_order_id' => $order->id])['data'];

        $this->assertFalse($preview['valid']);
        $this->assertNull($preview['preview_fingerprint']);
        $this->assertSame([['path' => 'seller_profile', 'code' => 'seller_profile_incomplete', 'message' => 'The document seller legal name must be configured before issuance.']], $preview['issues']);
        $this->assertTrue($preview['payment']['fully_paid']);
        $this->assertSame(InvoiceStatus::Draft, $draft->fresh()->status, 'The preview leaves the draft untouched.');
        $this->assertNull($draft->fresh()->invoice_number);
    }

    public function test_draft_order_cannot_be_invoiced(): void
    {
        $draft = $this->draftOrder();
        $this->connect($this->owner);

        $this->assertSame('sales_order_not_confirmed', $this->tool('previewInvoiceIssue', ['sales_order_id' => $draft->id])['data']['issues'][0]['code']);
    }

    /* ================================================================ Invoice reads + document */

    public function test_search_get_invoice_with_isolation_and_scope_gate(): void
    {
        $invoice = $this->issuedInvoice();
        $invoiceB = $this->issuedInvoice($this->storeB);
        $this->connect($this->owner);

        $found = $this->tool('searchInvoices', ['query' => $invoice->invoice_number])['data']['items'];
        $this->assertSame([$invoice->id], array_column($found, 'id'));
        $this->assertSame('0.0000', $found[0]['payment']['outstanding_amount']);
        $got = $this->tool('getInvoice', ['invoice_id' => $invoice->id])['data'];
        $this->assertSame('10000.0000', $got['totals']['total_incl_tax']);
        $this->assertSame([['tax_name' => null, 'tax_rate' => '0.0000', 'taxable_amount' => '10000.0000', 'tax_amount' => '0.0000']], $got['taxes']);
        $this->assertSame($invoice->sales_order_id, $got['sales_order']['id']);

        // Store isolation: a member of store A only.
        $sellerA = $this->member([$this->storeA], ['invoices.view']);
        $this->connect($sellerA);
        $this->assertSame('MCP_INVOICE_NOT_FOUND', $this->tool('getInvoice', ['invoice_id' => $invoiceB->id])['error']['code']);
        $this->assertNotContains($invoiceB->id, array_column($this->tool('searchInvoices')['data']['items'], 'id'));
        $this->assertArrayNotHasKey('payment', $this->tool('getInvoice', ['invoice_id' => $invoice->id])['data'], 'Receivables need payments.view.');

        // Organization isolation.
        [$otherOwner, $otherOrganization] = $this->otherOrganization();
        $this->connect($otherOwner, self::SCOPES, $otherOrganization);
        $this->assertSame('MCP_INVOICE_NOT_FOUND', $this->tool('getInvoice', ['invoice_id' => $invoice->id])['error']['code']);

        // Scope gate.
        $this->connect($this->owner, ['payments.view']);
        $this->assertSame('MCP_FORBIDDEN', $this->tool('searchInvoices')['error']['code']);
        $this->assertSame('MCP_FORBIDDEN', $this->tool('previewInvoiceIssue', ['sales_order_id' => $invoice->sales_order_id])['error']['code']);
    }

    public function test_invoice_pdf_is_read_only_and_requires_an_issued_invoice(): void
    {
        $invoice = $this->issuedInvoice();
        $before = $invoice->fresh()->only(['status', 'invoice_number', 'updated_at']);
        $this->connect($this->owner);

        $document = $this->tool('getInvoiceDocument', ['invoice_id' => $invoice->id])['data'];
        $this->assertSame('application/pdf', $document['mime_type']);
        $this->assertStringContainsString('/mcp/documents/invoices/'.$invoice->id, $document['download_url']);
        $this->assertEquals($before, $invoice->fresh()->only(['status', 'invoice_number', 'updated_at']));

        // The signed link serves the PDF.
        $this->get($document['download_url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    /* ================================================================ Consistency */

    public function test_sales_order_and_customer_balance_reflect_recorded_payments_and_invoice(): void
    {
        $order = $this->confirmedOrder();
        $this->connect($this->owner);
        $this->record($order, '4000', 'c1');

        $payment = $this->tool('getSalesOrder', ['sales_order_id' => $order->id], SalesOrderTools::class)['data']['payment'];
        $this->assertSame('10000.0000', $payment['total']);
        $this->assertSame('4000.0000', $payment['paid']);
        $this->assertSame('6000.0000', $payment['remaining']);
        $this->assertFalse($payment['fully_paid']);
        $this->assertNull($payment['invoice']);

        $balance = $this->tool('getCustomerBalanceSummary', ['customer_id' => $this->customer->id], SalesAssistantTools::class)['data'];
        $this->assertSame('4000.0000', $balance['paid_total']);

        $this->record($order, '6000', 'c2');
        $ready = $this->tool('previewInvoiceIssue', ['sales_order_id' => $order->id])['data'];
        $issued = $this->tool('issueInvoice', ['sales_order_id' => $order->id, 'preview_fingerprint' => $ready['preview_fingerprint'], 'idempotency_key' => 'c-inv'])['data'];

        $payment = $this->tool('getSalesOrder', ['sales_order_id' => $order->id], SalesOrderTools::class)['data']['payment'];
        $this->assertTrue($payment['fully_paid']);
        $this->assertSame(['id' => $issued['target']['id'], 'invoice_number' => $issued['invoice']['invoice_number'], 'status' => 'issued'], $payment['invoice']);
        $balance = $this->tool('getCustomerBalanceSummary', ['customer_id' => $this->customer->id], SalesAssistantTools::class)['data'];
        $this->assertSame('10000.0000', $balance['paid_total']);
        $this->assertSame('10000.0000', $balance['invoiced_total']);
        $this->assertSame('0.0000', $balance['outstanding']);

        $this->assertSame(2, $this->tool('searchPayments', ['sales_order_id' => $order->id])['data']['pagination']['total']);
        $this->assertSame(2, $this->tool('searchPayments', ['invoice_id' => $issued['target']['id']])['data']['pagination']['total']);
        $paymentId = Payment::query()->orderBy('id')->value('id');
        $this->assertSame([$issued['target']['id']], array_column($this->tool('getPayment', ['payment_id' => $paymentId])['data']['allocations'][0]['invoices'], 'id'));
    }

    /* ================================================================ Full workflow */

    public function test_full_workflow_partial_payment_blocked_invoice_remaining_payment_invoice_pdf(): void
    {
        $order = $this->confirmedOrder();
        $this->connect($this->owner);

        // "Le client a payé 4 000 DH par virement."
        $outstanding = $this->tool('getSalesOrder', ['sales_order_id' => $order->id], SalesOrderTools::class)['data']['payment']['remaining'];
        $this->assertSame('10000.0000', $outstanding);
        $transfer = collect($this->tool('searchPaymentMethods', ['currency_code' => 'MAD'])['data']['methods'])->firstWhere('method', 'bank_transfer');
        $accountId = $transfer['financial_accounts'][0]['id'];
        $paid = $this->record($order, '4000', 'wf-1', $accountId)['data']['sales_order_payment'];
        $this->assertSame('6000.0000', $paid['outstanding_amount']);
        $this->assertFalse($this->tool('previewInvoiceIssue', ['sales_order_id' => $order->id])['data']['valid']);

        // "Il vient de payer les 6 000 DH restants."
        $this->assertTrue($this->record($order, '6000', 'wf-2', $accountId)['data']['sales_order_payment']['fully_paid']);

        // "Fais la facture."
        $ready = $this->tool('previewInvoiceIssue', ['sales_order_id' => $order->id])['data'];
        $invoice = $this->tool('issueInvoice', ['sales_order_id' => $order->id, 'preview_fingerprint' => $ready['preview_fingerprint'], 'idempotency_key' => 'wf-inv'])['data']['invoice'];
        $this->assertNotNull($invoice['invoice_number']);

        // "Donne-moi le PDF."
        $document = $this->tool('getInvoiceDocument', ['invoice_id' => $invoice['id']])['data'];
        $this->assertNotEmpty($document['download_url']);
        $this->assertSame('confirmed', $order->fresh()->status->value);
    }

    public function test_registry_lists_finance_tools_by_scope_and_permission(): void
    {
        $names = $this->listTools($this->owner, ['invoices.view', 'payments.view']);
        foreach (['search_invoices', 'get_invoice', 'get_invoice_document', 'search_payments', 'get_payment'] as $name) {
            $this->assertContains($name, $names);
        }
        foreach (['preview_invoice_issue', 'issue_invoice', 'preview_payment', 'record_payment', 'search_payment_methods'] as $name) {
            $this->assertNotContains($name, $names);
        }

        $viewer = $this->member([$this->storeA], ['invoices.view']);
        $names = $this->listTools($viewer, ['invoices.view', 'payments.create']);
        $this->assertContains('search_invoices', $names);
        $this->assertNotContains('record_payment', $names);
    }

    public function test_idempotency_target_types_fit_the_column(): void
    {
        foreach ([\App\Services\Mcp\Finance\McpPaymentService::TARGET_TYPE, \App\Services\Mcp\Finance\McpInvoiceIssueService::TARGET_TYPE] as $type) {
            $this->assertLessThanOrEqual(McpIdempotencyService::TARGET_TYPE_MAX, mb_strlen($type));
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @param list<string> $scopes */
    private function connect(User $user, array $scopes = self::SCOPES, ?Organization $organization = null): void
    {
        app()->forgetScopedInstances();
        $issued = app(McpConnectionCredentials::class)->issue($user, $organization ?? $this->organization, 'Agent', $scopes, null);
        app(McpExecutionContext::class)->bind($issued['connection']->load(['organization', 'user', 'scopeRecords']));
    }

    /** @return array<string, mixed> */
    private function tool(string $method, array $arguments = [], string $class = FinanceTools::class): array
    {
        return app($class)->{$method}(...$arguments)->structuredContent;
    }

    /** @return array<string, mixed> */
    private function payment(SalesOrder $order, string $amount, ?int $accountId = null): array
    {
        return ['sales_order_id' => $order->id, 'method' => 'bank_transfer', 'financial_account_id' => $accountId ?? $this->bank->id, 'amount' => $amount];
    }

    /** Preview then record, like an agent. @return array<string, mixed> */
    private function record(SalesOrder $order, string $amount, string $key, ?int $accountId = null): array
    {
        $payment = $this->payment($order, $amount, $accountId);
        $preview = $this->tool('previewPayment', ['payment' => $payment])['data'];
        $this->assertTrue($preview['valid'], json_encode($preview['issues']));

        return $this->tool('recordPayment', ['payment' => $payment, 'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => $key]);
    }

    private function draftOrder(?Store $store = null): SalesOrder
    {
        app()->forgetScopedInstances();
        $order = $this->createDraftOrder($this->owner, $this->organization, $store ?? $this->storeA, $this->customer, ['sale_date' => '2026-10-01']);
        $this->addCustomLine($this->owner, $order, ['unit_price_excl_tax' => '10000.0000']);

        return $order->fresh();
    }

    private function confirmedOrder(?Store $store = null): SalesOrder
    {
        $order = $this->draftOrder($store);

        return app(ConfirmSalesOrderAction::class)->execute($this->owner, $order)->fresh();
    }

    /** A fully paid, invoiced order built with the web actions. */
    private function issuedInvoice(?Store $store = null): Invoice
    {
        $order = $this->confirmedOrder($store);
        $this->recordPayment($this->owner, $order, $this->bank, '10000.0000', ['method' => 'bank_transfer']);

        return $this->issueInvoice($this->owner, $this->createInvoice($this->owner, $order->fresh()));
    }

    /** @param list<Store> $stores @param list<string> $permissions */
    private function member(array $stores, array $permissions): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($this->organization, $user, $permissions);
        foreach ($stores as $store) {
            $this->addStoreMember($store, $user);
        }

        return $user;
    }

    /** @return array{User, Organization} */
    private function otherOrganization(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner, 'Other');
        $organization->settings = ['allow_mcp_connections' => true];
        $organization->save();

        return [$owner, $organization->refresh()];
    }

    /** @param list<string> $scopes @return list<string> */
    private function listTools(User $user, array $scopes): array
    {
        app()->forgetScopedInstances();
        $issued = app(McpConnectionCredentials::class)->issue($user, $this->organization, 'Agent', $scopes, null);
        $headers = ['Authorization' => 'Bearer '.$issued['secret'], 'Accept' => 'application/json, text/event-stream'];
        $session = $this->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]])->headers->get('Mcp-Session-Id');
        $headers += ['Mcp-Session-Id' => $session, 'Mcp-Protocol-Version' => '2025-11-25'];
        $this->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $names = array_column($this->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass])->json('result.tools') ?? [], 'name');
        $this->flushHeaders();

        return $names;
    }
}
