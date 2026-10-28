<?php

namespace App\Services\Mcp\Finance;

use App\Services\AuditLogger;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\McpIdempotencyService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * MCP-W7 invoices and payments. Adapters only: membership + ERP permission +
 * MCP scope, tenant identity from the connection, store access from the
 * user's memberships; every mutation is the canonical ERP action, which writes
 * the payment.* / invoice.* audit with the MCP connection attribution.
 */
final class FinanceTools
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly InvoiceReadService $invoices,
        private readonly PaymentReadService $payments,
        private readonly McpPaymentService $paymentChanges,
        private readonly McpInvoiceIssueService $invoiceIssue,
        private readonly McpInvoiceDocumentService $documents,
        private readonly McpIdempotencyService $idempotency,
        private readonly AuditLogger $audit,
    ) {}

    /* ---------------------------------------------------------------- Invoices */

    public function searchInvoices(?string $query = null, ?string $status = null, ?int $customer_id = null, ?int $sales_order_id = null, ?int $store_id = null, ?string $date_from = null, ?string $date_to = null, int $page = 1, int $per_page = 25): CallToolResult
    {
        return $this->execute('search_invoices', function () use ($query, $status, $customer_id, $sales_order_id, $store_id, $date_from, $date_to, $page, $per_page) {
            $this->context->authorize('invoices.view');
            $result = $this->invoices->search($this->context->organization(), array_filter(compact('query', 'status', 'customer_id', 'sales_order_id', 'store_id', 'date_from', 'date_to', 'page', 'per_page'), fn ($v) => $v !== null), $this->canSeePayments());
            $this->auditRead('mcp.invoices.searched', ['has_query' => is_string($query) && trim($query) !== '', 'status' => $status, 'page' => $page, 'result_count' => count($result['items'])]);

            return $result;
        });
    }

    public function getInvoice(int $invoice_id): CallToolResult
    {
        return $this->execute('get_invoice', function () use ($invoice_id) {
            $this->context->authorize('invoices.view');
            $result = $this->invoices->invoice($this->context->organization(), $invoice_id, $this->canSeePayments());
            $this->auditRead('mcp.invoice.viewed', ['invoice_id' => $invoice_id]);

            return $result;
        }, 'MCP_INVOICE_NOT_FOUND');
    }

    public function previewInvoiceIssue(int $sales_order_id): CallToolResult
    {
        return $this->execute('preview_invoice_issue', function () use ($sales_order_id) {
            $this->context->authorize('invoices.issue');
            $preview = $this->invoiceIssue->preview($sales_order_id);
            $this->auditRead('mcp.invoice_issue.previewed', ['sales_order_id' => $sales_order_id, 'valid' => $preview['valid'], 'issue_codes' => array_column($preview['issues'], 'code')]);

            return $preview;
        }, 'MCP_SALES_ORDER_NOT_FOUND');
    }

    public function issueInvoice(int $sales_order_id, string $preview_fingerprint, string $idempotency_key): CallToolResult
    {
        return $this->execute('issue_invoice', function () use ($sales_order_id, $preview_fingerprint, $idempotency_key) {
            $this->context->authorize('invoices.issue');

            return $this->idempotency->execute('issue_invoice', $idempotency_key, ['sales_order_id' => $sales_order_id],
                fn () => $this->invoiceIssue->execute($sales_order_id, $preview_fingerprint));
        }, 'MCP_SALES_ORDER_NOT_FOUND');
    }

    public function getInvoiceDocument(int $invoice_id, string $format = 'pdf', bool $include_company_stamp = false): CallToolResult
    {
        return $this->execute('get_invoice_document', function () use ($invoice_id, $format, $include_company_stamp) {
            $this->context->authorize('invoices.view');
            if ($format !== 'pdf') throw new InvalidArgumentException('invalid_format');
            $result = $this->documents->document($invoice_id, $include_company_stamp);
            $this->auditRead('mcp.invoice_document.generated', ['invoice_id' => $invoice_id]);

            return $result;
        }, 'MCP_INVOICE_NOT_FOUND');
    }

    /* ---------------------------------------------------------------- Payments */

    public function searchPaymentMethods(?string $currency_code = null): CallToolResult
    {
        return $this->execute('search_payment_methods', function () use ($currency_code) {
            $this->context->authorize('payments.create');

            return $this->payments->methods($this->context->organization(), $currency_code !== null ? strtoupper(trim($currency_code)) : null);
        });
    }

    public function searchPayments(?string $query = null, ?string $status = null, ?string $method = null, ?int $financial_account_id = null, ?int $customer_id = null, ?int $sales_order_id = null, ?int $invoice_id = null, ?int $store_id = null, ?string $date_from = null, ?string $date_to = null, int $page = 1, int $per_page = 25): CallToolResult
    {
        return $this->execute('search_payments', function () use ($query, $status, $method, $financial_account_id, $customer_id, $sales_order_id, $invoice_id, $store_id, $date_from, $date_to, $page, $per_page) {
            $this->context->authorize('payments.view');
            $result = $this->payments->search($this->context->organization(), array_filter(compact('query', 'status', 'method', 'financial_account_id', 'customer_id', 'sales_order_id', 'invoice_id', 'store_id', 'date_from', 'date_to', 'page', 'per_page'), fn ($v) => $v !== null));
            $this->auditRead('mcp.payments.searched', ['has_query' => is_string($query) && trim($query) !== '', 'status' => $status, 'page' => $page, 'result_count' => count($result['items'])]);

            return $result;
        });
    }

    public function getPayment(int $payment_id): CallToolResult
    {
        return $this->execute('get_payment', function () use ($payment_id) {
            $this->context->authorize('payments.view');
            $result = $this->payments->payment($this->context->organization(), $payment_id);
            $this->auditRead('mcp.payment.viewed', ['payment_id' => $payment_id]);

            return $result;
        }, 'MCP_PAYMENT_NOT_FOUND');
    }

    /** @param array<string, mixed> $payment */
    public function previewPayment(array $payment): CallToolResult
    {
        return $this->execute('preview_payment', function () use ($payment) {
            $this->context->authorize('payments.create');
            $preview = $this->paymentChanges->preview($this->paymentChanges->normalize($payment));
            $this->auditRead('mcp.payment.previewed', ['sales_order_id' => $preview['sales_order']['id'], 'valid' => $preview['valid'], 'issue_codes' => array_column($preview['issues'], 'code')]);

            return $preview;
        }, 'MCP_SALES_ORDER_NOT_FOUND');
    }

    /** @param array<string, mixed> $payment */
    public function recordPayment(array $payment, string $preview_fingerprint, string $idempotency_key): CallToolResult
    {
        return $this->execute('record_payment', function () use ($payment, $preview_fingerprint, $idempotency_key) {
            $this->context->authorize('payments.create');
            $payload = $this->paymentChanges->normalize($payment);

            return $this->idempotency->execute('record_payment', $idempotency_key, $payload,
                fn () => $this->paymentChanges->record($payload, $preview_fingerprint, $idempotency_key));
        }, 'MCP_SALES_ORDER_NOT_FOUND');
    }

    /* ---------------------------------------------------------------- Envelope */

    private function canSeePayments(): bool
    {
        return in_array('payments.view', $this->context->user()->permissionKeysFor($this->context->organization()->getKey()), true);
    }

    /** @param callable(): array<string, mixed> $callback */
    private function execute(string $tool, callable $callback, string $notFoundCode = 'MCP_ENTITY_NOT_FOUND'): CallToolResult
    {
        try {
            return $this->success($callback());
        } catch (AuthorizationException) {
            return $this->error('MCP_FORBIDDEN', 'The connection is not authorized to use this capability.');
        } catch (McpInvoiceAlreadyIssued $exception) {
            return $this->error('MCP_INVOICE_ALREADY_ISSUED', 'This Sales Order already has its issued invoice; no duplicate was created.', ['invoice_id' => $exception->invoiceId, 'invoice_number' => $exception->invoiceNumber]);
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode() === 404
                ? $this->error($notFoundCode, 'The requested resource was not found.')
                : $this->error('MCP_FORBIDDEN', 'The ERP refused this action for the connection user (permission or store membership).');
        } catch (ModelNotFoundException) {
            return $this->error($notFoundCode, 'The requested resource was not found.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            if (count($errors) === 1 && isset($errors['invoice'])) {
                return $this->error('MCP_INVALID_STATE', mb_substr((string) $errors['invoice'][0], 0, 200));
            }

            return $this->error('MCP_VALIDATION_FAILED', 'The candidate data is invalid.', ['fields' => $this->sanitizedErrors($exception)]);
        } catch (LockTimeoutException) {
            return $this->error('MCP_IDEMPOTENCY_BUSY', 'An identical operation is already being processed.');
        } catch (InvalidArgumentException $exception) {
            [$code, $message] = match ($exception->getMessage()) {
                'stale_or_invalid_preview' => ['MCP_PREVIEW_STALE', 'The preview is missing, expired, consumed, or no longer matches the current data. Preview again.'],
                'idempotency_key_payload_mismatch' => ['MCP_IDEMPOTENCY_CONFLICT', 'The idempotency key was already used with a different request.'],
                'document_unavailable' => ['MCP_DOCUMENT_UNAVAILABLE', 'The document could not be generated right now. Try again shortly.'],
                'forbidden_fields' => ['MCP_INVALID_ARGUMENTS', 'Unknown or disallowed fields were supplied.'],
                default => ['MCP_INVALID_ARGUMENTS', 'The supplied tool arguments are invalid.'],
            };

            return $this->error($code, $message);
        } catch (Throwable $exception) {
            Log::warning('MCP finance tool failed.', [
                'tool' => $tool,
                'connection_id' => $this->context->connection()?->getKey(),
                'exception_type' => $exception::class,
            ]);

            return $this->error('MCP_TOOL_INTERNAL_ERROR', 'The tool could not complete the request.');
        }
    }

    /** @param array<string, mixed> $data */
    private function success(array $data): CallToolResult
    {
        $payload = ['ok' => true, 'data' => $data];

        return new CallToolResult([new TextContent($payload)], structuredContent: $payload);
    }

    /** @param array<string, mixed> $details */
    private function error(string $code, string $message, array $details = []): CallToolResult
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) $error['details'] = $details;
        $payload = ['ok' => false, 'error' => $error];

        return new CallToolResult([new TextContent($payload)], isError: true, structuredContent: $payload);
    }

    /** @return array<string, list<string>> */
    private function sanitizedErrors(ValidationException $exception): array
    {
        return collect($exception->errors())->take(20)
            ->map(fn ($messages) => array_values(array_map(fn ($message) => mb_substr((string) $message, 0, 200), array_slice((array) $messages, 0, 3))))
            ->all();
    }

    /** @param array<string, mixed> $metadata */
    private function auditRead(string $event, array $metadata): void
    {
        $this->audit->record(event: $event, actor: $this->context->user(), organization: $this->context->organization(), newValues: $metadata);
    }
}
