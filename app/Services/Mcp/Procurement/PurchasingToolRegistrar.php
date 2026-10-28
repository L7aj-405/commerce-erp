<?php

namespace App\Services\Mcp\Procurement;

use App\Enums\PurchaseOrderStatus;
use App\Services\Mcp\McpExecutionContext;
use Mcp\Schema\ToolAnnotations;

/**
 * MCP purchasing registration: Sales Order procurement, supplier purchase orders
 * and goods receipts. A tool is listed only when the connection holds its scope
 * AND the user currently holds the ERP permission; every call re-checks both.
 */
final class PurchasingToolRegistrar
{
    private const SCOPED = 'Only purchase orders of the stores the connection user belongs to.';

    private const VOCABULARY = 'A supplier availability request (quotation sourcing) is pre-sale evidence only: it is not a purchase order and reserves nothing. '
        .'A purchase order is the real supplier commitment; it may be created while the sales order is still a draft and creates no stock. '
        .'A receipt means the goods physically arrived: it adds them to stock and reserves them for the linked sales order, without confirming it. '
        .'Sales order confirmation is a separate step (preview_sales_order_confirmation / confirm_sales_order) once every line is physically covered.';

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly PurchasingTools $tools,
    ) {}

    /** @param \Mcp\Server\Builder $builder @param array<string, mixed> $outputSchema */
    public function register($builder, ToolAnnotations $readOnly, array $outputSchema): void
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $date = ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'];
        $fingerprint = ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'];
        $idempotency = ['type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'description' => 'Reuse the same key when retrying: the operation happens once.'];
        $quantity = ['type' => 'string', 'pattern' => '^\\d+(?:\\.\\d{1,4})?$', 'description' => 'Decimal string, e.g. "2" or "1.5".'];
        $schema = fn (array $properties, array $required = []) => ['type' => 'object', ...($required === [] ? [] : ['required' => $required]),
            'properties' => $properties, 'additionalProperties' => false];
        $write = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false);

        if ($this->context->allows('sales_orders.view')) {
            $builder->addTool([$this->tools, 'getSalesOrderProcurement'], name: 'get_sales_order_procurement', title: 'Get sales order procurement',
                description: 'Procurement state of one sales order, per line: stock-covered quantity, quantity to procure, ordered, received, remaining to receive, state (covered_by_stock, procurement_required, procurement_ordered, procurement_partially_received, procurement_received, unresolved), supplier, availability evidence, source quotation, linked purchase orders, and whether the order is confirmation-ready, with the confirmation blockers and the next_step (resolve_supplier, create_purchase_order, receive_procurement, confirm_sales_order). '.self::VOCABULARY,
                annotations: $readOnly, outputSchema: $outputSchema, inputSchema: $schema(['sales_order_id' => $id], ['sales_order_id']));
        }

        if ($this->context->allows('purchase_orders.view')) {
            $builder->addTool([$this->tools, 'searchPurchaseOrders'], name: 'search_purchase_orders', title: 'Search purchase orders',
                description: 'Search supplier purchase orders (BCF-…) by number or supplier name, with supplier/sales order/status/order date filters. Newest first; ordered, received and remaining quantities per order. '.self::SCOPED,
                annotations: $readOnly, outputSchema: $outputSchema,
                inputSchema: $schema([
                    'query' => ['type' => 'string', 'maxLength' => 255], 'supplier_id' => $id, 'sales_order_id' => $id,
                    'status' => ['type' => 'string', 'enum' => array_column(PurchaseOrderStatus::cases(), 'value')],
                    'date_from' => $date, 'date_to' => $date,
                    'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                ]));
            $builder->addTool([$this->tools, 'getPurchaseOrder'], name: 'get_purchase_order', title: 'Get purchase order',
                description: 'Return one supplier purchase order: header, supplier, status, linked sales orders, lines with ordered / received / remaining quantities and purchase_order_line_id, receipt history (warehouse, date, delivery reference), source quotation and supplier availability evidence, and a version token.',
                annotations: $readOnly, outputSchema: $outputSchema, inputSchema: $schema(['purchase_order_id' => $id], ['purchase_order_id']));
        }

        if ($this->context->allows('purchase_orders.create')) {
            $order = [
                'sales_order_id' => $id,
                'supplier_id' => [...$id, 'description' => 'The supplier the open requirements are confirmed with (see get_sales_order_procurement).'],
                'expected_delivery_date' => $date,
                'notes' => ['type' => ['string', 'null'], 'maxLength' => 2000],
            ];
            $builder->addTool([$this->tools, 'previewPurchaseOrderCreation'], name: 'preview_purchase_order_creation', title: 'Preview purchase order',
                description: 'Shows, without saving anything, the supplier purchase order that would be placed for a sales order: every open procurement requirement of that supplier (one still to be derived from the accepted quotation is included), with the exact lines and quantities, issues (no_open_requirement, already_ordered, wrong_supplier, supplier_inactive, supplier_not_found, forbidden) and a short-lived single-use preview_fingerprint. The sales order may be a draft. '.self::VOCABULARY,
                annotations: $readOnly, outputSchema: $outputSchema, inputSchema: $schema($order, ['sales_order_id', 'supplier_id']));
            $builder->addTool([$this->tools, 'createPurchaseOrder'], name: 'create_purchase_order', title: 'Create purchase order',
                description: 'Places the previewed supplier purchase order (same arguments + preview_fingerprint): a real financial commitment, linked to the sales order and its requirements, which become ordered. Creates no stock, no payment, no invoice, and does not change the sales order status. Requires an idempotency key: a retry never orders twice.',
                annotations: $write, outputSchema: $outputSchema,
                inputSchema: $schema([...$order, 'preview_fingerprint' => $fingerprint, 'idempotency_key' => $idempotency], ['sales_order_id', 'supplier_id', 'preview_fingerprint', 'idempotency_key']));
        }

        if ($this->context->allows('procurement_receipts.create')) {
            $receipt = [
                'purchase_order_id' => $id,
                'purchase_order_line_id' => [...$id, 'description' => 'Required when several lines still await goods (see get_purchase_order).'],
                'quantity_received' => $quantity,
                'warehouse_id' => [...$id, 'description' => 'Active warehouse where the goods physically arrived.'],
                'received_date' => [...$date, 'description' => 'Defaults to today; never in the future.'],
                'supplier_delivery_reference' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'note' => ['type' => ['string', 'null'], 'maxLength' => 2000],
            ];
            $builder->addTool([$this->tools, 'previewProcurementReceipt'], name: 'preview_procurement_receipt', title: 'Preview goods receipt',
                description: 'Validates a supplier goods receipt without saving it: ordered / received / remaining before and after, resulting line, purchase order and requirement status, the effect on the sales order (quantity reserved, confirmation_ready), issues (purchase_order_cancelled, receipt_quantity_exceeds_remaining, line_fully_received, warehouse_invalid, invalid_received_date, forbidden) and a short-lived single-use preview_fingerprint. Partial receipts are allowed. '.self::VOCABULARY,
                annotations: $readOnly, outputSchema: $outputSchema, inputSchema: $schema($receipt, ['purchase_order_id', 'quantity_received', 'warehouse_id']));
            $builder->addTool([$this->tools, 'receiveProcurement'], name: 'receive_procurement', title: 'Receive supplier goods',
                description: 'Records the previewed receipt (same arguments + preview_fingerprint): one inbound stock movement, the goods immediately reserved for the linked sales order line. Does NOT confirm the sales order. Requires an idempotency key: a retry never stocks twice; a receipt recorded after the preview makes it stale (MCP_PREVIEW_STALE).',
                annotations: $write, outputSchema: $outputSchema,
                inputSchema: $schema([...$receipt, 'preview_fingerprint' => $fingerprint, 'idempotency_key' => $idempotency], ['purchase_order_id', 'quantity_received', 'warehouse_id', 'preview_fingerprint', 'idempotency_key']));
        }
    }
}
