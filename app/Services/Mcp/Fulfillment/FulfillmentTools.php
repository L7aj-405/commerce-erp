<?php

namespace App\Services\Mcp\Fulfillment;

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
 * F1.2 shipment tools. Adapters only: membership + ERP permission + MCP scope,
 * tenant identity from the connection, store access from the user's
 * memberships; every mutation is the canonical ERP action, which writes the
 * fulfillment.* audit with the MCP connection attribution. No tool can hand
 * goods over (the stock OUT stays a web action).
 */
final class FulfillmentTools
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly McpFulfillmentService $shipments,
        private readonly McpIdempotencyService $idempotency,
        private readonly AuditLogger $audit,
    ) {}

    public function getSalesOrderShipments(int $sales_order_id): CallToolResult
    {
        return $this->execute('get_sales_order_shipments', function () use ($sales_order_id) {
            $this->context->authorize('fulfillment.view');
            $result = $this->shipments->shipments($sales_order_id);
            $this->auditRead('mcp.shipments.viewed', ['sales_order_id' => $sales_order_id, 'shipment_count' => count($result['shipments'])]);

            return $result;
        });
    }

    /** @param array<string, mixed> $tracking */
    public function previewShipmentTrackingUpdate(int $sales_order_id, string $shipment_id, array $tracking): CallToolResult
    {
        return $this->execute('preview_shipment_tracking_update', function () use ($sales_order_id, $shipment_id, $tracking) {
            $this->context->authorize('fulfillment.manage');
            $preview = $this->shipments->previewTracking($sales_order_id, $shipment_id, $this->shipments->normalizeTracking($tracking));
            $this->auditRead('mcp.shipment_tracking.previewed', ['sales_order_id' => $sales_order_id, 'shipment_id' => $shipment_id, 'valid' => $preview['valid'], 'issue_codes' => array_column($preview['issues'], 'code')]);

            return $preview;
        });
    }

    /** @param array<string, mixed> $tracking */
    public function updateShipmentTracking(int $sales_order_id, string $shipment_id, array $tracking, string $preview_fingerprint, string $idempotency_key): CallToolResult
    {
        return $this->execute('update_shipment_tracking', function () use ($sales_order_id, $shipment_id, $tracking, $preview_fingerprint, $idempotency_key) {
            $this->context->authorize('fulfillment.manage');
            $tracking = $this->shipments->normalizeTracking($tracking);

            return $this->idempotency->execute('update_shipment_tracking', $idempotency_key, ['sales_order_id' => $sales_order_id, 'shipment_id' => strtolower($shipment_id), 'tracking' => $tracking],
                fn () => $this->shipments->updateTracking($sales_order_id, $shipment_id, $tracking, $preview_fingerprint));
        });
    }

    public function previewShipmentDelivery(int $sales_order_id, string $shipment_id, ?string $delivered_at = null): CallToolResult
    {
        return $this->execute('preview_shipment_delivery', function () use ($sales_order_id, $shipment_id, $delivered_at) {
            $this->context->authorize('fulfillment.mark_delivered');
            $preview = $this->shipments->previewDelivery($sales_order_id, $shipment_id, $delivered_at);
            $this->auditRead('mcp.shipment_delivery.previewed', ['sales_order_id' => $sales_order_id, 'shipment_id' => $shipment_id, 'valid' => $preview['valid'], 'issue_codes' => array_column($preview['issues'], 'code')]);

            return $preview;
        });
    }

    public function markShipmentDelivered(int $sales_order_id, string $shipment_id, string $preview_fingerprint, string $idempotency_key, ?string $delivered_at = null): CallToolResult
    {
        return $this->execute('mark_shipment_delivered', function () use ($sales_order_id, $shipment_id, $preview_fingerprint, $idempotency_key, $delivered_at) {
            $this->context->authorize('fulfillment.mark_delivered');
            $payload = $this->shipments->deliveryPayload($delivered_at);

            return $this->idempotency->execute('mark_shipment_delivered', $idempotency_key, ['sales_order_id' => $sales_order_id, 'shipment_id' => strtolower($shipment_id), ...$payload],
                fn () => $this->shipments->markDelivered($sales_order_id, $shipment_id, $payload['delivered_at'], $preview_fingerprint));
        });
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
                ? $this->error('MCP_SHIPMENT_NOT_FOUND', 'The requested sales order or shipment was not found.')
                : $this->error('MCP_FORBIDDEN', 'The ERP refused this action for the connection user (permission or store membership).');
        } catch (ModelNotFoundException) {
            return $this->error('MCP_SHIPMENT_NOT_FOUND', 'The requested sales order or shipment was not found.');
        } catch (ValidationException $exception) {
            return $this->error('MCP_VALIDATION_FAILED', 'The candidate data is invalid.', ['fields' => $this->sanitizedErrors($exception)]);
        } catch (LockTimeoutException) {
            return $this->error('MCP_IDEMPOTENCY_BUSY', 'An identical operation is already being processed.');
        } catch (InvalidArgumentException $exception) {
            [$code, $message] = match ($exception->getMessage()) {
                'stale_or_invalid_preview' => ['MCP_PREVIEW_STALE', 'The preview is missing, expired, consumed, or no longer matches the current shipment. Preview again.'],
                'idempotency_key_payload_mismatch' => ['MCP_IDEMPOTENCY_CONFLICT', 'The idempotency key was already used with a different request.'],
                'forbidden_fields' => ['MCP_INVALID_ARGUMENTS', 'Unknown or disallowed tracking fields were supplied.'],
                default => ['MCP_INVALID_ARGUMENTS', 'The supplied tool arguments are invalid.'],
            };

            return $this->error($code, $message);
        } catch (Throwable $exception) {
            Log::warning('MCP shipment tool failed.', [
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
