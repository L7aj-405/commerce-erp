<?php

namespace App\Services\Mcp\Procurement;

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

/** B1 supplier catalog tools: which suppliers supply which variant, on which terms. */
final class SupplierCatalogTools
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly McpSupplierCatalogService $catalog,
        private readonly McpIdempotencyService $idempotency,
        private readonly AuditLogger $audit,
    ) {}

    public function getVariantSuppliers(?int $variant_id = null, ?string $sku = null, bool $include_inactive = false): CallToolResult
    {
        return $this->execute('get_variant_suppliers', fn () => $this->catalog->variantSuppliers($variant_id, $sku, $include_inactive), 'MCP_VARIANT_NOT_FOUND');
    }

    public function getSupplierProducts(int $supplier_id, ?string $status = null, ?string $query = null, int $page = 1, int $per_page = 25): CallToolResult
    {
        return $this->execute('get_supplier_products', fn () => $this->catalog->supplierProducts($supplier_id, $status, $query, $page, $per_page), 'MCP_SUPPLIER_NOT_FOUND');
    }

    public function recommendSuppliersForQuotationLine(int $quotation_id, int $quotation_line_id): CallToolResult
    {
        return $this->execute('recommend_suppliers_for_quotation_line', fn () => $this->catalog->recommendForQuotationLine($quotation_id, $quotation_line_id), 'MCP_QUOTATION_NOT_FOUND');
    }

    /** @param array<string, mixed> $relation */
    public function previewSupplierProductRelation(int $supplier_id, int $product_variant_id, array $relation = []): CallToolResult
    {
        return $this->execute('preview_supplier_product_relation', function () use ($supplier_id, $product_variant_id, $relation) {
            $preview = $this->catalog->preview($this->catalog->normalize($supplier_id, $product_variant_id, $relation));
            $this->audit->record(event: 'mcp.supplier_catalog.previewed', actor: $this->context->user(), organization: $this->context->organization(), newValues: [
                'supplier_id' => $supplier_id, 'product_variant_id' => $product_variant_id, 'mode' => $preview['mode'], 'valid' => $preview['valid'],
            ]);

            return $preview;
        });
    }

    /** @param array<string, mixed> $relation */
    public function saveSupplierProductRelation(int $supplier_id, int $product_variant_id, array $relation, string $preview_fingerprint, string $idempotency_key): CallToolResult
    {
        return $this->execute('save_supplier_product_relation', function () use ($supplier_id, $product_variant_id, $relation, $preview_fingerprint, $idempotency_key) {
            $payload = $this->catalog->normalize($supplier_id, $product_variant_id, $relation);

            return $this->idempotency->execute('save_supplier_product_relation', $idempotency_key, $payload, function () use ($payload, $preview_fingerprint) {
                $saved = $this->catalog->save($payload, $preview_fingerprint);

                return ['target' => ['type' => 'product_variant_supplier', 'id' => (int) $saved->getKey()], 'relation' => $this->catalog->relation($saved)];
            });
        });
    }

    private function execute(string $tool, callable $callback, string $notFoundCode = 'MCP_ENTITY_NOT_FOUND'): CallToolResult
    {
        try {
            return $this->success($callback());
        } catch (AuthorizationException) {
            return $this->error('MCP_FORBIDDEN', 'The connection is not authorized to use this capability.');
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode() === 404
                ? $this->error($notFoundCode, 'The requested resource was not found.')
                : $this->error('MCP_FORBIDDEN', 'The ERP refused this action for the connection user.');
        } catch (ModelNotFoundException) {
            return $this->error($notFoundCode, 'The requested resource was not found.');
        } catch (ValidationException $exception) {
            return $this->error('MCP_VALIDATION_FAILED', 'The candidate data is invalid.', ['fields' => collect($exception->errors())->take(20)
                ->map(fn ($messages) => array_map(fn ($message) => mb_substr((string) $message, 0, 200), array_slice((array) $messages, 0, 3)))->all()]);
        } catch (LockTimeoutException) {
            return $this->error('MCP_IDEMPOTENCY_BUSY', 'An identical operation is already being processed.');
        } catch (InvalidArgumentException $exception) {
            [$code, $message] = match ($exception->getMessage()) {
                'stale_or_invalid_preview' => ['MCP_PREVIEW_STALE', 'The preview is missing, expired, consumed, or no longer matches the current relation or preferred supplier. Preview again.'],
                'idempotency_key_payload_mismatch' => ['MCP_IDEMPOTENCY_CONFLICT', 'The idempotency key was already used with a different request.'],
                'forbidden_fields' => ['MCP_INVALID_ARGUMENTS', 'Unknown or disallowed fields were supplied.'],
                'price_access_required' => ['MCP_FORBIDDEN', 'Setting a supplier purchase price needs purchase-cost visibility (product_cost.view or purchase_orders.view scope and permission).'],
                'variant_locator_required' => ['MCP_INVALID_ARGUMENTS', 'Provide exactly one of variant_id or sku.'],
                default => ['MCP_INVALID_ARGUMENTS', 'The supplied tool arguments are invalid.'],
            };

            return $this->error($code, $message);
        } catch (Throwable $exception) {
            Log::warning('MCP supplier catalog tool failed.', ['tool' => $tool, 'connection_id' => $this->context->connection()?->getKey(), 'exception_type' => $exception::class]);

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
}
