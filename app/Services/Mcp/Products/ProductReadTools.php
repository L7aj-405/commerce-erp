<?php

namespace App\Services\Mcp\Products;

use App\Services\AuditLogger;
use App\Services\Mcp\McpExecutionContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Throwable;

final class ProductReadTools
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly ProductCatalogReadService $catalog,
        private readonly ProductStockReadService $stock,
        private readonly AuditLogger $audit,
    ) {}

    public function searchProducts(
        ?string $query = null,
        ?string $status = null,
        ?int $brand_id = null,
        ?int $category_id = null,
        int $page = 1,
        int $per_page = 25,
    ): CallToolResult {
        return $this->execute('search_products', function () use ($query, $status, $brand_id, $category_id, $page, $per_page) {
            $this->context->authorize('products.view');
            $filters = array_filter([
                'query' => $query,
                'status' => $status,
                'brand_id' => $brand_id,
                'category_id' => $category_id,
                'page' => $page,
                'per_page' => $per_page,
            ], fn ($value) => $value !== null);
            $result = $this->catalog->search($this->context->organization(), $filters);

            $this->audit->record(
                event: 'mcp.products.searched',
                actor: $this->context->user(),
                organization: $this->context->organization(),
                newValues: [
                    'has_query' => is_string($query) && trim($query) !== '',
                    'status' => $status,
                    'brand_id' => $brand_id,
                    'category_id' => $category_id,
                    'page' => $page,
                    'per_page' => $per_page,
                    'result_count' => count($result['items']),
                ],
            );

            return $result;
        });
    }

    public function getProduct(int $product_id): CallToolResult
    {
        return $this->execute('get_product', function () use ($product_id) {
            $this->context->authorize('products.view');
            $this->positiveId($product_id);
            $result = $this->catalog->product(
                $this->context->organization(),
                $product_id,
                $this->context->allows('product_cost.view'),
            );
            $this->auditRead('mcp.product.viewed', ['product_id' => $product_id]);

            return $result;
        }, 'MCP_PRODUCT_NOT_FOUND');
    }

    public function getProductVariant(int $variant_id): CallToolResult
    {
        return $this->execute('get_product_variant', function () use ($variant_id) {
            $this->context->authorize('products.view');
            $this->positiveId($variant_id);
            $result = $this->catalog->variant(
                $this->context->organization(),
                $variant_id,
                $this->context->allows('product_cost.view'),
            );
            $this->auditRead('mcp.product_variant.viewed', ['product_variant_id' => $variant_id]);

            return $result;
        }, 'MCP_VARIANT_NOT_FOUND');
    }

    public function getProductStock(?int $product_id = null, ?int $variant_id = null): CallToolResult
    {
        return $this->execute('get_product_stock', function () use ($product_id, $variant_id) {
            $this->context->authorize('products.view');
            $this->context->authorize('inventory.view');

            if (($product_id === null) === ($variant_id === null)) {
                throw new InvalidArgumentException('exactly_one_stock_target_required');
            }

            if ($product_id !== null) {
                $this->positiveId($product_id);
                $result = $this->stock->product($this->context->organization(), $product_id);
                $this->auditRead('mcp.product_stock.viewed', ['product_id' => $product_id]);

                return $result;
            }

            $this->positiveId((int) $variant_id);
            $result = $this->stock->variant($this->context->organization(), (int) $variant_id);
            $this->auditRead('mcp.product_stock.viewed', ['product_variant_id' => $variant_id]);

            return $result;
        }, 'MCP_STOCK_TARGET_NOT_FOUND');
    }

    /**
     * @param callable(): array<string, mixed> $callback
     */
    private function execute(string $tool, callable $callback, string $notFoundCode = 'MCP_ENTITY_NOT_FOUND'): CallToolResult
    {
        try {
            return $this->success($callback());
        } catch (AuthorizationException) {
            return $this->error('MCP_FORBIDDEN', 'The connection is not authorized to use this capability.');
        } catch (ModelNotFoundException) {
            return $this->error($notFoundCode, 'The requested resource was not found.');
        } catch (InvalidArgumentException) {
            return $this->error('MCP_INVALID_ARGUMENTS', 'The supplied tool arguments are invalid.');
        } catch (Throwable $exception) {
            Log::warning('MCP product read tool failed.', [
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
        $payload = [
            'ok' => false,
            'error' => ['code' => $code, 'message' => $message],
        ];

        return new CallToolResult([new TextContent($payload)], isError: true, structuredContent: $payload);
    }

    /** @param array<string, mixed> $metadata */
    private function auditRead(string $event, array $metadata): void
    {
        $this->audit->record(
            event: $event,
            actor: $this->context->user(),
            organization: $this->context->organization(),
            newValues: $metadata,
        );
    }

    private function positiveId(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('invalid_id');
        }
    }
}
