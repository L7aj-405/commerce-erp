<?php

namespace App\Services\Mcp\Sales;

use App\Models\Quotation;
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
 * Sales assistant tools (MCP-5): customer reads, quotation reads, and
 * quotation DRAFT preview/create/update. Authorization is always membership +
 * ERP permission + MCP scope; tenant identity comes only from the connection.
 */
final class SalesAssistantTools
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly CustomerReadService $customers,
        private readonly QuotationReadService $quotations,
        private readonly McpQuotationDraftService $drafts,
        private readonly McpIdempotencyService $idempotency,
        private readonly AuditLogger $audit,
        private readonly McpQuotationSourcingService $sourcing,
    ) {}

    public function searchCustomers(?string $query = null, ?string $status = null, int $page = 1, int $per_page = 25): CallToolResult
    {
        return $this->execute('search_customers', function () use ($query, $status, $page, $per_page) {
            $this->context->authorize('customers.view');
            $result = $this->customers->search($this->context->organization(), array_filter(
                ['query' => $query, 'status' => $status, 'page' => $page, 'per_page' => $per_page],
                fn ($value) => $value !== null,
            ));
            $this->auditRead('mcp.customers.searched', [
                'has_query' => is_string($query) && trim($query) !== '', 'status' => $status,
                'page' => $page, 'per_page' => $per_page, 'result_count' => count($result['items']),
            ]);

            return $result;
        });
    }

    public function getCustomer(int $customer_id): CallToolResult
    {
        return $this->execute('get_customer', function () use ($customer_id) {
            $this->context->authorize('customers.view');
            $result = $this->customers->customer($this->context->organization(), $customer_id);
            $this->auditRead('mcp.customer.viewed', ['customer_id' => $customer_id]);

            return $result;
        }, 'MCP_CUSTOMER_NOT_FOUND');
    }

    public function getCustomerBalanceSummary(int $customer_id): CallToolResult
    {
        return $this->execute('get_customer_balance_summary', function () use ($customer_id) {
            $this->context->authorize('customers.view');
            $result = $this->customers->balance($this->context->organization(), $customer_id, $this->context->user());
            $this->auditRead('mcp.customer_balance.viewed', ['customer_id' => $customer_id]);

            return $result;
        }, 'MCP_CUSTOMER_NOT_FOUND');
    }

    public function searchQuotations(
        ?string $query = null,
        ?string $status = null,
        ?int $customer_id = null,
        ?int $store_id = null,
        ?string $date_from = null,
        ?string $date_to = null,
        int $page = 1,
        int $per_page = 25,
    ): CallToolResult {
        return $this->execute('search_quotations', function () use ($query, $status, $customer_id, $store_id, $date_from, $date_to, $page, $per_page) {
            $this->context->authorize('quotations.view');
            $result = $this->quotations->search($this->context->organization(), array_filter([
                'query' => $query, 'status' => $status, 'customer_id' => $customer_id, 'store_id' => $store_id,
                'date_from' => $date_from, 'date_to' => $date_to, 'page' => $page, 'per_page' => $per_page,
            ], fn ($value) => $value !== null));
            $this->auditRead('mcp.quotations.searched', [
                'has_query' => is_string($query) && trim($query) !== '', 'status' => $status,
                'customer_id' => $customer_id, 'store_id' => $store_id,
                'page' => $page, 'per_page' => $per_page, 'result_count' => count($result['items']),
            ]);

            return $result;
        });
    }

    public function getQuotation(int $quotation_id): CallToolResult
    {
        return $this->execute('get_quotation', function () use ($quotation_id) {
            $this->context->authorize('quotations.view');
            $quotation = $this->quotations->find($this->context->organization(), $quotation_id);
            $result = $this->withSourcing($this->quotations->present($quotation), $quotation);
            $this->auditRead('mcp.quotation.viewed', ['quotation_id' => $quotation_id]);

            return $result;
        }, 'MCP_QUOTATION_NOT_FOUND');
    }

    /** @param array<string, mixed> $quotation */
    public function previewQuotation(array $quotation, ?int $quotation_id = null): CallToolResult
    {
        return $this->execute('preview_quotation', function () use ($quotation, $quotation_id) {
            $mode = $quotation_id === null ? 'create' : 'update';
            $this->context->authorize(McpQuotationDraftService::requiredScope($mode));
            $preview = $this->drafts->preview($this->drafts->normalize($mode, $quotation, $quotation_id));
            $this->auditRead('mcp.quotation.previewed', [
                'mode' => $mode, 'quotation_id' => $quotation_id, 'item_count' => count($preview['lines']),
                'valid' => $preview['valid'], 'issue_codes' => array_values(array_unique(array_column($preview['issues'], 'code'))),
                'fingerprint_issued' => $preview['preview_fingerprint'] !== null,
            ]);

            return $preview;
        }, 'MCP_QUOTATION_NOT_FOUND');
    }

    /** @param array<string, mixed> $quotation */
    public function createQuotationDraft(array $quotation, string $preview_fingerprint, string $idempotency_key): CallToolResult
    {
        return $this->execute('create_quotation_draft', function () use ($quotation, $preview_fingerprint, $idempotency_key) {
            $this->context->authorize('quotations.create');
            $payload = $this->drafts->normalize('create', $quotation);

            return $this->idempotency->execute('create_quotation_draft', $idempotency_key, $payload, function () use ($payload, $preview_fingerprint) {
                ['quotation' => $created, 'evaluation' => $evaluation] = $this->drafts->create($payload, $preview_fingerprint);
                $this->auditMutation('create_quotation_draft', $created, $evaluation);

                // Stored verbatim as the idempotent replay: keep it compact (no
                // customer contact data); get_quotation returns the full draft.
                return [
                    'target' => ['type' => 'quotation', 'id' => $created->getKey()],
                    'status' => $created->status->value,
                    'store_id' => $created->store_id,
                    'line_count' => $created->lines->count(),
                    'total_incl_tax' => (string) $created->total_incl_tax,
                    'version' => $this->quotations->version($created),
                ];
            });
        });
    }

    /** @param array<string, mixed> $quotation */
    public function updateQuotationDraft(int $quotation_id, array $quotation, string $preview_fingerprint): CallToolResult
    {
        return $this->execute('update_quotation_draft', function () use ($quotation_id, $quotation, $preview_fingerprint) {
            $this->context->authorize('quotations.update');
            $payload = $this->drafts->normalize('update', $quotation, $quotation_id);

            return DB::transaction(function () use ($payload, $preview_fingerprint) {
                ['quotation' => $updated, 'evaluation' => $evaluation] = $this->drafts->update($payload, $preview_fingerprint);
                $this->auditMutation('update_quotation_draft', $updated, $evaluation);

                return ['target' => ['type' => 'quotation', 'id' => $updated->getKey()], 'quotation' => $this->quotations->present($updated)];
            });
        }, 'MCP_QUOTATION_NOT_FOUND');
    }

    /**
     * Compact supplier-sourcing state, so the agent knows at a glance whether a
     * draft can be issued. Full request history: get_quotation_sourcing.
     *
     * @param array<string, mixed> $presented
     * @return array<string, mixed>
     */
    private function withSourcing(array $presented, Quotation $quotation): array
    {
        $summary = $this->sourcing->summary($quotation, detailed: false);
        if (! $summary['evaluated']) {
            return [...$presented, 'sourcing' => ['evaluated' => false, 'message' => $summary['message']]];
        }

        $byLine = collect($summary['lines'])->keyBy('quotation_line_id');
        $presented['lines'] = array_map(fn (array $line) => [
            ...$line,
            'sourcing' => array_diff_key($byLine->get($line['line_id'], []), ['quotation_line_id' => true]),
        ], $presented['lines']);

        return [...$presented, 'sourcing' => [
            'evaluated' => true,
            'sourcing_required' => $summary['sourcing_required'],
            'sourcing_ready' => $summary['sourcing_ready'],
            'unresolved_sourcing_count' => $summary['unresolved_count'],
        ]];
    }

    /** @param callable(): array<string, mixed> $callback */
    private function execute(string $tool, callable $callback, string $notFoundCode = 'MCP_ENTITY_NOT_FOUND'): CallToolResult
    {
        try {
            return $this->success($callback());
        } catch (AuthorizationException) {
            return $this->forbidden();
        } catch (HttpExceptionInterface $exception) {
            // abort_unless(403) from the quotation actions' own authorization.
            return $exception->getStatusCode() === 404
                ? $this->error($notFoundCode, 'The requested resource was not found.')
                : $this->forbidden();
        } catch (ModelNotFoundException) {
            return $this->error($notFoundCode, 'The requested resource was not found.');
        } catch (ValidationException $exception) {
            return $this->error('MCP_VALIDATION_FAILED', 'The candidate data is invalid.', ['fields' => $this->sanitizedErrors($exception)]);
        } catch (LockTimeoutException) {
            return $this->error('MCP_IDEMPOTENCY_BUSY', 'An identical operation is already being processed.');
        } catch (InvalidArgumentException $exception) {
            [$code, $message] = match ($exception->getMessage()) {
                'stale_or_invalid_preview' => ['MCP_PREVIEW_STALE', 'The preview is missing, expired, consumed, or no longer matches the current data. Preview again.'],
                'quotation_not_editable' => ['MCP_QUOTATION_NOT_EDITABLE', 'Only a draft quotation can be modified.'],
                'idempotency_key_payload_mismatch' => ['MCP_IDEMPOTENCY_CONFLICT', 'The idempotency key was already used with a different request.'],
                'no_changes' => ['MCP_INVALID_ARGUMENTS', 'No header field or items were supplied.'],
                default => ['MCP_INVALID_ARGUMENTS', 'The supplied tool arguments are invalid.'],
            };

            return $this->error($code, $message);
        } catch (Throwable $exception) {
            Log::warning('MCP sales tool failed.', [
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

    /**
     * Compact attribution on top of the quotation actions' own audit entries:
     * which tool, which Devis, what changed — never the full payload.
     *
     * @param array<string, mixed> $evaluation
     */
    private function auditMutation(string $tool, Quotation $quotation, array $evaluation): void
    {
        $actions = array_count_values(array_column($evaluation['lines'], 'action'));
        $this->audit->record(
            'mcp.sales_tool_executed',
            $this->context->user(),
            $this->context->organization(),
            $quotation->store,
            $quotation,
            newValues: [
                'tool' => $tool,
                'target' => ['type' => 'quotation', 'id' => $quotation->getKey()],
                'status' => $quotation->status->value,
                'changed_fields' => $evaluation['header']['changed_fields'],
                'items' => [
                    'added' => $actions['add'] ?? 0,
                    'updated' => $actions['update'] ?? 0,
                    'unchanged' => $actions['unchanged'] ?? 0,
                    'removed' => count($evaluation['removed_lines']),
                ],
                'variant_ids' => array_slice(array_values(array_unique(array_filter(array_map(
                    fn (array $line) => $line['data']['product_variant_id'] ?? null, $evaluation['lines'],
                )))), 0, 50),
                'total_incl_tax' => (string) $quotation->total_incl_tax,
            ],
        );
    }
}
