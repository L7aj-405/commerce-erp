<?php

namespace App\Services\Mcp\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Services\Mcp\McpExecutionContext;
use Mcp\Schema\ToolAnnotations;

/**
 * MCP-W7 registration: invoices and payments. A tool is listed only when the
 * connection holds its scope AND the user currently holds the ERP permission;
 * every call re-checks both.
 */
final class FinanceToolRegistrar
{
    private const SCOPED = 'Only Sales Orders, invoices and payments of the stores the connection user belongs to.';

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly FinanceTools $tools,
    ) {}

    /** @param \Mcp\Server\Builder $builder @param array<string, mixed> $outputSchema */
    public function register($builder, ToolAnnotations $readOnly, array $outputSchema): void
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $page = ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1];
        $perPage = ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25];
        $date = ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'];
        $fingerprint = ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'];
        $idempotency = ['type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'description' => 'Reuse the same key when retrying: the operation happens once.'];
        $schema = fn (array $properties, array $required = []) => ['type' => 'object', ...($required === [] ? [] : ['required' => $required]),
            'properties' => $properties, 'additionalProperties' => false];
        $write = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false);
        $methods = array_column(PaymentMethod::cases(), 'value');

        // ---------------------------------------------------------------- Invoices
        if ($this->context->allows('invoices.view')) {
            $builder->addTool([$this->tools, 'searchInvoices'], name: 'search_invoices', title: 'Search invoices',
                description: 'Search invoices by number, customer or Sales Order number, with status/customer/order/store/date filters. Newest first. Includes the source order\'s paid/outstanding amounts when payments.view is held. '.self::SCOPED,
                annotations: $readOnly, outputSchema: $outputSchema,
                inputSchema: $schema([
                    'query' => ['type' => 'string', 'maxLength' => 255], 'status' => ['type' => 'string', 'enum' => array_column(InvoiceStatus::cases(), 'value')],
                    'customer_id' => $id, 'sales_order_id' => $id, 'store_id' => $id, 'date_from' => $date, 'date_to' => $date, 'page' => $page, 'per_page' => $perPage,
                ]));
            $builder->addTool([$this->tools, 'getInvoice'], name: 'get_invoice', title: 'Get invoice',
                description: 'Return one invoice: header, customer snapshot, store, frozen lines, tax breakdown, totals, Sales Order reference, immutability, and (with payments.view) the order\'s paid/outstanding amounts.',
                annotations: $readOnly, outputSchema: $outputSchema, inputSchema: $schema(['invoice_id' => $id], ['invoice_id']));
            $builder->addTool([$this->tools, 'getInvoiceDocument'], name: 'get_invoice_document', title: 'Get invoice PDF',
                description: 'Generates/returns the official PDF of an ISSUED invoice without changing invoice state: metadata plus a short-lived signed download_url the user can open. include_company_stamp only reports whether an apposed stamp is rendered; it never applies one (use preview_document_stamp / apply_document_stamp).',
                annotations: $readOnly, outputSchema: $outputSchema,
                inputSchema: $schema(['invoice_id' => $id, 'format' => ['type' => 'string', 'enum' => ['pdf'], 'default' => 'pdf'], 'include_company_stamp' => ['type' => 'boolean', 'default' => false]], ['invoice_id']));
        }

        if ($this->context->allows('invoices.issue')) {
            $builder->addTool([$this->tools, 'previewInvoiceIssue'], name: 'preview_invoice_issue', title: 'Preview invoice issue',
                description: 'Checks whether a confirmed Sales Order is eligible for invoicing, with the ERP issue rules and without saving anything. Invoice issuance is blocked until the order is fully paid (sales_order_not_fully_paid lists total/paid/outstanding); other issues: sales_order_not_confirmed, invoice_already_issued (with the existing invoice), seller_profile_incomplete. Returns the invoice it would issue and a short-lived single-use preview_fingerprint when valid.',
                annotations: $readOnly, outputSchema: $outputSchema, inputSchema: $schema(['sales_order_id' => $id], ['sales_order_id']));
            $builder->addTool([$this->tools, 'issueInvoice'], name: 'issue_invoice', title: 'Issue invoice',
                description: 'Issues the canonical immutable invoice previewed by preview_invoice_issue (official number, frozen snapshot, linked to the Sales Order). Does not record payment and does not confirm or change the order. Requires the preview fingerprint and an idempotency key; an order already invoiced returns MCP_INVOICE_ALREADY_ISSUED with the existing invoice instead of a duplicate.',
                annotations: $write, outputSchema: $outputSchema,
                inputSchema: $schema(['sales_order_id' => $id, 'preview_fingerprint' => $fingerprint, 'idempotency_key' => $idempotency], ['sales_order_id', 'preview_fingerprint', 'idempotency_key']));
        }

        // ---------------------------------------------------------------- Payments
        if ($this->context->allows('payments.view')) {
            $builder->addTool([$this->tools, 'searchPayments'], name: 'search_payments', title: 'Search payments',
                description: 'Search payments by number, reference, order number or customer, with status/method/account/customer/order/invoice/store/date filters. Newest first. '.self::SCOPED,
                annotations: $readOnly, outputSchema: $outputSchema,
                inputSchema: $schema([
                    'query' => ['type' => 'string', 'maxLength' => 255], 'status' => ['type' => 'string', 'enum' => array_column(PaymentStatus::cases(), 'value')],
                    'method' => ['type' => 'string', 'enum' => $methods], 'financial_account_id' => $id, 'customer_id' => $id, 'sales_order_id' => $id, 'invoice_id' => $id,
                    'store_id' => $id, 'date_from' => $date, 'date_to' => $date, 'page' => $page, 'per_page' => $perPage,
                ]));
            $builder->addTool([$this->tools, 'getPayment'], name: 'get_payment', title: 'Get payment',
                description: 'Return one payment: amount, currency, date, method, financial account, status, references, customer, Sales Order allocation and that order\'s invoices. Posted payments are immutable (reversal is a separate web action).',
                annotations: $readOnly, outputSchema: $outputSchema, inputSchema: $schema(['payment_id' => $id], ['payment_id']));
        }

        if ($this->context->allows('payments.create')) {
            $payment = [
                'sales_order_id' => $id,
                'method' => ['type' => 'string', 'enum' => $methods],
                'financial_account_id' => [...$id, 'description' => 'An account listed under the method by search_payment_methods.'],
                'amount' => ['type' => 'string', 'pattern' => '^\\d+(?:\\.\\d{1,4})?$', 'description' => 'Decimal string in the order currency, e.g. "4000" or "4000.50".'],
                'payment_date' => [...$date, 'description' => 'Defaults to today; another date needs the ERP payments.backdate permission.'],
                'reference' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'external_reference' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'notes' => ['type' => ['string', 'null'], 'maxLength' => 5000],
            ];
            $builder->addTool([$this->tools, 'searchPaymentMethods'], name: 'search_payment_methods', title: 'Search payment methods',
                description: 'List the ERP payment methods (cash, card, bank_transfer, cheque) with the active financial accounts that accept each one. Use it to resolve "par virement" etc. to a method + financial_account_id; never invent an id. Filter by the Sales Order currency.',
                annotations: $readOnly, outputSchema: $outputSchema, inputSchema: $schema(['currency_code' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3]]));
            $builder->addTool([$this->tools, 'previewPayment'], name: 'preview_payment', title: 'Preview payment',
                description: 'Validates a payment without saving it. Always preview before recording. Runs the ERP payment rules (confirmed order, active account in the order currency accepting the method, amount not above the outstanding balance, date permission) and returns paid/outstanding before and after, the resulting payment status, issues and a short-lived single-use preview_fingerprint. Partial payments are allowed.',
                annotations: $readOnly, outputSchema: $outputSchema, inputSchema: $schema(['payment' => $schema($payment, ['sales_order_id', 'method', 'financial_account_id', 'amount'])], ['payment']));
            $builder->addTool([$this->tools, 'recordPayment'], name: 'record_payment', title: 'Record payment',
                description: 'Records the exact previewed payment (same payment object + preview_fingerprint) on the Sales Order. It does not issue an invoice and does not confirm the order. Requires an idempotency key: a retry never records twice. Returns the updated paid/outstanding amounts.',
                annotations: $write, outputSchema: $outputSchema,
                inputSchema: $schema(['payment' => $schema($payment, ['sales_order_id', 'method', 'financial_account_id', 'amount']), 'preview_fingerprint' => $fingerprint, 'idempotency_key' => $idempotency], ['payment', 'preview_fingerprint', 'idempotency_key']));
        }
    }
}
