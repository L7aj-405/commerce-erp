<?php

namespace App\Services\Mcp\Products;

use App\Models\McpOperation;
use App\Models\McpOperationItem;
use App\Services\Mcp\McpExecutionContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Throwable;

final class ProductOperationTools
{
    private const ERRORS = [
        'idempotency_key_payload_mismatch' => ['MCP_IDEMPOTENCY_CONFLICT', 'The idempotency key was already used with a different request.'],
        'stale_or_invalid_preview' => ['MCP_PREVIEW_STALE', 'The preview is missing, expired, consumed, or no longer matches the current targets. Prepare a new operation.'],
        'operation_not_ready' => ['MCP_OPERATION_NOT_READY', 'The operation is still being validated. Poll get_product_operation.'],
        'nothing_to_execute' => ['MCP_OPERATION_NOTHING_TO_EXECUTE', 'The preview contains no changed rows.'],
        'too_many_active_operations' => ['MCP_OPERATION_BUSY', 'Too many operations are already queued or running for this connection.'],
        'operation_details_expired' => ['MCP_OPERATION_DETAILS_EXPIRED', 'Per-row details for this operation are past retention.'],
        'too_many_rows' => ['MCP_OPERATION_TOO_LARGE', 'The operation exceeds the maximum number of rows.'],
        'rate_limited' => ['MCP_RATE_LIMITED', 'The bulk operation rate limit has been reached.'],
    ];

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly McpProductOperationService $operations,
    ) {}

    /** @param list<mixed> $rows */
    public function prepareProductBulkUpdate(array $rows, string $idempotency_key): CallToolResult
    {
        return $this->prepare(McpOperation::TYPE_PRODUCT_UPDATE, $rows, $idempotency_key);
    }

    /** @param list<mixed> $rows */
    public function preparePurchasePriceBulkUpdate(array $rows, string $idempotency_key): CallToolResult
    {
        return $this->prepare(McpOperation::TYPE_PURCHASE_PRICE_UPDATE, $rows, $idempotency_key);
    }

    /** @param list<mixed> $rows */
    public function prepareProductBulkArchive(array $rows, string $idempotency_key): CallToolResult
    {
        return $this->prepare(McpOperation::TYPE_PRODUCT_ARCHIVE, $rows, $idempotency_key);
    }

    public function executeProductOperation(string $operation_id, string $preview_fingerprint): CallToolResult
    {
        return $this->execute('execute_product_operation', function () use ($operation_id, $preview_fingerprint) {
            $this->throttle('execute', (int) config('mcp.operations.execute_per_minute', 10));

            return $this->operations->execute($operation_id, $preview_fingerprint);
        });
    }

    public function getProductOperation(string $operation_id): CallToolResult
    {
        return $this->execute('get_product_operation', fn () => $this->operations->show($operation_id));
    }

    public function getProductOperationItems(string $operation_id, int $page = 1, int $per_page = 50, ?string $status = null, ?string $preview_status = null): CallToolResult
    {
        return $this->execute('get_product_operation_items', function () use ($operation_id, $page, $per_page, $status, $preview_status) {
            $statuses = [McpOperationItem::STATUS_READY, McpOperationItem::STATUS_EXCLUDED, McpOperationItem::STATUS_PENDING, McpOperationItem::STATUS_SUCCEEDED, McpOperationItem::STATUS_FAILED];
            $previews = [McpOperationItem::PREVIEW_CHANGED, McpOperationItem::PREVIEW_UNCHANGED, McpOperationItem::PREVIEW_SKIPPED, McpOperationItem::PREVIEW_INVALID, McpOperationItem::PREVIEW_CONFLICT];
            if (($status !== null && ! in_array($status, $statuses, true)) || ($preview_status !== null && ! in_array($preview_status, $previews, true))) {
                throw new InvalidArgumentException('invalid_filter');
            }

            return $this->operations->items($operation_id, $page, $per_page, $status, $preview_status);
        });
    }

    /**
     * Product import/merge: previews the rows as an operation and returns the
     * first page of per-row results with the counts.
     *
     * @param list<mixed> $rows
     */
    public function previewProductImport(array $rows, string $idempotency_key): CallToolResult
    {
        return $this->execute('preview_product_import', function () use ($rows, $idempotency_key) {
            $this->throttle('prepare', (int) config('mcp.operations.prepare_per_minute', 10));
            $view = $this->operations->prepare(McpOperation::TYPE_PRODUCT_IMPORT, $rows, $idempotency_key);
            // A synchronous queue validates large imports inline and releases the connection binding afterwards.
            if ($view['status'] !== McpOperation::STATUS_VALIDATING && $view['details_available'] && $this->context->isBound()) {
                $view['rows'] = $this->operations->items($view['operation_id'], 1, 50, null, null, McpOperation::TYPE_PRODUCT_IMPORT);
            }

            return $view;
        });
    }

    public function executeProductImport(string $operation_id, string $preview_fingerprint): CallToolResult
    {
        return $this->execute('execute_product_import', function () use ($operation_id, $preview_fingerprint) {
            $this->throttle('execute', (int) config('mcp.operations.execute_per_minute', 10));

            return $this->operations->execute($operation_id, $preview_fingerprint, McpOperation::TYPE_PRODUCT_IMPORT);
        });
    }

    public function getProductImport(string $operation_id): CallToolResult
    {
        return $this->execute('get_product_import', fn () => $this->operations->show($operation_id, McpOperation::TYPE_PRODUCT_IMPORT));
    }

    public function getProductImportItems(string $operation_id, int $page = 1, int $per_page = 50, ?string $action = null): CallToolResult
    {
        return $this->execute('get_product_import_items', function () use ($operation_id, $page, $per_page, $action) {
            // Actions are the import vocabulary over the generic preview/execution statuses.
            [$status, $preview] = match ($action) {
                null => [null, null],
                'create', 'update' => [null, McpOperationItem::PREVIEW_CHANGED],
                'unchanged' => [null, McpOperationItem::PREVIEW_UNCHANGED],
                'skipped' => [null, McpOperationItem::PREVIEW_SKIPPED],
                'invalid' => [null, McpOperationItem::PREVIEW_INVALID],
                'conflict' => [null, McpOperationItem::PREVIEW_CONFLICT],
                'failed' => [McpOperationItem::STATUS_FAILED, null],
                'succeeded' => [McpOperationItem::STATUS_SUCCEEDED, null],
                default => throw new InvalidArgumentException('invalid_filter'),
            };
            $targetType = match ($action) {
                'create' => McpProductImportPlanner::TARGET_CREATE,
                'update' => McpProductImportPlanner::TARGET_UPDATE,
                default => null,
            };

            return $this->operations->items($operation_id, $page, $per_page, $status, $preview, McpOperation::TYPE_PRODUCT_IMPORT, $targetType);
        });
    }

    /** @param list<mixed> $rows */
    private function prepare(string $type, array $rows, string $key): CallToolResult
    {
        return $this->execute('prepare_'.$type, function () use ($type, $rows, $key) {
            $this->throttle('prepare', (int) config('mcp.operations.prepare_per_minute', 10));

            return $this->operations->prepare($type, $rows, $key);
        });
    }

    private function throttle(string $action, int $perMinute): void
    {
        $key = 'mcp-bulk-'.$action.':'.$this->context->connectionOrFail()->getKey();
        if (RateLimiter::tooManyAttempts($key, max(1, $perMinute))) {
            throw new InvalidArgumentException('rate_limited');
        }
        RateLimiter::hit($key, 60);
    }

    /** @param callable(): array<string, mixed> $callback */
    private function execute(string $tool, callable $callback): CallToolResult
    {
        try {
            return $this->success($callback());
        } catch (AuthorizationException) {
            return $this->error('MCP_FORBIDDEN', 'The connection is not authorized to use this capability.');
        } catch (ModelNotFoundException) {
            return $this->error('MCP_ENTITY_NOT_FOUND', 'The requested operation was not found.');
        } catch (LockTimeoutException) {
            return $this->error('MCP_IDEMPOTENCY_BUSY', 'An identical operation is already being processed.');
        } catch (InvalidArgumentException $exception) {
            [$code, $message] = self::ERRORS[$exception->getMessage()] ?? ['MCP_INVALID_ARGUMENTS', 'The supplied tool arguments are invalid.'];

            return $this->error($code, $message);
        } catch (Throwable $exception) {
            Log::warning('MCP product operation tool failed.', [
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

    private function error(string $code, string $message): CallToolResult
    {
        $payload = ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];

        return new CallToolResult([new TextContent($payload)], isError: true, structuredContent: $payload);
    }
}
