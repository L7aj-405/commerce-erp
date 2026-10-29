<?php

namespace App\Services\Mcp\Procurement;

use App\Actions\Procurement\SaveProductVariantSupplierAction;
use App\Services\Mcp\McpExecutionContext;
use Mcp\Schema\ToolAnnotations;

/**
 * B1 supplier catalog registration. Listed only with the scope AND the mapped
 * ERP permission; every call re-checks both (plus products.view).
 */
final class SupplierCatalogToolRegistrar
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly SupplierCatalogTools $tools,
    ) {}

    /** @param \Mcp\Server\Builder $builder @param array<string, mixed> $outputSchema */
    public function register($builder, ToolAnnotations $readOnly, array $outputSchema): void
    {
        if (! $this->context->allows('supplier_catalog.view')) return;

        $id = ['type' => 'integer', 'minimum' => 1];
        $schema = fn (array $properties, array $required = []) => ['type' => 'object', ...($required === [] ? [] : ['required' => $required]),
            'properties' => $properties, 'additionalProperties' => false];
        $nullableString = fn (int $max) => ['type' => ['string', 'null'], 'maxLength' => $max];
        $decimal = ['type' => ['string', 'null'], 'pattern' => '^\\d+(?:\\.\\d{1,4})?$', 'description' => 'Exact decimal string, max 4 decimals.'];
        $integer = fn (int $min, int $max) => ['type' => ['integer', 'null'], 'minimum' => $min, 'maximum' => $max];

        $builder->addTool([$this->tools, 'getVariantSuppliers'], name: 'get_variant_suppliers', title: 'Get suppliers of a product variant',
            description: 'Answer "who supplies this product?", "which supplier is preferred?" and "what price / lead time do we know per supplier?". '
                .'Give variant_id or sku. Returns each supplier relation (supplier SKU/reference, known supplier purchase price + currency, MOQ, order multiple, lead time, preferred, priority, status, ratings) '
                .'and last_purchase derived from purchase orders. The supplier price is NOT the variant cost. Inactive relations only with include_inactive. No ranking is computed.',
            annotations: $readOnly, outputSchema: $outputSchema,
            inputSchema: $schema(['variant_id' => $id, 'sku' => ['type' => 'string', 'maxLength' => 255], 'include_inactive' => ['type' => 'boolean', 'default' => false]]));
        $builder->addTool([$this->tools, 'getSupplierProducts'], name: 'get_supplier_products', title: 'Get products supplied by a supplier',
            description: 'Page through the product variants one supplier supplies, with its known terms and last purchase. Filter by status or a query on product name, SKU, reference or supplier SKU.',
            annotations: $readOnly, outputSchema: $outputSchema,
            inputSchema: $schema([
                'supplier_id' => $id, 'status' => ['type' => 'string', 'enum' => ['active', 'inactive']], 'query' => ['type' => 'string', 'maxLength' => 255],
                'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
            ], ['supplier_id']));

        if (! $this->context->allows('supplier_catalog.manage')) return;

        $relationFields = $schema([
            'supplier_sku' => $nullableString(255), 'supplier_reference' => $nullableString(255), 'supplier_product_name' => $nullableString(255),
            'purchase_price' => $decimal, 'currency_code' => ['type' => ['string', 'null'], 'pattern' => '^[A-Za-z]{3}$'],
            'minimum_order_quantity' => $decimal, 'order_multiple' => $decimal, 'lead_time_days' => $integer(0, 3650),
            'is_preferred' => ['type' => 'boolean'], 'priority' => $integer(1, 999), 'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
            'quality_rating' => $integer(1, 5), 'reliability_rating' => $integer(1, 5), 'notes' => $nullableString(2000),
        ]);
        $builder->addTool([$this->tools, 'previewSupplierProductRelation'], name: 'preview_supplier_product_relation', title: 'Preview a supplier ↔ product relation',
            description: 'Required before save_supplier_product_relation. Validates linking supplier_id to product_variant_id (or editing that existing link) with the ERP rules, without saving. '
                .'One relation per supplier + variant: an existing active link is updated, a deactivated one is reactivated — never duplicated. Omitted fields keep their value. '
                .'If is_preferred is true, preferred_change shows the current preferred supplier and which one will be unset (one preferred supplier per variant). '
                .'Show the user the changes (especially a preferred replacement) and get their confirmation. Returns a short-lived single-use preview_fingerprint. '
                .'Allowed fields: '.implode(', ', SaveProductVariantSupplierAction::FIELDS).'. Set status "inactive" to deactivate (relations are never deleted).',
            annotations: $readOnly, outputSchema: $outputSchema,
            inputSchema: $schema(['supplier_id' => $id, 'product_variant_id' => $id, 'relation' => $relationFields], ['supplier_id', 'product_variant_id']));
        $builder->addTool([$this->tools, 'saveSupplierProductRelation'], name: 'save_supplier_product_relation', title: 'Save a supplier ↔ product relation',
            description: 'Apply exactly what preview_supplier_product_relation showed (same supplier_id, product_variant_id and relation object + its preview_fingerprint), only after the user asked for it. '
                .'Requires an idempotency key: a retry with the same key returns the same result. A relation or preferred supplier that changed since the preview is rejected: preview again.',
            annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false), outputSchema: $outputSchema,
            inputSchema: $schema([
                'supplier_id' => $id, 'product_variant_id' => $id, 'relation' => $relationFields,
                'preview_fingerprint' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
                'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
            ], ['supplier_id', 'product_variant_id', 'relation', 'preview_fingerprint', 'idempotency_key']));
    }
}
