<?php

namespace App\Services\Mcp\Products;

use App\Actions\Catalog\ArchiveProductAction;
use App\Actions\Catalog\CreateProductAction;
use App\Actions\Catalog\CreateVariantAction;
use App\Actions\Catalog\UpdateProductAction;
use App\Actions\Catalog\UpdateProductPurchasePriceAction;
use App\Actions\Catalog\UpdateVariantAction;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AuditLogger;
use App\Services\Catalog\CatalogProductDataValidator;
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
use Throwable;

final class ProductWriteTools
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly McpProductChangeService $changes,
        private readonly CatalogProductDataValidator $validator,
        private readonly McpIdempotencyService $idempotency,
        private readonly CreateProductAction $createProductAction,
        private readonly CreateVariantAction $createVariantAction,
        private readonly UpdateProductAction $updateProductAction,
        private readonly UpdateVariantAction $updateVariantAction,
        private readonly UpdateProductPurchasePriceAction $updateCostAction,
        private readonly ArchiveProductAction $archiveProductAction,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function validateProductData(string $entity_type, string $mode, array $data, ?int $target_id = null): CallToolResult
    {
        return $this->execute('validate_product_data', function () use ($entity_type, $mode, $data, $target_id) {
            $this->context->authorize('products.view');

            return $this->changes->validate($entity_type, $mode, $target_id, $data);
        });
    }

    /** @param array<string, mixed> $changes */
    public function previewProductChanges(string $target_type, int $target_id, array $changes = []): CallToolResult
    {
        return $this->execute('preview_product_changes', fn () => $this->changes->preview($target_type, $target_id, $changes));
    }

    /** @param array<string, mixed> $data */
    public function createProduct(array $data, string $idempotency_key): CallToolResult
    {
        return $this->execute('create_product', function () use ($data, $idempotency_key) {
            $this->context->authorize('products.create');
            $this->assertFields($data, [...McpProductChangeService::PRODUCT_FIELDS, 'variant']);
            if (! is_array($data['variant'] ?? null)) throw new InvalidArgumentException('variant_required');
            $this->assertFields($data['variant'], McpProductChangeService::CREATE_VARIANT_FIELDS);
            $withCost = array_key_exists('purchase_price', $data['variant']);
            if ($withCost) $this->context->authorize('product_cost.manage');
            // Canonical request without the unique rules: a replay must not fail on the SKU its own first call created.
            $request = $this->validator->product($data, $this->organizationId(), true, $withCost, checkUniqueness: false);

            return $this->idempotency->execute('create_product', $idempotency_key, $request, function () use ($data, $withCost) {
                $normalized = $this->validator->product($data, $this->organizationId(), true, $withCost);
                $product = $this->createProductAction->execute($this->context->user(), $this->context->organization(), $normalized);
                $result = [
                    'target' => ['type' => 'product', 'id' => $product->getKey()],
                    'variant_ids' => $product->variants->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                ];
                $this->auditTool('create_product', $result['target'], array_keys($normalized));

                return $result;
            });
        });
    }

    /** @param array<string, mixed> $data */
    public function createVariant(int $product_id, array $data, string $idempotency_key): CallToolResult
    {
        return $this->execute('create_variant', function () use ($product_id, $data, $idempotency_key) {
            $this->context->authorize('products.create');
            $this->assertFields($data, McpProductChangeService::CREATE_VARIANT_FIELDS);
            $withCost = array_key_exists('purchase_price', $data);
            if ($withCost) $this->context->authorize('product_cost.manage');
            $product = $this->product($product_id);
            $request = $this->validator->variant($data, $this->organizationId(), null, $withCost, checkUniqueness: false);

            return $this->idempotency->execute('create_variant', $idempotency_key, ['product_id' => $product_id, 'data' => $request], function () use ($product, $data, $withCost) {
                $normalized = $this->validator->variant($data, $this->organizationId(), null, $withCost);
                $variant = $this->createVariantAction->execute($this->context->user(), $product, $normalized);
                $target = ['type' => 'variant', 'id' => $variant->getKey()];
                $this->auditTool('create_variant', $target, array_keys($normalized));

                return ['target' => $target, 'product_id' => $product->getKey()];
            });
        });
    }

    /** @param array<string, mixed> $changes */
    public function updateProduct(int $product_id, array $changes, string $preview_fingerprint): CallToolResult
    {
        return $this->execute('update_product', function () use ($product_id, $changes, $preview_fingerprint) {
            $this->context->authorize('products.update');

            return DB::transaction(function () use ($product_id, $changes, $preview_fingerprint) {
                $proposed = $this->changes->authorizeExecution('product', $product_id, $changes, $preview_fingerprint);
                $product = $this->product($product_id, true);
                $updated = $this->updateProductAction->execute($this->context->user(), $product, $proposed);
                $target = ['type' => 'product', 'id' => $updated->getKey()];
                $this->auditTool('update_product', $target, array_keys($changes));

                return ['target' => $target, 'updated_at' => $updated->updated_at?->toIso8601String()];
            });
        });
    }

    /** @param array<string, mixed> $changes */
    public function updateVariant(int $variant_id, array $changes, string $preview_fingerprint): CallToolResult
    {
        return $this->execute('update_variant', function () use ($variant_id, $changes, $preview_fingerprint) {
            $this->context->authorize('products.update');

            return DB::transaction(function () use ($variant_id, $changes, $preview_fingerprint) {
                $proposed = $this->changes->authorizeExecution('variant', $variant_id, $changes, $preview_fingerprint);
                $variant = $this->variant($variant_id, true);
                $proposed['purchase_price'] = $variant->purchase_price;
                $updated = $this->updateVariantAction->execute($this->context->user(), $variant, $proposed);
                $target = ['type' => 'variant', 'id' => $updated->getKey()];
                $this->auditTool('update_variant', $target, array_keys($changes));

                return ['target' => $target, 'updated_at' => $updated->updated_at?->toIso8601String()];
            });
        });
    }

    public function updateProductPurchasePrice(int $variant_id, string $purchase_price, string $preview_fingerprint): CallToolResult
    {
        return $this->execute('update_product_purchase_price', function () use ($variant_id, $purchase_price, $preview_fingerprint) {
            $this->context->authorize('product_cost.manage');

            return DB::transaction(function () use ($variant_id, $purchase_price, $preview_fingerprint) {
                $proposed = $this->changes->authorizeExecution('purchase_price', $variant_id, ['purchase_price' => $purchase_price], $preview_fingerprint);
                $variant = $this->variant($variant_id, true);
                $updated = $this->updateCostAction->execute($this->context->user(), $variant, $proposed['purchase_price']);
                $target = ['type' => 'variant', 'id' => $updated->getKey()];
                $this->auditTool('update_product_purchase_price', $target, ['purchase_price']);

                return ['target' => $target, 'updated_at' => $updated->updated_at?->toIso8601String()];
            });
        });
    }

    public function archiveProduct(int $product_id, string $preview_fingerprint): CallToolResult
    {
        return $this->execute('archive_product', function () use ($product_id, $preview_fingerprint) {
            $this->context->authorize('products.archive');

            return DB::transaction(function () use ($product_id, $preview_fingerprint) {
                $this->changes->authorizeExecution('archive_product', $product_id, [], $preview_fingerprint);
                $product = $this->product($product_id, true);
                $this->archiveProductAction->execute($this->context->user(), $product);
                $target = ['type' => 'product', 'id' => $product->getKey()];
                $this->auditTool('archive_product', $target, ['status']);

                return ['target' => $target, 'status' => 'inactive'];
            });
        });
    }

    /** @param callable(): array<string, mixed> $callback */
    private function execute(string $tool, callable $callback): CallToolResult
    {
        try {
            return $this->success($callback());
        } catch (AuthorizationException) {
            return $this->error('MCP_FORBIDDEN', 'The connection is not authorized to use this capability.');
        } catch (ModelNotFoundException) {
            return $this->error('MCP_ENTITY_NOT_FOUND', 'The requested resource was not found.');
        } catch (ValidationException $exception) {
            return $this->error('MCP_VALIDATION_FAILED', 'The candidate data is invalid.', ['fields' => $exception->errors()]);
        } catch (LockTimeoutException) {
            return $this->error('MCP_IDEMPOTENCY_BUSY', 'An identical operation is already being processed.');
        } catch (InvalidArgumentException $exception) {
            $code = match ($exception->getMessage()) {
                'stale_or_invalid_preview' => 'MCP_PREVIEW_STALE',
                'preview_conflict' => 'MCP_PREVIEW_CONFLICT',
                'idempotency_key_payload_mismatch' => 'MCP_IDEMPOTENCY_CONFLICT',
                default => 'MCP_INVALID_ARGUMENTS',
            };

            return $this->error($code, match ($code) {
                'MCP_PREVIEW_STALE' => 'The preview is missing, expired, consumed, or no longer matches the current target.',
                'MCP_PREVIEW_CONFLICT' => 'The requested change contains forbidden or externally managed fields.',
                'MCP_IDEMPOTENCY_CONFLICT' => 'The idempotency key was already used with a different request.',
                default => 'The supplied tool arguments are invalid.',
            });
        } catch (Throwable $exception) {
            Log::warning('MCP product mutation tool failed.', [
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

    /** @param array<string, mixed> $data @param list<string> $allowed */
    private function assertFields(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== []) throw new InvalidArgumentException('forbidden_fields');
    }

    private function product(int $id, bool $lock = false): Product
    {
        $query = Product::query()->where('organization_id', $this->organizationId())->whereKey($id);
        if ($lock) $query->lockForUpdate();
        $product = $query->first();
        if (! $product) throw (new ModelNotFoundException)->setModel(Product::class, [$id]);

        return $product;
    }

    private function variant(int $id, bool $lock = false): ProductVariant
    {
        $query = ProductVariant::query()->where('organization_id', $this->organizationId())->whereKey($id);
        if ($lock) $query->lockForUpdate();
        $variant = $query->first();
        if (! $variant) throw (new ModelNotFoundException)->setModel(ProductVariant::class, [$id]);

        return $variant;
    }

    /** @param array{type:string,id:mixed} $target @param list<string> $changedFields */
    private function auditTool(string $tool, array $target, array $changedFields): void
    {
        $this->audit->record(
            'mcp.product_tool_executed',
            $this->context->user(),
            $this->context->organization(),
            newValues: ['tool' => $tool, 'target' => $target, 'changed_fields' => $changedFields],
        );
    }

    private function organizationId(): int
    {
        return (int) $this->context->organization()->getKey();
    }
}
