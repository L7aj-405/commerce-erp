<?php

namespace App\Services\Mcp\Fulfillment;

use App\Services\Mcp\McpExecutionContext;
use Mcp\Schema\ToolAnnotations;

/**
 * F1.2 registration: shipment tracking and delivery. A tool is listed only
 * when the connection holds its scope AND the user currently holds the ERP
 * permission; every call re-checks both. No hand-over tool exists: the stock
 * OUT can never be triggered (or repeated) over MCP.
 */
final class FulfillmentToolRegistrar
{
    private const LOGISTICS_ONLY = 'Logistics only: never moves stock, never consumes a reservation, never changes the invoice, payments or the order\'s fulfillment_status.';

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly FulfillmentTools $tools,
    ) {}

    /** @param \Mcp\Server\Builder $builder @param array<string, mixed> $outputSchema */
    public function register($builder, ToolAnnotations $readOnly, array $outputSchema): void
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $shipmentId = ['type' => 'string', 'pattern' => '^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$', 'description' => 'Shipment id from get_sales_order_shipments.'];
        $dateTime = ['type' => 'string', 'maxLength' => 40, 'description' => 'ISO-8601 date-time; defaults to now.'];
        $fingerprint = ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'];
        $idempotency = ['type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'description' => 'Reuse the same key when retrying: the operation happens once.'];
        $nullable = fn (array $schema) => ['type' => [$schema['type'], 'null'], ...array_diff_key($schema, ['type' => true])];
        $tracking = ['type' => 'object', 'minProperties' => 1, 'additionalProperties' => false, 'properties' => [
            'carrier_contact_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'An active transport contact of the organization (carrier shipments only).'],
            'tracking_number' => $nullable(['type' => 'string', 'maxLength' => 128]),
            'tracking_url' => $nullable(['type' => 'string', 'maxLength' => 500, 'description' => 'http(s) link to the carrier tracking page.']),
            'carrier_reference' => $nullable(['type' => 'string', 'maxLength' => 128]),
            'package_count' => $nullable(['type' => 'integer', 'minimum' => 1, 'maximum' => 9999]),
            'expected_delivery_at' => $nullable(['type' => 'string', 'maxLength' => 40]),
            'note' => $nullable(['type' => 'string', 'maxLength' => 2000]),
        ]];
        $schema = fn (array $properties, array $required = []) => ['type' => 'object', ...($required === [] ? [] : ['required' => $required]),
            'properties' => $properties, 'additionalProperties' => false];
        $write = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false);

        if ($this->context->allows('fulfillment.view')) {
            $builder->addTool([$this->tools, 'getSalesOrderShipments'], name: 'get_sales_order_shipments', title: 'Get sales order shipments',
                description: 'Shipment / delivery state of one Sales Order: per line ordered / shipped / delivered / remaining, and every shipment with its status (preparing, ready, handed_to_carrier, in_transit, delivered, closed, cancelled), carrier, tracking number and link, packages, hand-over / in-transit / delivery times, stock-out time, proof metadata and customer notification outcome. handed_to_carrier is NOT delivered. Only orders of the stores the connection user belongs to.',
                annotations: $readOnly, outputSchema: $outputSchema, inputSchema: $schema(['sales_order_id' => $id], ['sales_order_id']));
        }

        if ($this->context->allows('fulfillment.manage')) {
            $builder->addTool([$this->tools, 'previewShipmentTrackingUpdate'], name: 'preview_shipment_tracking_update', title: 'Preview shipment tracking update',
                description: '"Modifier le suivi" dry run: validates a tracking change (carrier, tracking number / link, carrier reference, packages, expected delivery, note) of an open shipment with the ERP rules, without saving. Returns before / after / changed fields and a short-lived single-use preview_fingerprint when valid. '.self::LOGISTICS_ONLY,
                annotations: $readOnly, outputSchema: $outputSchema,
                inputSchema: $schema(['sales_order_id' => $id, 'shipment_id' => $shipmentId, 'tracking' => $tracking], ['sales_order_id', 'shipment_id', 'tracking']));
            $builder->addTool([$this->tools, 'updateShipmentTracking'], name: 'update_shipment_tracking', title: 'Update shipment tracking',
                description: 'Applies the tracking change previewed by preview_shipment_tracking_update (same tracking object and its fingerprint). Audited as fulfillment.tracking_updated. Requires an idempotency key. '.self::LOGISTICS_ONLY,
                annotations: $write, outputSchema: $outputSchema,
                inputSchema: $schema(['sales_order_id' => $id, 'shipment_id' => $shipmentId, 'tracking' => $tracking, 'preview_fingerprint' => $fingerprint, 'idempotency_key' => $idempotency],
                    ['sales_order_id', 'shipment_id', 'tracking', 'preview_fingerprint', 'idempotency_key']));
        }

        if ($this->context->allows('fulfillment.mark_delivered')) {
            $builder->addTool([$this->tools, 'previewShipmentDelivery'], name: 'preview_shipment_delivery', title: 'Preview shipment delivery',
                description: '"Marquer comme livré" dry run: checks that a shipment handed to the carrier (or in transit) can be marked delivered at delivered_at (between hand-over and now), without saving. Issues: already_delivered, invalid_shipment_state, invalid_delivered_at. Returns a short-lived single-use preview_fingerprint when valid. '.self::LOGISTICS_ONLY,
                annotations: $readOnly, outputSchema: $outputSchema,
                inputSchema: $schema(['sales_order_id' => $id, 'shipment_id' => $shipmentId, 'delivered_at' => $dateTime], ['sales_order_id', 'shipment_id']));
            $builder->addTool([$this->tools, 'markShipmentDelivered'], name: 'mark_shipment_delivered', title: 'Mark shipment delivered',
                description: 'Marks the shipment previewed by preview_shipment_delivery as delivered (same delivered_at and its fingerprint); keeps the hand-over time; the customer is emailed once if the organization enabled shipment notifications. Requires an idempotency key; a retry never repeats anything. '.self::LOGISTICS_ONLY,
                annotations: $write, outputSchema: $outputSchema,
                inputSchema: $schema(['sales_order_id' => $id, 'shipment_id' => $shipmentId, 'delivered_at' => $dateTime, 'preview_fingerprint' => $fingerprint, 'idempotency_key' => $idempotency],
                    ['sales_order_id', 'shipment_id', 'preview_fingerprint', 'idempotency_key']));
        }
    }
}
