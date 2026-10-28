<?php

namespace App\Services\Mcp\Procurement;

use App\Services\AuditLogger;
use App\Services\Mcp\Catalog\McpNonStockItemChangeService;
use App\Services\Mcp\Catalog\NonStockItemReadService;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\McpIdempotencyService;
use App\Services\Mcp\Sales\McpQuotationSourcingService;
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
 * MCP-W7: suppliers, reusable non-stock articles and quotation supplier
 * sourcing. Adapters only — authorization is membership + ERP permission +
 * MCP scope, tenant identity comes from the connection, and every mutation is
 * the canonical ERP action (which writes the audit trail with the MCP
 * connection attribution).
 */
final class SupplierSourcingTools
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly SupplierReadService $suppliers,
        private readonly McpSupplierChangeService $supplierChanges,
        private readonly NonStockItemReadService $items,
        private readonly McpNonStockItemChangeService $itemChanges,
        private readonly McpQuotationSourcingService $sourcing,
        private readonly McpIdempotencyService $idempotency,
        private readonly AuditLogger $audit,
    ) {}

    /* ---------------------------------------------------------------- Suppliers */

    public function searchSuppliers(?string $query = null, ?string $status = null, int $page = 1, int $per_page = 25): CallToolResult
    {
        return $this->execute('search_suppliers', function () use ($query, $status, $page, $per_page) {
            $this->context->authorize('suppliers.view');
            $result = $this->suppliers->search($this->context->organization(), compact('query', 'status', 'page', 'per_page'));
            $this->auditRead('mcp.suppliers.searched', ['has_query' => is_string($query) && trim($query) !== '', 'status' => $status, 'page' => $page, 'result_count' => count($result['items'])]);

            return $result;
        });
    }

    public function getSupplier(int $supplier_id): CallToolResult
    {
        return $this->execute('get_supplier', function () use ($supplier_id) {
            $this->context->authorize('suppliers.view');
            $result = $this->suppliers->supplier($this->context->organization(), $supplier_id);
            $this->auditRead('mcp.supplier.viewed', ['supplier_id' => $supplier_id]);

            return $result;
        }, 'MCP_SUPPLIER_NOT_FOUND');
    }

    /** @param array<string, mixed> $supplier */
    public function previewSupplierChanges(array $supplier, ?int $supplier_id = null): CallToolResult
    {
        return $this->execute('preview_supplier_changes', function () use ($supplier, $supplier_id) {
            $mode = $supplier_id === null ? 'create' : 'update';
            $this->context->authorize(McpSupplierChangeService::requiredScope($mode));
            $preview = $this->supplierChanges->preview($this->supplierChanges->normalize($mode, $supplier, $supplier_id));
            $this->auditRead('mcp.supplier.previewed', ['mode' => $mode, 'supplier_id' => $supplier_id, 'changed_fields' => $preview['changed_fields'], 'valid' => $preview['valid']]);

            return $preview;
        }, 'MCP_SUPPLIER_NOT_FOUND');
    }

    /** @param array<string, mixed> $supplier */
    public function createSupplier(array $supplier, string $preview_fingerprint, string $idempotency_key): CallToolResult
    {
        return $this->execute('create_supplier', function () use ($supplier, $preview_fingerprint, $idempotency_key) {
            $this->context->authorize('suppliers.create');
            $payload = $this->supplierChanges->normalize('create', $supplier);

            return $this->idempotency->execute('create_supplier', $idempotency_key, $payload, function () use ($payload, $preview_fingerprint) {
                $created = $this->supplierChanges->create($payload, $preview_fingerprint);

                return ['target' => ['type' => 'supplier', 'id' => $created->getKey()], 'supplier' => $this->suppliers->summary($created)];
            });
        });
    }

    /** @param array<string, mixed> $supplier */
    public function updateSupplier(int $supplier_id, array $supplier, string $preview_fingerprint): CallToolResult
    {
        return $this->execute('update_supplier', function () use ($supplier_id, $supplier, $preview_fingerprint) {
            $this->context->authorize('suppliers.update');
            $payload = $this->supplierChanges->normalize('update', $supplier, $supplier_id);

            return DB::transaction(function () use ($payload, $preview_fingerprint) {
                $updated = $this->supplierChanges->update($payload, $preview_fingerprint);

                return ['target' => ['type' => 'supplier', 'id' => $updated->getKey()], 'supplier' => $this->suppliers->supplier($this->context->organization(), (int) $updated->getKey())];
            });
        }, 'MCP_SUPPLIER_NOT_FOUND');
    }

    /* ---------------------------------------------------------------- Non-stock articles */

    public function searchNonStockItems(?string $query = null, ?string $status = null, int $page = 1, int $per_page = 25): CallToolResult
    {
        return $this->execute('search_non_stock_items', function () use ($query, $status, $page, $per_page) {
            $this->context->authorize('non_stock_items.view');
            $result = $this->items->search($this->context->organization(), compact('query', 'status', 'page', 'per_page'));
            $this->auditRead('mcp.non_stock_items.searched', ['has_query' => is_string($query) && trim($query) !== '', 'status' => $status, 'page' => $page, 'result_count' => count($result['items'])]);

            return $result;
        });
    }

    public function getNonStockItem(int $non_stock_item_id): CallToolResult
    {
        return $this->execute('get_non_stock_item', function () use ($non_stock_item_id) {
            $this->context->authorize('non_stock_items.view');
            $result = $this->items->item($this->context->organization(), $non_stock_item_id);
            $this->auditRead('mcp.non_stock_item.viewed', ['non_stock_item_id' => $non_stock_item_id]);

            return $result;
        }, 'MCP_NON_STOCK_ITEM_NOT_FOUND');
    }

    /** @param array<string, mixed> $non_stock_item */
    public function previewNonStockItemChanges(array $non_stock_item, ?int $non_stock_item_id = null): CallToolResult
    {
        return $this->execute('preview_non_stock_item_changes', function () use ($non_stock_item, $non_stock_item_id) {
            $mode = $non_stock_item_id === null ? 'create' : 'update';
            $this->context->authorize(McpNonStockItemChangeService::requiredScope($mode));
            $preview = $this->itemChanges->preview($this->itemChanges->normalize($mode, $non_stock_item, $non_stock_item_id));
            $this->auditRead('mcp.non_stock_item.previewed', ['mode' => $mode, 'non_stock_item_id' => $non_stock_item_id, 'valid' => $preview['valid']]);

            return $preview;
        }, 'MCP_NON_STOCK_ITEM_NOT_FOUND');
    }

    /** @param array<string, mixed> $non_stock_item */
    public function createNonStockItem(array $non_stock_item, string $preview_fingerprint, string $idempotency_key): CallToolResult
    {
        return $this->execute('create_non_stock_item', function () use ($non_stock_item, $preview_fingerprint, $idempotency_key) {
            $this->context->authorize('non_stock_items.create');
            $payload = $this->itemChanges->normalize('create', $non_stock_item);

            return $this->idempotency->execute('create_non_stock_item', $idempotency_key, $payload, function () use ($payload, $preview_fingerprint) {
                ['item' => $item, 'reused' => $reused] = $this->itemChanges->create($payload, $preview_fingerprint);

                return ['target' => ['type' => 'non_stock_item', 'id' => $item->getKey()], 'non_stock_item' => $this->items->present($item), 'reused_existing' => $reused];
            });
        });
    }

    /** @param array<string, mixed> $non_stock_item */
    public function updateNonStockItem(int $non_stock_item_id, array $non_stock_item, string $preview_fingerprint): CallToolResult
    {
        return $this->execute('update_non_stock_item', function () use ($non_stock_item_id, $non_stock_item, $preview_fingerprint) {
            $this->context->authorize('non_stock_items.update');
            $payload = $this->itemChanges->normalize('update', $non_stock_item, $non_stock_item_id);

            return DB::transaction(function () use ($payload, $preview_fingerprint) {
                $item = $this->itemChanges->update($payload, $preview_fingerprint);

                return ['target' => ['type' => 'non_stock_item', 'id' => $item->getKey()], 'non_stock_item' => $this->items->item($this->context->organization(), (int) $item->getKey())];
            });
        }, 'MCP_NON_STOCK_ITEM_NOT_FOUND');
    }

    /* ---------------------------------------------------------------- Quotation sourcing */

    public function getQuotationSourcing(int $quotation_id): CallToolResult
    {
        return $this->execute('get_quotation_sourcing', function () use ($quotation_id) {
            $this->context->authorize('quotation_sourcing.view');
            $result = $this->sourcing->sourcing($quotation_id);
            $this->auditRead('mcp.quotation_sourcing.viewed', ['quotation_id' => $quotation_id]);

            return $result;
        }, 'MCP_QUOTATION_NOT_FOUND');
    }

    public function createSupplierAvailabilityRequest(
        int $quotation_id,
        int $quotation_line_id,
        int $supplier_id,
        string $idempotency_key,
        ?string $requested_quantity = null,
        ?string $note = null,
    ): CallToolResult {
        return $this->execute('create_supplier_availability_request', function () use ($quotation_id, $quotation_line_id, $supplier_id, $idempotency_key, $requested_quantity, $note) {
            $this->context->authorize('quotation_sourcing.create');
            $payload = [
                'quotation_id' => $quotation_id, 'quotation_line_id' => $quotation_line_id, 'supplier_id' => $supplier_id,
                'requested_quantity' => $requested_quantity === null ? null : trim($requested_quantity),
                'note' => $note === null || trim($note) === '' ? null : trim($note),
            ];

            return $this->idempotency->execute('create_supplier_availability_request', $idempotency_key, $payload,
                fn () => $this->sourcing->createRequest($payload));
        }, 'MCP_QUOTATION_NOT_FOUND');
    }

    public function recordSupplierAvailabilityResponse(
        int $quotation_id,
        int $supplier_request_id,
        string $confirmed_quantity,
        ?string $note = null,
        ?string $expected_status = null,
    ): CallToolResult {
        return $this->execute('record_supplier_availability_response', function () use ($quotation_id, $supplier_request_id, $confirmed_quantity, $note, $expected_status) {
            $this->context->authorize('quotation_sourcing.update');

            return $this->sourcing->recordResponse($quotation_id, $supplier_request_id, trim($confirmed_quantity), $note === null || trim($note) === '' ? null : trim($note), $expected_status);
        }, 'MCP_QUOTATION_NOT_FOUND');
    }

    public function cancelSupplierAvailabilityRequest(int $quotation_id, int $supplier_request_id, ?string $expected_status = null): CallToolResult
    {
        return $this->execute('cancel_supplier_availability_request', function () use ($quotation_id, $supplier_request_id, $expected_status) {
            $this->context->authorize('quotation_sourcing.update');

            return $this->sourcing->cancelRequest($quotation_id, $supplier_request_id, $expected_status);
        }, 'MCP_QUOTATION_NOT_FOUND');
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
            // Canonical ERP actions refuse with abort(403/404): same answer as a missing record or capability.
            return $exception->getStatusCode() === 404
                ? $this->error($notFoundCode, 'The requested resource was not found.')
                : $this->error('MCP_FORBIDDEN', 'The ERP refused this action for the connection user (permission or store membership).');
        } catch (ModelNotFoundException) {
            return $this->error($notFoundCode, 'The requested resource was not found.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            if (isset($errors['version'])) {
                return $this->error('MCP_STALE_VERSION', 'The record changed since it was read. Read it again, then retry.');
            }
            if (count($errors) === 1 && (isset($errors['status']) || isset($errors['quotation']))) {
                return $this->error('MCP_INVALID_STATE', mb_substr((string) collect($errors)->flatten()->first(), 0, 200));
            }

            return $this->error('MCP_VALIDATION_FAILED', 'The candidate data is invalid.', ['fields' => $this->sanitizedErrors($exception)]);
        } catch (LockTimeoutException) {
            return $this->error('MCP_IDEMPOTENCY_BUSY', 'An identical operation is already being processed.');
        } catch (InvalidArgumentException $exception) {
            [$code, $message] = match ($exception->getMessage()) {
                'stale_or_invalid_preview' => ['MCP_PREVIEW_STALE', 'The preview is missing, expired, consumed, or no longer matches the current data. Preview again.'],
                'idempotency_key_payload_mismatch' => ['MCP_IDEMPOTENCY_CONFLICT', 'The idempotency key was already used with a different request.'],
                'forbidden_fields' => ['MCP_INVALID_ARGUMENTS', 'Unknown or disallowed fields were supplied.'],
                'no_changes' => ['MCP_INVALID_ARGUMENTS', 'No field was supplied.'],
                default => ['MCP_INVALID_ARGUMENTS', 'The supplied tool arguments are invalid.'],
            };

            return $this->error($code, $message);
        } catch (Throwable $exception) {
            Log::warning('MCP supplier/sourcing tool failed.', [
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
