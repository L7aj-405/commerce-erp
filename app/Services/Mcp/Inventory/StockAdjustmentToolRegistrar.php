<?php

namespace App\Services\Mcp\Inventory;

use App\Services\Mcp\McpExecutionContext;
use Mcp\Schema\ToolAnnotations;

/**
 * Manual stock adjustment tools. Listed only when the connection holds the
 * `inventory.adjust` scope AND the user currently holds inventory.adjust and
 * inventory.view; every call re-checks both. Production inventory only.
 */
final class StockAdjustmentToolRegistrar
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly StockAdjustmentTools $tools,
    ) {}

    /** @param \Mcp\Server\Builder $builder @param array<string, mixed> $outputSchema */
    public function register($builder, ToolAnnotations $readOnly, array $outputSchema): void
    {
        if (! $this->context->allows('inventory.adjust') || ! $this->context->allows('inventory.view')) {
            return;
        }

        $id = ['type' => 'integer', 'minimum' => 1];
        $payload = [
            'variant_id' => [...$id, 'description' => 'Product variant id (see get_product_stock / search_products).'],
            'warehouse_id' => [...$id, 'description' => 'Warehouse id (see get_product_stock).'],
            'operation' => ['type' => 'string', 'enum' => ['set', 'increase', 'decrease'], 'description' => 'set = exact physical quantity that should exist (the ERP computes the difference); increase = add quantity; decrease = remove quantity.'],
            'quantity' => ['type' => ['string', 'number'], 'description' => 'Decimal, at most 4 fractional digits. For set: the new on-hand quantity (0 allowed). For increase/decrease: the amount, greater than zero.'],
            'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000, 'description' => 'Why the stock is corrected (e.g. physical inventory, breakage, correction).'],
            'reference' => ['type' => ['string', 'null'], 'maxLength' => 255],
        ];
        $schema = fn (array $properties, array $required) => ['type' => 'object', 'required' => $required, 'properties' => $properties, 'additionalProperties' => false];
        $write = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false);
        $effect = 'Production inventory only (MCP never touches Test Mode data). Reserved stock cannot be removed and stock cannot go negative.';

        $builder->addTool([$this->tools, 'previewStockAdjustment'], name: 'preview_stock_adjustment', title: 'Preview stock adjustment',
            description: 'Dry run of a manual stock correction for one variant in one warehouse: current on-hand / reserved / available, the signed delta, the resulting stock, validation issues, and a short-lived single-use preview_fingerprint when valid. Changes nothing. '.$effect,
            annotations: $readOnly, outputSchema: $outputSchema,
            inputSchema: $schema($payload, ['variant_id', 'warehouse_id', 'operation', 'quantity', 'reason']));
        $builder->addTool([$this->tools, 'applyStockAdjustment'], name: 'apply_stock_adjustment', title: 'Apply stock adjustment',
            description: 'Applies the adjustment previewed by preview_stock_adjustment (same payload and its fingerprint). Re-validates under a row lock, refuses a stale preview if stock changed, creates exactly one immutable adjustment movement through the ERP inventory action, audited as inventory.adjusted. Requires an idempotency key; a retry never applies twice. '.$effect,
            annotations: $write, outputSchema: $outputSchema,
            inputSchema: $schema($payload + [
                'preview_fingerprint' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
                'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'description' => 'Reuse the same key when retrying: the operation happens once.'],
            ], ['variant_id', 'warehouse_id', 'operation', 'quantity', 'reason', 'preview_fingerprint', 'idempotency_key']));
    }
}
