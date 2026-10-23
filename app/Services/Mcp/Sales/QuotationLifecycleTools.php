<?php

namespace App\Services\Mcp\Sales;

use App\Models\Quotation;
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
 * Quotation lifecycle tools: preview + execute for issue, accept and
 * conversion to a draft Sales Order. No reject, revise, email, invoice or
 * payment is reachable from here.
 */
final class QuotationLifecycleTools
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly McpQuotationLifecycleService $lifecycle,
        private readonly QuotationReadService $quotations,
        private readonly McpIdempotencyService $idempotency,
        private readonly AuditLogger $audit,
    ) {}

    public function previewQuotationIssue(int $quotation_id): CallToolResult
    {
        return $this->preview('issue', $quotation_id);
    }

    public function issueQuotation(int $quotation_id, string $preview_fingerprint): CallToolResult
    {
        return $this->transition('issue', $quotation_id, $preview_fingerprint);
    }

    public function previewQuotationAcceptance(int $quotation_id): CallToolResult
    {
        return $this->preview('accept', $quotation_id);
    }

    public function acceptQuotation(int $quotation_id, string $preview_fingerprint): CallToolResult
    {
        return $this->transition('accept', $quotation_id, $preview_fingerprint);
    }

    public function previewQuotationConversion(int $quotation_id): CallToolResult
    {
        return $this->preview('convert', $quotation_id);
    }

    public function convertQuotationToSalesOrder(int $quotation_id, string $preview_fingerprint, string $idempotency_key): CallToolResult
    {
        return $this->execute('convert_quotation_to_sales_order', function () use ($quotation_id, $preview_fingerprint, $idempotency_key) {
            $this->context->authorize('quotations.convert');
            if ($quotation_id < 1) throw new InvalidArgumentException('invalid_id');

            // The quotation is the only input: same key + same quotation replays.
            return $this->idempotency->execute('convert_quotation_to_sales_order', $idempotency_key, ['quotation_id' => $quotation_id], function () use ($quotation_id, $preview_fingerprint) {
                $result = $this->lifecycle->execute('convert', $quotation_id, $preview_fingerprint);
                $order = $result['sales_order'];
                $this->auditMutation('convert_quotation_to_sales_order', $result['quotation'], $order);

                // Stored verbatim as the idempotent replay: compact, no contact data.
                return [
                    'target' => ['type' => 'sales_order', 'id' => $order->getKey()],
                    'quotation' => ['id' => $result['quotation']->getKey(), 'quotation_number' => $result['quotation']->quotation_number, 'status' => $result['quotation']->status->value],
                    'sales_order' => [
                        'id' => $order->getKey(), 'order_number' => $order->order_number, 'status' => $order->status->value,
                        'line_count' => $order->lines->count(), 'total_incl_tax' => (string) $order->total_incl_tax,
                    ],
                    'stock_warnings' => $result['stock_warnings'],
                ];
            });
        });
    }

    public function previewQuotationRevision(int $quotation_id, string $reason): CallToolResult
    {
        return $this->execute('preview_quotation_revision', function () use ($quotation_id, $reason) {
            $this->context->authorize(McpQuotationLifecycleService::REVISION_SCOPE);
            if ($quotation_id < 1 || trim($reason) === '' || mb_strlen($reason) > 2000) throw new InvalidArgumentException('invalid_arguments');
            $preview = $this->lifecycle->previewRevision($quotation_id, $reason);
            $this->audit->record(event: 'mcp.quotation_revision.previewed', actor: $this->context->user(), organization: $this->context->organization(), newValues: [
                'quotation_id' => $quotation_id, 'valid' => $preview['valid'],
                'issue_codes' => array_values(array_unique(array_column($preview['issues'], 'code'))),
                'fingerprint_issued' => $preview['preview_fingerprint'] !== null,
            ]);

            return $preview;
        });
    }

    public function createQuotationRevision(int $quotation_id, string $preview_fingerprint, string $idempotency_key): CallToolResult
    {
        return $this->execute('create_quotation_revision', function () use ($quotation_id, $preview_fingerprint, $idempotency_key) {
            $this->context->authorize(McpQuotationLifecycleService::REVISION_SCOPE);
            if ($quotation_id < 1) throw new InvalidArgumentException('invalid_id');

            // Same key + same source quotation replays: never two revisions.
            return $this->idempotency->execute('create_quotation_revision', $idempotency_key, ['quotation_id' => $quotation_id], function () use ($quotation_id, $preview_fingerprint) {
                ['source' => $source, 'revision' => $revision] = $this->lifecycle->createRevision($quotation_id, $preview_fingerprint);
                // The canonical `quotation.revision_started` audit is written by the ERP action.
                $this->audit->record('mcp.sales_tool_executed', $this->context->user(), $this->context->organization(), $revision->store, $revision, newValues: [
                    'tool' => 'create_quotation_revision',
                    'target' => ['type' => 'quotation', 'id' => $revision->getKey()],
                    'source_quotation' => ['id' => $source->getKey(), 'quotation_number' => $source->quotation_number],
                    'revision_number' => (int) $revision->revision_number,
                ]);

                return [
                    'target' => ['type' => 'quotation', 'id' => $revision->getKey()],
                    'source_quotation' => ['id' => $source->getKey(), 'quotation_number' => $source->quotation_number, 'status' => $source->status->value],
                    'revision' => [
                        'id' => $revision->getKey(), 'revision_number' => (int) $revision->revision_number,
                        'status' => $revision->status->value, 'editable' => $revision->status->isEditable(),
                        'totals' => $this->quotations->present($revision)['totals'],
                    ],
                ];
            });
        });
    }

    private function preview(string $transition, int $quotationId): CallToolResult
    {
        return $this->execute("preview_quotation_{$transition}", function () use ($transition, $quotationId) {
            $this->context->authorize(McpQuotationLifecycleService::scope($transition));
            $preview = $this->lifecycle->preview($transition, $quotationId);
            $this->audit->record(event: 'mcp.quotation_transition.previewed', actor: $this->context->user(), organization: $this->context->organization(), newValues: [
                'transition' => $transition, 'quotation_id' => $quotationId, 'valid' => $preview['valid'],
                'issue_codes' => array_values(array_unique(array_column($preview['issues'], 'code'))),
                'fingerprint_issued' => $preview['preview_fingerprint'] !== null,
            ]);

            return $preview;
        });
    }

    private function transition(string $transition, int $quotationId, string $fingerprint): CallToolResult
    {
        $tool = $transition === 'issue' ? 'issue_quotation' : 'accept_quotation';

        return $this->execute($tool, function () use ($transition, $quotationId, $fingerprint, $tool) {
            $this->context->authorize(McpQuotationLifecycleService::scope($transition));
            if ($quotationId < 1) throw new InvalidArgumentException('invalid_id');

            return DB::transaction(function () use ($transition, $quotationId, $fingerprint, $tool) {
                $result = $this->lifecycle->execute($transition, $quotationId, $fingerprint);
                if (! $result['replay']) $this->auditMutation($tool, $result['quotation'], null);

                return [
                    'target' => ['type' => 'quotation', 'id' => $result['quotation']->getKey()],
                    'replay' => $result['replay'],
                    'quotation' => $this->quotations->present($result['quotation']),
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
                ? $this->error('MCP_QUOTATION_NOT_FOUND', 'The requested resource was not found.')
                : $this->forbidden();
        } catch (ModelNotFoundException) {
            return $this->error('MCP_QUOTATION_NOT_FOUND', 'The requested resource was not found.');
        } catch (McpQuotationAlreadyConverted $exception) {
            return $this->error('MCP_QUOTATION_ALREADY_CONVERTED', 'This quotation was already converted; no second Sales Order was created.', [
                'sales_order_id' => $exception->salesOrderId, 'order_number' => $exception->orderNumber,
            ]);
        } catch (ValidationException $exception) {
            return $this->error('MCP_VALIDATION_FAILED', 'The ERP rejected the transition.', ['fields' => $this->sanitizedErrors($exception)]);
        } catch (LockTimeoutException) {
            return $this->error('MCP_IDEMPOTENCY_BUSY', 'An identical operation is already being processed.');
        } catch (InvalidArgumentException $exception) {
            [$code, $message] = match ($exception->getMessage()) {
                'stale_or_invalid_preview' => ['MCP_PREVIEW_STALE', 'The preview is missing, expired, consumed, or no longer matches the current quotation. Preview again.'],
                'idempotency_key_payload_mismatch' => ['MCP_IDEMPOTENCY_CONFLICT', 'The idempotency key was already used with a different request.'],
                default => ['MCP_INVALID_ARGUMENTS', 'The supplied tool arguments are invalid.'],
            };

            return $this->error($code, $message);
        } catch (Throwable $exception) {
            Log::warning('MCP quotation lifecycle tool failed.', [
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

    private function auditMutation(string $tool, Quotation $quotation, ?SalesOrder $order): void
    {
        $this->audit->record('mcp.sales_tool_executed', $this->context->user(), $this->context->organization(), $quotation->store, $quotation, newValues: [
            'tool' => $tool,
            'target' => ['type' => 'quotation', 'id' => $quotation->getKey()],
            'quotation_number' => $quotation->quotation_number,
            'status' => $quotation->status->value,
            'sales_order' => $order ? ['id' => $order->getKey(), 'order_number' => $order->order_number, 'status' => $order->status->value] : null,
            'total_incl_tax' => (string) $quotation->total_incl_tax,
        ]);
    }
}
