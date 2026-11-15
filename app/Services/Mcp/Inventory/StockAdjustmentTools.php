<?php

namespace App\Services\Mcp\Inventory;

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
 * preview_stock_adjustment / apply_stock_adjustment. Adapters only: MCP scope +
 * current ERP permission (inventory.adjust, plus inventory.view to read the
 * balance), organization from the connection, then the canonical ERP action.
 */
final class StockAdjustmentTools
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly McpStockAdjustmentService $service,
        private readonly McpIdempotencyService $idempotency,
        private readonly AuditLogger $audit,
    ) {}

    public function previewStockAdjustment(int $variant_id, int $warehouse_id, string $operation, int|float|string $quantity, string $reason, ?string $reference = null): CallToolResult
    {
        return $this->execute('preview_stock_adjustment', function () use ($variant_id, $warehouse_id, $operation, $quantity, $reason, $reference) {
            $this->authorizeAdjust();
            $payload = $this->service->normalize($variant_id, $warehouse_id, $operation, $quantity, $reason, $reference);
            $preview = $this->service->preview($payload);
            $this->auditRead('mcp.stock_adjustment.previewed', ['variant_id' => $variant_id, 'warehouse_id' => $warehouse_id, 'operation' => $operation, 'valid' => $preview['valid'], 'issue_codes' => array_column($preview['issues'], 'code')]);

            return $preview;
        });
    }

    public function applyStockAdjustment(int $variant_id, int $warehouse_id, string $operation, int|float|string $quantity, string $reason, string $preview_fingerprint, string $idempotency_key, ?string $reference = null): CallToolResult
    {
        return $this->execute('apply_stock_adjustment', function () use ($variant_id, $warehouse_id, $operation, $quantity, $reason, $reference, $preview_fingerprint, $idempotency_key) {
            $this->authorizeAdjust();
            $payload = $this->service->normalize($variant_id, $warehouse_id, $operation, $quantity, $reason, $reference);

            return $this->idempotency->execute('apply_stock_adjustment', $idempotency_key, $payload,
                fn () => $this->service->apply($payload, $preview_fingerprint));
        });
    }

    private function authorizeAdjust(): void
    {
        $this->context->authorize('inventory.adjust');
        $this->context->authorize('inventory.view');
    }

    /* ---------------------------------------------------------------- Envelope */

    /** @param callable(): array<string, mixed> $callback */
    private function execute(string $tool, callable $callback): CallToolResult
    {
        try {
            return $this->success($callback());
        } catch (AuthorizationException) {
            return $this->error('MCP_FORBIDDEN', 'The connection is not authorized to use this capability.');
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode() === 404
                ? $this->error('MCP_STOCK_TARGET_NOT_FOUND', 'The requested variant or warehouse was not found.')
                : $this->error('MCP_FORBIDDEN', 'The ERP refused this action for the connection user.');
        } catch (ModelNotFoundException) {
            return $this->error('MCP_STOCK_TARGET_NOT_FOUND', 'The requested variant or warehouse was not found.');
        } catch (ValidationException $exception) {
            return $this->error('MCP_VALIDATION_FAILED', 'The stock adjustment is invalid.', ['fields' => $this->sanitizedErrors($exception)]);
        } catch (LockTimeoutException) {
            return $this->error('MCP_IDEMPOTENCY_BUSY', 'An identical operation is already being processed.');
        } catch (InvalidArgumentException $exception) {
            [$code, $message] = match ($exception->getMessage()) {
                'stale_or_invalid_preview' => ['MCP_PREVIEW_STALE', 'The preview is missing, expired, consumed, or stock changed since it was issued. Preview again.'],
                'idempotency_key_payload_mismatch' => ['MCP_IDEMPOTENCY_CONFLICT', 'The idempotency key was already used with a different request.'],
                'reason_required' => ['MCP_VALIDATION_FAILED', 'A reason is required for a manual stock adjustment.'],
                default => ['MCP_INVALID_ARGUMENTS', 'The supplied tool arguments are invalid.'],
            };

            return $this->error($code, $message);
        } catch (Throwable $exception) {
            Log::warning('MCP stock adjustment tool failed.', [
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
        if ($details !== []) {
            $error['details'] = $details;
        }
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
