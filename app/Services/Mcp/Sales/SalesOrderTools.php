<?php

namespace App\Services\Mcp\Sales;

use App\Models\SalesOrder;
use App\Services\AuditLogger;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\McpIdempotencyService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Sales Order tools (MCP-7): search/read, draft preview/create/update, and an
 * explicitly previewed confirmation. No invoice, payment, cancellation or
 * deletion is reachable from here.
 */
final class SalesOrderTools
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly SalesOrderReadService $orders,
        private readonly McpSalesOrderService $service,
        private readonly McpIdempotencyService $idempotency,
        private readonly AuditLogger $audit,
    ) {}

    public function searchSalesOrders(
        ?string $query = null,
        ?string $status = null,
        ?string $payment_status = null,
        ?int $customer_id = null,
        ?int $store_id = null,
        ?string $date_from = null,
        ?string $date_to = null,
        int $page = 1,
        int $per_page = 25,
    ): CallToolResult {
        return $this->execute('search_sales_orders', function () use ($query, $status, $payment_status, $customer_id, $store_id, $date_from, $date_to, $page, $per_page) {
            $this->context->authorize('sales_orders.view');
            $result = $this->orders->search($this->context->organization(), array_filter([
                'query' => $query, 'status' => $status, 'payment_status' => $payment_status, 'customer_id' => $customer_id,
                'store_id' => $store_id, 'date_from' => $date_from, 'date_to' => $date_to, 'page' => $page, 'per_page' => $per_page,
            ], fn ($value) => $value !== null));
            $this->auditRead('mcp.sales_orders.searched', [
                'has_query' => is_string($query) && trim($query) !== '', 'status' => $status, 'payment_status' => $payment_status,
                'customer_id' => $customer_id, 'store_id' => $store_id, 'page' => $page, 'per_page' => $per_page,
                'result_count' => count($result['items']),
            ]);

            return $result;
        });
    }

    public function getSalesOrder(int $sales_order_id): CallToolResult
    {
        return $this->execute('get_sales_order', function () use ($sales_order_id) {
            $this->context->authorize('sales_orders.view');
            $order = $this->orders->find($this->context->organization(), $sales_order_id);
            $result = $this->orders->present($order, $this->context->allows('inventory.view'));
            $this->auditRead('mcp.sales_order.viewed', ['sales_order_id' => $sales_order_id]);

            return $result;
        });
    }

    /** @param array<string, mixed> $sales_order */
    public function previewSalesOrder(array $sales_order, ?int $sales_order_id = null): CallToolResult
    {
        return $this->execute('preview_sales_order', function () use ($sales_order, $sales_order_id) {
            $mode = $sales_order_id === null ? 'create' : 'update';
            $this->context->authorize(McpSalesOrderService::requiredScope($mode));
            $preview = $this->service->preview($this->service->normalize($mode, $sales_order, $sales_order_id));
            $this->auditRead('mcp.sales_order.previewed', [
                'mode' => $mode, 'sales_order_id' => $sales_order_id, 'item_count' => count($preview['lines']),
                'valid' => $preview['valid'], 'issue_codes' => array_values(array_unique(array_column($preview['issues'], 'code'))),
                'fingerprint_issued' => $preview['preview_fingerprint'] !== null,
            ]);

            return $preview;
        });
    }

    /** @param array<string, mixed> $sales_order */
    public function createSalesOrderDraft(array $sales_order, string $preview_fingerprint, string $idempotency_key): CallToolResult
    {
        return $this->execute('create_sales_order_draft', function () use ($sales_order, $preview_fingerprint, $idempotency_key) {
            $this->context->authorize('sales_orders.create');
            $payload = $this->service->normalize('create', $sales_order);

            return $this->idempotency->execute('create_sales_order_draft', $idempotency_key, $payload, function () use ($payload, $preview_fingerprint) {
                ['order' => $order, 'evaluation' => $evaluation] = $this->service->create($payload, $preview_fingerprint);
                $this->auditMutation('create_sales_order_draft', $order, $evaluation);

                // Stored verbatim as the idempotent replay: compact, no customer contact data.
                return [
                    'target' => ['type' => 'sales_order', 'id' => $order->getKey()],
                    'order_number' => $order->order_number,
                    'status' => $order->status->value,
                    'store_id' => $order->store_id,
                    'line_count' => $order->lines->count(),
                    'total_incl_tax' => (string) $order->total_incl_tax,
                    'version' => $this->orders->version($order),
                ];
            });
        });
    }

    /** @param array<string, mixed> $sales_order */
    public function updateSalesOrderDraft(int $sales_order_id, array $sales_order, string $preview_fingerprint): CallToolResult
    {
        return $this->execute('update_sales_order_draft', function () use ($sales_order_id, $sales_order, $preview_fingerprint) {
            $this->context->authorize('sales_orders.update');
            $payload = $this->service->normalize('update', $sales_order, $sales_order_id);

            return DB::transaction(function () use ($payload, $preview_fingerprint) {
                ['order' => $order, 'evaluation' => $evaluation] = $this->service->update($payload, $preview_fingerprint);
                $this->auditMutation('update_sales_order_draft', $order, $evaluation);

                return ['target' => ['type' => 'sales_order', 'id' => $order->getKey()], 'sales_order' => $this->orders->present($order, false)];
            });
        });
    }

    public function previewSalesOrderConfirmation(int $sales_order_id): CallToolResult
    {
        return $this->execute('preview_sales_order_confirmation', function () use ($sales_order_id) {
            $this->context->authorize('sales_orders.confirm');
            $preview = $this->service->previewConfirmation($sales_order_id);
            $this->auditRead('mcp.sales_order_confirmation.previewed', [
                'sales_order_id' => $sales_order_id, 'valid' => $preview['valid'],
                'issue_codes' => array_values(array_unique(array_column($preview['issues'], 'code'))),
                'fingerprint_issued' => $preview['preview_fingerprint'] !== null,
            ]);

            return $preview;
        });
    }

    public function confirmSalesOrder(int $sales_order_id, string $preview_fingerprint): CallToolResult
    {
        return $this->execute('confirm_sales_order', function () use ($sales_order_id, $preview_fingerprint) {
            $this->context->authorize('sales_orders.confirm');

            return DB::transaction(function () use ($sales_order_id, $preview_fingerprint) {
                ['order' => $order, 'replay' => $replay] = $this->service->confirm($sales_order_id, $preview_fingerprint);
                if (! $replay) {
                    $this->audit->record('mcp.sales_tool_executed', $this->context->user(), $this->context->organization(), $order->store, $order, newValues: [
                        'tool' => 'confirm_sales_order',
                        'target' => ['type' => 'sales_order', 'id' => $order->getKey()],
                        'order_number' => $order->order_number,
                        'status' => $order->status->value,
                        'total_incl_tax' => (string) $order->total_incl_tax,
                    ]);
                }

                return [
                    'target' => ['type' => 'sales_order', 'id' => $order->getKey()],
                    'order_number' => $order->order_number,
                    'status' => $order->status->value,
                    'confirmed_at' => $order->confirmed_at?->toIso8601String(),
                    'replay' => $replay,
                    'sales_order' => $this->orders->present($order, $this->context->allows('inventory.view')),
                ];
            });
        });
    }

    /** @param callable(): array<string, mixed> $callback */
    private function execute(string $tool, callable $callback): CallToolResult
    {
        try {
            return $this->success($callback());
        } catch (AuthorizationException) {
            return $this->forbidden();
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode() === 404
                ? $this->error('MCP_SALES_ORDER_NOT_FOUND', 'The requested resource was not found.')
                : $this->forbidden();
        } catch (ModelNotFoundException) {
            return $this->error('MCP_SALES_ORDER_NOT_FOUND', 'The requested resource was not found.');
        } catch (ValidationException $exception) {
            return $this->error('MCP_VALIDATION_FAILED', 'The ERP rejected the request.', ['fields' => $this->sanitizedErrors($exception)]);
        } catch (LockTimeoutException) {
            return $this->error('MCP_IDEMPOTENCY_BUSY', 'An identical operation is already being processed.');
        } catch (InvalidArgumentException $exception) {
            [$code, $message] = match ($exception->getMessage()) {
                'stale_or_invalid_preview' => ['MCP_PREVIEW_STALE', 'The preview is missing, expired, consumed, or no longer matches the current data. Preview again.'],
                'sales_order_not_editable' => ['MCP_SALES_ORDER_NOT_EDITABLE', 'Only a draft sales order can be modified.'],
                'idempotency_key_payload_mismatch' => ['MCP_IDEMPOTENCY_CONFLICT', 'The idempotency key was already used with a different request.'],
                'no_changes' => ['MCP_INVALID_ARGUMENTS', 'No header field or items were supplied.'],
                default => ['MCP_INVALID_ARGUMENTS', 'The supplied tool arguments are invalid.'],
            };

            return $this->error($code, $message);
        } catch (Throwable $exception) {
            Log::warning('MCP sales order tool failed.', [
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

    private function forbidden(): CallToolResult
    {
        return $this->error('MCP_FORBIDDEN', 'The connection is not authorized to use this capability.');
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

    /** @param array<string, mixed> $evaluation */
    private function auditMutation(string $tool, SalesOrder $order, array $evaluation): void
    {
        $actions = array_count_values(array_column($evaluation['lines'], 'action'));
        $this->audit->record('mcp.sales_tool_executed', $this->context->user(), $this->context->organization(), $order->store, $order, newValues: [
            'tool' => $tool,
            'target' => ['type' => 'sales_order', 'id' => $order->getKey()],
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'changed_fields' => $evaluation['header']['changed_fields'],
            'items' => [
                'added' => $actions['add'] ?? 0,
                'updated' => $actions['update'] ?? 0,
                'unchanged' => $actions['unchanged'] ?? 0,
                'removed' => count($evaluation['removed_lines']),
            ],
            'variant_ids' => array_slice(array_values(array_unique(array_filter(array_column($evaluation['lines'], 'product_variant_id')))), 0, 50),
            'total_incl_tax' => (string) $order->total_incl_tax,
        ]);
    }
}
