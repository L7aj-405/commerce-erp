<?php

namespace App\Services\Mcp\Procurement;

use App\Services\AuditLogger;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\McpIdempotencyService;
use App\Services\Mcp\Sales\SalesOrderReadService;
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
 * MCP purchasing: Sales Order procurement, supplier purchase orders and goods
 * receipts. Adapters only: membership + ERP permission + MCP scope, tenant
 * identity from the connection, store access from the user's memberships; every
 * mutation is the canonical procurement action, which writes the
 * purchase_order.* / procurement.* audit with the MCP connection attribution.
 */
final class PurchasingTools
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly SalesOrderReadService $orders,
        private readonly PurchaseOrderReadService $purchaseOrders,
        private readonly McpPurchaseOrderService $purchaseOrderChanges,
        private readonly McpProcurementReceiptService $receipts,
        private readonly McpIdempotencyService $idempotency,
        private readonly AuditLogger $audit,
    ) {}

    public function getSalesOrderProcurement(int $sales_order_id): CallToolResult
    {
        return $this->execute('get_sales_order_procurement', function () use ($sales_order_id) {
            $this->context->authorize('sales_orders.view');
            $result = $this->purchaseOrders->salesOrderProcurement($this->orders->find($this->context->organization(), $sales_order_id));
            $this->auditRead('mcp.sales_order_procurement.viewed', ['sales_order_id' => $sales_order_id]);

            return $result;
        }, 'MCP_SALES_ORDER_NOT_FOUND');
    }

    public function searchPurchaseOrders(?string $query = null, ?int $supplier_id = null, ?int $sales_order_id = null, ?string $status = null, ?string $date_from = null, ?string $date_to = null, int $page = 1, int $per_page = 25): CallToolResult
    {
        return $this->execute('search_purchase_orders', function () use ($query, $supplier_id, $sales_order_id, $status, $date_from, $date_to, $page, $per_page) {
            $this->context->authorize('purchase_orders.view');
            $result = $this->purchaseOrders->search($this->context->organization(), array_filter(compact('query', 'supplier_id', 'sales_order_id', 'status', 'date_from', 'date_to', 'page', 'per_page'), fn ($v) => $v !== null));
            $this->auditRead('mcp.purchase_orders.searched', ['has_query' => is_string($query) && trim($query) !== '', 'status' => $status, 'page' => $page, 'result_count' => count($result['items'])]);

            return $result;
        });
    }

    public function getPurchaseOrder(int $purchase_order_id): CallToolResult
    {
        return $this->execute('get_purchase_order', function () use ($purchase_order_id) {
            $this->context->authorize('purchase_orders.view');
            $result = $this->purchaseOrders->present($this->purchaseOrders->find($this->context->organization(), $purchase_order_id));
            $this->auditRead('mcp.purchase_order.viewed', ['purchase_order_id' => $purchase_order_id]);

            return $result;
        }, 'MCP_PURCHASE_ORDER_NOT_FOUND');
    }

    public function previewPurchaseOrderCreation(int $sales_order_id, int $supplier_id, ?string $expected_delivery_date = null, ?string $notes = null): CallToolResult
    {
        return $this->execute('preview_purchase_order_creation', function () use ($sales_order_id, $supplier_id, $expected_delivery_date, $notes) {
            $this->context->authorize('purchase_orders.create');
            $preview = $this->purchaseOrderChanges->preview($this->purchaseOrderChanges->normalize(compact('sales_order_id', 'supplier_id', 'expected_delivery_date', 'notes')));
            $this->auditRead('mcp.purchase_order.previewed', ['sales_order_id' => $sales_order_id, 'supplier_id' => $supplier_id, 'valid' => $preview['valid'], 'issue_codes' => array_column($preview['issues'], 'code')]);

            return $preview;
        }, 'MCP_SALES_ORDER_NOT_FOUND');
    }

    public function createPurchaseOrder(int $sales_order_id, int $supplier_id, string $preview_fingerprint, string $idempotency_key, ?string $expected_delivery_date = null, ?string $notes = null): CallToolResult
    {
        return $this->execute('create_purchase_order', function () use ($sales_order_id, $supplier_id, $expected_delivery_date, $notes, $preview_fingerprint, $idempotency_key) {
            $this->context->authorize('purchase_orders.create');
            $payload = $this->purchaseOrderChanges->normalize(compact('sales_order_id', 'supplier_id', 'expected_delivery_date', 'notes'));

            return $this->idempotency->execute('create_purchase_order', $idempotency_key, $payload,
                fn () => $this->purchaseOrderChanges->create($payload, $preview_fingerprint, $idempotency_key));
        }, 'MCP_SALES_ORDER_NOT_FOUND');
    }

    public function previewProcurementReceipt(int $purchase_order_id, string $quantity_received, int $warehouse_id, ?int $purchase_order_line_id = null, ?string $received_date = null, ?string $supplier_delivery_reference = null, ?string $note = null): CallToolResult
    {
        return $this->execute('preview_procurement_receipt', function () use ($purchase_order_id, $purchase_order_line_id, $quantity_received, $warehouse_id, $received_date, $supplier_delivery_reference, $note) {
            $this->context->authorize('procurement_receipts.create');
            $preview = $this->receipts->preview($this->receipts->normalize(compact('purchase_order_id', 'purchase_order_line_id', 'quantity_received', 'warehouse_id', 'received_date', 'supplier_delivery_reference', 'note')));
            $this->auditRead('mcp.procurement_receipt.previewed', ['purchase_order_id' => $purchase_order_id, 'valid' => $preview['valid'], 'issue_codes' => array_column($preview['issues'], 'code')]);

            return $preview;
        }, 'MCP_PURCHASE_ORDER_NOT_FOUND');
    }

    public function receiveProcurement(int $purchase_order_id, string $quantity_received, int $warehouse_id, string $preview_fingerprint, string $idempotency_key, ?int $purchase_order_line_id = null, ?string $received_date = null, ?string $supplier_delivery_reference = null, ?string $note = null): CallToolResult
    {
        return $this->execute('receive_procurement', function () use ($purchase_order_id, $purchase_order_line_id, $quantity_received, $warehouse_id, $received_date, $supplier_delivery_reference, $note, $preview_fingerprint, $idempotency_key) {
            $this->context->authorize('procurement_receipts.create');
            $payload = $this->receipts->normalize(compact('purchase_order_id', 'purchase_order_line_id', 'quantity_received', 'warehouse_id', 'received_date', 'supplier_delivery_reference', 'note'));

            return $this->idempotency->execute('receive_procurement', $idempotency_key, $payload,
                fn () => $this->receipts->record($payload, $preview_fingerprint, $idempotency_key));
        }, 'MCP_PURCHASE_ORDER_NOT_FOUND');
    }

    /* ---------------------------------------------------------------- Envelope */

    /** @param callable(): array<string, mixed> $callback */
    private function execute(string $tool, callable $callback, string $notFoundCode = 'MCP_ENTITY_NOT_FOUND'): CallToolResult
    {
        try {
            return $this->success($callback());
        } catch (AuthorizationException) {
            return $this->error('MCP_FORBIDDEN', 'The connection is not authorized to use this capability.');
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode() === 404
                ? $this->error($notFoundCode, 'The requested resource was not found.')
                : $this->error('MCP_FORBIDDEN', 'The ERP refused this action for the connection user (permission or store membership).');
        } catch (ModelNotFoundException) {
            return $this->error($notFoundCode, 'The requested resource was not found.');
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        } catch (LockTimeoutException) {
            return $this->error('MCP_IDEMPOTENCY_BUSY', 'An identical operation is already being processed.');
        } catch (InvalidArgumentException $exception) {
            [$code, $message] = match ($exception->getMessage()) {
                'stale_or_invalid_preview' => ['MCP_PREVIEW_STALE', 'The preview is missing, expired, consumed, or no longer matches the current data (e.g. another receipt or purchase order was recorded). Preview again.'],
                'idempotency_key_payload_mismatch' => ['MCP_IDEMPOTENCY_CONFLICT', 'The idempotency key was already used with a different request.'],
                'forbidden_fields' => ['MCP_INVALID_ARGUMENTS', 'Unknown or disallowed fields were supplied.'],
                default => ['MCP_INVALID_ARGUMENTS', 'The supplied tool arguments are invalid.'],
            };

            return $this->error($code, $message);
        } catch (Throwable $exception) {
            Log::warning('MCP purchasing tool failed.', [
                'tool' => $tool,
                'connection_id' => $this->context->connection()?->getKey(),
                'exception_type' => $exception::class,
            ]);

            return $this->error('MCP_TOOL_INTERNAL_ERROR', 'The tool could not complete the request.');
        }
    }

    /** Business refusals raised at execution time (normally caught by the preview) keep a precise code. */
    private function validationError(ValidationException $exception): CallToolResult
    {
        $errors = $exception->errors();
        $first = (string) (collect($errors)->flatten()->first() ?? '');
        [$code, $message] = match (true) {
            isset($errors['quantity']) && str_contains($first, 'dépasse') => ['MCP_RECEIPT_EXCEEDS_REMAINING', 'The received quantity exceeds what is still expected.'],
            isset($errors['quantity']) && str_contains($first, 'entre-temps') => ['MCP_STALE_VERSION', 'Another receipt was recorded meanwhile. Read the purchase order and preview again.'],
            isset($errors['status']) || isset($errors['requirement_ids']) || isset($errors['supplier_id']) => ['MCP_PROCUREMENT_NOT_READY', 'The ERP refused this procurement step in the current state.'],
            default => ['MCP_VALIDATION_FAILED', 'The candidate data is invalid.'],
        };

        return $this->error($code, $message, ['fields' => collect($errors)->take(20)
            ->map(fn ($messages) => array_values(array_map(fn ($m) => mb_substr((string) $m, 0, 200), array_slice((array) $messages, 0, 3))))->all()]);
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

    /** @param array<string, mixed> $metadata */
    private function auditRead(string $event, array $metadata): void
    {
        $this->audit->record(event: $event, actor: $this->context->user(), organization: $this->context->organization(), newValues: $metadata);
    }
}
