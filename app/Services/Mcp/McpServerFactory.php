<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Products\McpProductChangeService;
use App\Services\Mcp\Products\ProductOperationTools;
use App\Services\Mcp\Products\ProductReadTools;
use App\Services\Mcp\Products\ProductWriteTools;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\FileSessionLock;
use Mcp\Server\Session\FileSessionStore;

final class McpServerFactory
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly ProductReadTools $productTools,
        private readonly ProductWriteTools $productWriteTools,
        private readonly ProductOperationTools $operationTools,
    ) {}

    public function make(): Server
    {
        $directory = storage_path('framework/mcp-sessions/'.$this->context->connectionOrFail()->getKey());

        $builder = Server::builder()
            ->setServerInfo('10xScale ERP', '1.0.0', 'Tenant-scoped 10xScale ERP capabilities')
            ->setSession(new FileSessionStore($directory, (int) config('mcp.session_ttl_seconds', 3600)))
            ->setSessionLock(new FileSessionLock($directory));

        $readOnly = new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );
        $outputSchema = [
            'type' => 'object',
            'required' => ['ok'],
            'properties' => [
                'ok' => ['type' => 'boolean'],
                'data' => ['type' => 'object'],
                'error' => [
                    'type' => 'object',
                    'required' => ['code', 'message'],
                    'properties' => [
                        'code' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                        'details' => ['type' => 'object'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            'additionalProperties' => false,
        ];

        if ($this->context->allows('products.view')) {
            $builder->addTool(
                [$this->productTools, 'searchProducts'],
                name: 'search_products',
                title: 'Search products',
                description: 'Search this connection organization\'s products by name, SKU, reference, or barcode using bounded deterministic pagination.',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'maxLength' => 255],
                        'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
                        'brand_id' => ['type' => 'integer', 'minimum' => 1],
                        'category_id' => ['type' => 'integer', 'minimum' => 1],
                        'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1],
                        'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                    ],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema,
            );
            $builder->addTool(
                [$this->productTools, 'getProduct'],
                name: 'get_product',
                title: 'Get product',
                description: 'Return an organization-scoped product with variants, identifiers, status, channel metadata, and resolved HT/TTC tax pricing.',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object',
                    'required' => ['product_id'],
                    'properties' => ['product_id' => ['type' => 'integer', 'minimum' => 1]],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema,
            );
            $builder->addTool(
                [$this->productTools, 'getProductVariant'],
                name: 'get_product_variant',
                title: 'Get product variant',
                description: 'Return an organization-scoped variant with identifiers, status, channel metadata, and resolved HT/TTC tax pricing.',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object',
                    'required' => ['variant_id'],
                    'properties' => ['variant_id' => ['type' => 'integer', 'minimum' => 1]],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema,
            );

            if ($this->context->allows('inventory.view')) {
                $builder->addTool(
                    [$this->productTools, 'getProductStock'],
                    name: 'get_product_stock',
                    title: 'Get product stock',
                    description: 'Return exact organization-wide on-hand, reserved, and available stock totals and warehouse breakdowns for one product or variant.',
                    annotations: $readOnly,
                    inputSchema: [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => ['type' => 'integer', 'minimum' => 1],
                            'variant_id' => ['type' => 'integer', 'minimum' => 1],
                        ],
                        'oneOf' => [
                            ['required' => ['product_id']],
                            ['required' => ['variant_id']],
                        ],
                        'additionalProperties' => false,
                    ],
                    outputSchema: $outputSchema,
                );
            }
        }

        $this->registerProductWriteTools($builder, $readOnly, $outputSchema);
        $this->registerProductOperationTools($builder, $readOnly, $outputSchema);

        return $builder->build();
    }

    /** @param array<string, mixed> $outputSchema */
    private function registerProductWriteTools($builder, ToolAnnotations $readOnly, array $outputSchema): void
    {
        $mutation = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false);
        $archive = new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false);
        $productFields = $this->productFieldSchema();
        $variantFields = $this->variantFieldSchema();
        $candidateFields = array_replace($productFields, $variantFields, [
            'variant' => ['type' => 'object', 'properties' => $variantFields, 'additionalProperties' => false],
        ]);

        if ($this->context->allows('products.view')) {
            $builder->addTool(
                [$this->productWriteTools, 'validateProductData'],
                name: 'validate_product_data',
                title: 'Validate product data',
                description: 'Validate and normalize candidate product or variant data without mutation.',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object',
                    'required' => ['entity_type', 'mode', 'data'],
                    'properties' => [
                        'entity_type' => ['type' => 'string', 'enum' => ['product', 'variant']],
                        'mode' => ['type' => 'string', 'enum' => ['create', 'update']],
                        'target_id' => ['type' => 'integer', 'minimum' => 1],
                        'data' => ['type' => 'object', 'properties' => $candidateFields, 'additionalProperties' => false],
                    ],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema,
            );
        }

        if ($this->context->allows('products.update') || $this->context->allows('products.archive') || $this->context->allows('product_cost.manage')) {
            $builder->addTool(
                [$this->productWriteTools, 'previewProductChanges'],
                name: 'preview_product_changes',
                title: 'Preview product changes',
                description: 'Preview one product, variant, purchase-cost, or archive mutation and issue a short-lived fingerprint when safe.',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object',
                    'required' => ['target_type', 'target_id'],
                    'properties' => [
                        'target_type' => ['type' => 'string', 'enum' => ['product', 'variant', 'purchase_price', 'archive_product']],
                        'target_id' => ['type' => 'integer', 'minimum' => 1],
                        'changes' => ['type' => 'object', 'properties' => array_replace($productFields, $variantFields), 'additionalProperties' => false, 'default' => []],
                    ],
                    'additionalProperties' => false,
                ],
                outputSchema: $outputSchema,
            );
        }

        if ($this->context->allows('products.create')) {
            $builder->addTool(
                [$this->productWriteTools, 'createProduct'],
                name: 'create_product', title: 'Create product',
                description: 'Create one product and its initial variant without creating stock. Requires an idempotency key.',
                annotations: $mutation,
                inputSchema: [
                    'type' => 'object', 'required' => ['data', 'idempotency_key'],
                    'properties' => [
                        'data' => ['type' => 'object', 'properties' => [...$productFields, 'variant' => ['type' => 'object', 'properties' => $variantFields, 'additionalProperties' => false]], 'additionalProperties' => false],
                        'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
                    ], 'additionalProperties' => false,
                ], outputSchema: $outputSchema,
            );
            $builder->addTool(
                [$this->productWriteTools, 'createVariant'],
                name: 'create_variant', title: 'Create product variant',
                description: 'Create one variant under an organization-scoped product without creating stock. Requires an idempotency key.',
                annotations: $mutation,
                inputSchema: [
                    'type' => 'object', 'required' => ['product_id', 'data', 'idempotency_key'],
                    'properties' => [
                        'product_id' => ['type' => 'integer', 'minimum' => 1],
                        'data' => ['type' => 'object', 'properties' => $variantFields, 'additionalProperties' => false],
                        'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
                    ], 'additionalProperties' => false,
                ], outputSchema: $outputSchema,
            );
        }

        if ($this->context->allows('products.update')) {
            $builder->addTool(
                [$this->productWriteTools, 'updateProduct'],
                name: 'update_product', title: 'Update product',
                description: 'Apply explicit product fields after a matching short-lived preview.',
                annotations: $mutation,
                inputSchema: $this->updateSchema('product_id', $productFields), outputSchema: $outputSchema,
            );
            $builder->addTool(
                [$this->productWriteTools, 'updateVariant'],
                name: 'update_variant', title: 'Update product variant',
                description: 'Apply explicit non-cost variant fields after a matching short-lived preview.',
                annotations: $mutation,
                inputSchema: $this->updateSchema('variant_id', array_diff_key($variantFields, ['purchase_price' => true])), outputSchema: $outputSchema,
            );
        }

        if ($this->context->allows('product_cost.manage')) {
            $builder->addTool(
                [$this->productWriteTools, 'updateProductPurchasePrice'],
                name: 'update_product_purchase_price', title: 'Update product purchase price',
                description: 'Update one variant purchase cost using an exact decimal and a matching preview.',
                annotations: $mutation,
                inputSchema: [
                    'type' => 'object', 'required' => ['variant_id', 'purchase_price', 'preview_fingerprint'],
                    'properties' => [
                        'variant_id' => ['type' => 'integer', 'minimum' => 1],
                        'purchase_price' => ['type' => 'string', 'pattern' => '^\\d+(?:\\.\\d{1,4})?$'],
                        'preview_fingerprint' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
                    ], 'additionalProperties' => false,
                ], outputSchema: $outputSchema,
            );
        }

        if ($this->context->allows('products.archive')) {
            $builder->addTool(
                [$this->productWriteTools, 'archiveProduct'],
                name: 'archive_product', title: 'Archive product',
                description: 'Deactivate a product and its variants after an explicit archive preview. No rows are deleted.',
                annotations: $archive,
                inputSchema: [
                    'type' => 'object', 'required' => ['product_id', 'preview_fingerprint'],
                    'properties' => [
                        'product_id' => ['type' => 'integer', 'minimum' => 1],
                        'preview_fingerprint' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
                    ], 'additionalProperties' => false,
                ], outputSchema: $outputSchema,
            );
        }
    }

    /** @param array<string, mixed> $outputSchema */
    private function registerProductOperationTools($builder, ToolAnnotations $readOnly, array $outputSchema): void
    {
        if (! $this->context->allows('products.view')) return;

        $prepare = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false);
        $execute = new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false);
        $max = (int) config('mcp.operations.max_items', 5000);
        $key = ['type' => 'string', 'minLength' => 1, 'maxLength' => 128];
        // Row schemas describe structure only; row-level problems (bad decimals,
        // unknown fields, missing targets) are reported per row in the preview
        // instead of rejecting the whole request.
        $rows = fn (array $properties) => ['type' => 'array', 'minItems' => 1, 'maxItems' => $max, 'items' => ['type' => 'object', 'properties' => $properties]];
        $locator = fn (string $idField) => [$idField => ['type' => 'integer'], 'sku' => ['type' => 'string']];
        $prepareDescription = ' Validates every row and returns preview counts without mutating anything. Up to '.$max.' rows. '
            .'Small requests return the preview immediately; large ones return status "validating" (poll get_product_operation until "previewed"). '
            .'The same idempotency_key with the same rows returns the existing operation.';
        $operationId = ['type' => 'string', 'pattern' => '^[0-9A-Za-z]{26}$'];

        if ($this->context->allows('products.update')) {
            $builder->addTool(
                [$this->operationTools, 'prepareProductBulkUpdate'],
                name: 'prepare_product_bulk_update', title: 'Prepare product bulk update',
                description: 'Prepare explicit product or variant field changes (no purchase cost, no stock). Each row targets a product or variant by target_id or sku.'.$prepareDescription,
                annotations: $prepare,
                inputSchema: [
                    'type' => 'object', 'required' => ['rows', 'idempotency_key'],
                    'properties' => [
                        'rows' => $rows([
                            'target_type' => ['type' => 'string', 'enum' => ['product', 'variant']],
                            ...$locator('target_id'),
                            'changes' => [
                                'type' => 'object',
                                'description' => 'Product fields: '.implode(', ', McpProductChangeService::PRODUCT_FIELDS)
                                    .'. Variant fields: '.implode(', ', McpProductChangeService::VARIANT_FIELDS)
                                    .'. Prices are exact decimal strings with at most 4 decimals.',
                            ],
                        ]),
                        'idempotency_key' => $key,
                    ], 'additionalProperties' => false,
                ], outputSchema: $outputSchema,
            );
        }

        if ($this->context->allows('product_cost.manage')) {
            $builder->addTool(
                [$this->operationTools, 'preparePurchasePriceBulkUpdate'],
                name: 'prepare_purchase_price_bulk_update', title: 'Prepare purchase price bulk update',
                description: 'Prepare variant purchase-cost updates. Each row has variant_id or sku and purchase_price as an exact decimal string (max 4 decimals).'.$prepareDescription,
                annotations: $prepare,
                inputSchema: [
                    'type' => 'object', 'required' => ['rows', 'idempotency_key'],
                    'properties' => [
                        'rows' => $rows([...$locator('variant_id'), 'purchase_price' => ['type' => 'string']]),
                        'idempotency_key' => $key,
                    ], 'additionalProperties' => false,
                ], outputSchema: $outputSchema,
            );
        }

        if ($this->context->allows('products.archive')) {
            $builder->addTool(
                [$this->operationTools, 'prepareProductBulkArchive'],
                name: 'prepare_product_bulk_archive', title: 'Prepare product bulk archive',
                description: 'Prepare deactivation of products and their variants. Each row has product_id or the sku of one of its variants. Nothing is deleted.'.$prepareDescription,
                annotations: $prepare,
                inputSchema: [
                    'type' => 'object', 'required' => ['rows', 'idempotency_key'],
                    'properties' => [
                        'rows' => $rows($locator('product_id')),
                        'idempotency_key' => $key,
                    ], 'additionalProperties' => false,
                ], outputSchema: $outputSchema,
            );
        }

        if ($this->context->allows('products.update') || $this->context->allows('product_cost.manage') || $this->context->allows('products.archive')) {
            $builder->addTool(
                [$this->operationTools, 'executeProductOperation'],
                name: 'execute_product_operation', title: 'Execute product operation',
                description: 'Queue a previewed operation using its exact preview_fingerprint. Only "changed" rows are applied, each independently; the final status is completed, partial, or failed. A changed target or expired preview is rejected: prepare again. Repeating the call with the same fingerprint returns the current status.',
                annotations: $execute,
                inputSchema: [
                    'type' => 'object', 'required' => ['operation_id', 'preview_fingerprint'],
                    'properties' => [
                        'operation_id' => $operationId,
                        'preview_fingerprint' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
                    ], 'additionalProperties' => false,
                ], outputSchema: $outputSchema,
            );
        }

        $builder->addTool(
            [$this->operationTools, 'getProductOperation'],
            name: 'get_product_operation', title: 'Get product operation',
            description: 'Return status, preview counts, progress, timestamps, and (while previewed) the preview fingerprint of an operation created by this connection.',
            annotations: $readOnly,
            inputSchema: [
                'type' => 'object', 'required' => ['operation_id'],
                'properties' => ['operation_id' => $operationId], 'additionalProperties' => false,
            ], outputSchema: $outputSchema,
        );
        $builder->addTool(
            [$this->operationTools, 'getProductOperationItems'],
            name: 'get_product_operation_items', title: 'Get product operation items',
            description: 'Page through per-row preview and execution results of an operation created by this connection.',
            annotations: $readOnly,
            inputSchema: [
                'type' => 'object', 'required' => ['operation_id'],
                'properties' => [
                    'operation_id' => $operationId,
                    'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => (int) config('mcp.operations.items_max_per_page', 200), 'default' => 50],
                    'status' => ['type' => 'string', 'enum' => ['ready', 'excluded', 'pending', 'succeeded', 'failed']],
                    'preview_status' => ['type' => 'string', 'enum' => ['changed', 'unchanged', 'skipped', 'invalid', 'conflict']],
                ], 'additionalProperties' => false,
            ], outputSchema: $outputSchema,
        );
    }

    /** @return array<string, mixed> */
    private function productFieldSchema(): array
    {
        return [
            'name' => ['type' => 'string', 'maxLength' => 255],
            'description' => ['type' => ['string', 'null']],
            'image_url' => ['type' => ['string', 'null'], 'maxLength' => 2048],
            'brand_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'category_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'unit_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
        ];
    }

    /** @return array<string, mixed> */
    private function variantFieldSchema(): array
    {
        $decimal = ['type' => ['string', 'null'], 'pattern' => '^\\d+(?:\\.\\d{1,4})?$'];

        return [
            'label' => ['type' => ['string', 'null'], 'maxLength' => 255],
            'sku' => ['type' => 'string', 'maxLength' => 255],
            'reference' => ['type' => ['string', 'null'], 'maxLength' => 255],
            'barcode' => ['type' => ['string', 'null'], 'maxLength' => 255],
            'image_url' => ['type' => ['string', 'null'], 'maxLength' => 2048],
            'purchase_price' => $decimal,
            'public_price_ttc' => $decimal,
            'unit_price_ht' => $decimal,
            'regular_sale_price' => $decimal,
            'promotional_sale_price' => $decimal,
            'default_sale_price' => $decimal,
            'tax_rate_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
        ];
    }

    /** @param array<string, mixed> $fields @return array<string, mixed> */
    private function updateSchema(string $idField, array $fields): array
    {
        return [
            'type' => 'object', 'required' => [$idField, 'changes', 'preview_fingerprint'],
            'properties' => [
                $idField => ['type' => 'integer', 'minimum' => 1],
                'changes' => ['type' => 'object', 'minProperties' => 1, 'properties' => $fields, 'additionalProperties' => false],
                'preview_fingerprint' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
            ],
            'additionalProperties' => false,
        ];
    }
}
