<?php

namespace App\Services\Mcp\Fulfillment;

use App\Actions\Fulfillment\MarkFulfillmentDeliveredAction;
use App\Actions\Fulfillment\UpdateFulfillmentShipmentDetailsAction;
use App\Models\Fulfillment;
use App\Models\SalesOrder;
use App\Services\Fulfillment\FulfillmentEngine;
use App\Services\Fulfillment\FulfillmentQuery;
use App\Services\Mcp\McpDryRun;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Parties\McpPartyPreviews;
use App\Services\Mcp\Sales\SalesOrderReadService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * F1.2 shipments over MCP: read, "Modifier le suivi", "Marquer comme livré".
 * Every mutation is the canonical web action (UpdateFulfillmentShipmentDetailsAction,
 * MarkFulfillmentDeliveredAction) — logistics only. Deliberately NOT exposed:
 * hand-over to the carrier / direct hand-over (the one stock OUT), preparation,
 * cancellation and closing. Previews run the action in a rolled-back
 * transaction, so their verdict is the ERP's and they never notify anyone.
 */
final class McpFulfillmentService
{
    public const TARGET_TYPE = 'fulfillment';

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly SalesOrderReadService $orders,
        private readonly FulfillmentEngine $engine,
        private readonly FulfillmentQuery $query,
        private readonly UpdateFulfillmentShipmentDetailsAction $updateTracking,
        private readonly MarkFulfillmentDeliveredAction $markDelivered,
        private readonly McpPartyPreviews $previews,
        private readonly McpDryRun $dryRun,
    ) {}

    /** @return array<string, mixed> */
    public function shipments(int $salesOrderId): array
    {
        $order = $this->orders->find($this->context->organization(), $salesOrderId);
        $this->engine->authorize($this->context->user(), $order, 'fulfillment.view');
        $state = $this->query->forOrder($order, $this->context->user());

        return [
            'sales_order' => $this->orderSummary($order),
            'fulfillment_status' => $state['state'],
            'delivery_state' => $state['delivery_state'],
            'workflow_mode' => $state['mode'],
            'lines' => $state['lines'],
            'shipments' => array_map(fn (array $shipment) => [
                ...array_diff_key($shipment, ['actions' => true, 'attachments' => true]),
                'attachments' => array_map(fn (array $attachment) => array_diff_key($attachment, ['url' => true]), $shipment['attachments']),
                'mcp_actions' => array_values(array_intersect($shipment['actions'], ['edit_details', 'mark_delivered'])),
            ], $state['fulfillments']),
            'note' => 'handed_to_carrier means the goods left stock with the carrier; it is NOT delivered. Stock leaves only at hand-over (a web action, never exposed over MCP). Tracking updates and delivery are logistics only and never move stock, reservations, invoices or payments.',
        ];
    }

    /**
     * @param array<string, mixed> $tracking
     * @return array<string, mixed>
     */
    public function normalizeTracking(array $tracking): array
    {
        if ($tracking === [] || array_diff(array_keys($tracking), UpdateFulfillmentShipmentDetailsAction::FIELDS) !== []) throw new InvalidArgumentException('forbidden_fields');
        $tracking = array_map(fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $tracking);
        Validator::make($tracking, UpdateFulfillmentShipmentDetailsAction::rules())->validate();
        if (array_key_exists('carrier_contact_id', $tracking)) $tracking['carrier_contact_id'] = (int) $tracking['carrier_contact_id'];
        if (array_key_exists('package_count', $tracking) && $tracking['package_count'] !== null) $tracking['package_count'] = (int) $tracking['package_count'];
        ksort($tracking);

        return $tracking;
    }

    /** @param array<string, mixed> $tracking @return array<string, mixed> */
    public function previewTracking(int $salesOrderId, string $shipmentId, array $tracking): array
    {
        return $this->preview('shipment_tracking', $salesOrderId, $shipmentId, $tracking, ['fulfillment.manage'],
            fn (SalesOrder $order, Fulfillment $shipment) => $this->updateTracking->execute($this->context->user(), $order, $shipment, $tracking, $shipment->status->value));
    }

    /** @param array<string, mixed> $tracking @return array<string, mixed> */
    public function updateTracking(int $salesOrderId, string $shipmentId, array $tracking, string $fingerprint): array
    {
        return $this->execute('shipment_tracking', $salesOrderId, $shipmentId, $tracking, $fingerprint,
            fn (SalesOrder $order, Fulfillment $shipment) => $this->updateTracking->execute($this->context->user(), $order, $shipment, $tracking, $shipment->status->value));
    }

    /** @return array<string, mixed> */
    public function previewDelivery(int $salesOrderId, string $shipmentId, ?string $deliveredAt): array
    {
        $payload = $this->deliveryPayload($deliveredAt);

        return $this->preview('shipment_delivery', $salesOrderId, $shipmentId, $payload, ['fulfillment.mark_delivered'],
            function (SalesOrder $order, Fulfillment $shipment) use ($payload) {
                if ($shipment->status->isDelivered()) {
                    throw ValidationException::withMessages(['already_delivered' => 'This shipment is already delivered; marking it again changes nothing.']);
                }

                return $this->markDelivered->execute($this->context->user(), $order, $shipment, $payload['delivered_at'], $shipment->status->value);
            });
    }

    /** @return array<string, mixed> */
    public function markDelivered(int $salesOrderId, string $shipmentId, ?string $deliveredAt, string $fingerprint): array
    {
        $payload = $this->deliveryPayload($deliveredAt);

        return $this->execute('shipment_delivery', $salesOrderId, $shipmentId, $payload, $fingerprint,
            fn (SalesOrder $order, Fulfillment $shipment) => $this->markDelivered->execute($this->context->user(), $order, $shipment, $payload['delivered_at'], $shipment->status->value));
    }

    /** @return array{delivered_at: ?string} */
    public function deliveryPayload(?string $deliveredAt): array
    {
        $deliveredAt = is_string($deliveredAt) && trim($deliveredAt) !== '' ? trim($deliveredAt) : null;
        Validator::make(['delivered_at' => $deliveredAt], ['delivered_at' => ['nullable', 'date']])->validate();

        return ['delivered_at' => $deliveredAt];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $scopes
     * @param callable(SalesOrder, Fulfillment): Fulfillment $action
     * @return array<string, mixed>
     */
    private function preview(string $entity, int $salesOrderId, string $shipmentId, array $payload, array $scopes, callable $action): array
    {
        $result = $this->dryRun->run(function () use ($salesOrderId, $shipmentId, $action) {
            [$order, $shipment] = $this->locate($salesOrderId, $shipmentId);
            $before = $this->shipmentState($shipment);
            $stateHash = $this->stateHash($shipment);
            $after = null;
            $issues = [];
            try {
                $after = $this->shipmentState($action($order, $shipment)->fresh());
            } catch (ValidationException $exception) {
                $issues = $this->issues($exception);
            } catch (HttpExceptionInterface) {
                $issues[] = ['path' => 'shipment', 'code' => 'not_permitted', 'message' => 'The ERP refused this action for the connection user (permission or store membership).'];
            }

            return ['order' => $order, 'shipment' => $shipment, 'before' => $before, 'after' => $after, 'issues' => $issues, 'state' => $stateHash];
        });

        [$fingerprint, $expiresAt] = $result['issues'] === []
            ? $this->previews->issue($entity, $this->binding($entity, $shipmentId, $payload, $result['state'], $scopes))
            : [null, null];

        return [
            'sales_order' => $this->orderSummary($result['order']),
            'shipment_id' => $shipmentId,
            'before' => $result['before'],
            'after' => $result['after'],
            'changes' => $result['after'] === null ? [] : array_keys(array_diff_assoc(array_map('json_encode', $result['after']), array_map('json_encode', $result['before']))),
            'valid' => $result['issues'] === [],
            'issues' => $result['issues'],
            'required_scopes' => $scopes,
            'preview_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'stock_effect' => 'none',
            'note' => $entity === 'shipment_delivery'
                ? 'Marks the shipment delivered (logistics only): no stock movement, reservation, invoice or payment change; the customer may be emailed once if the organization enabled it.'
                : 'Updates tracking information only: no status change and no stock movement.',
        ];
    }

    /**
     * Must run inside the caller's transaction (idempotency wrapper).
     *
     * @param array<string, mixed> $payload
     * @param callable(SalesOrder, Fulfillment): Fulfillment $action
     * @return array<string, mixed>
     */
    private function execute(string $entity, int $salesOrderId, string $shipmentId, array $payload, string $fingerprint, callable $action): array
    {
        [$order, $shipment] = $this->locate($salesOrderId, $shipmentId);
        $scopes = $entity === 'shipment_delivery' ? ['fulfillment.mark_delivered'] : ['fulfillment.manage'];
        $binding = $this->binding($entity, $shipmentId, $payload, $this->stateHash($shipment), $scopes);
        $this->previews->consume($entity, $fingerprint, $binding);
        try {
            $shipment = $action($order, $shipment);
        } catch (Throwable $exception) {
            $this->previews->release($entity, $fingerprint, $binding);
            throw $exception;
        }

        return [
            'target' => ['type' => self::TARGET_TYPE, 'id' => (string) $shipment->getKey()],
            'sales_order' => $this->orderSummary($order),
            'shipment' => $this->shipmentState($shipment->fresh()),
            'stock_effect' => 'none',
        ];
    }

    /** @return array{SalesOrder, Fulfillment} */
    private function locate(int $salesOrderId, string $shipmentId): array
    {
        if (! preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $shipmentId)) throw new InvalidArgumentException('invalid_id');
        $order = $this->orders->find($this->context->organization(), $salesOrderId, lock: true);
        $shipment = Fulfillment::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->getKey())
            ->whereKey(strtolower($shipmentId))->first() // HasUlids stores lowercase ids
            ?? throw (new ModelNotFoundException)->setModel(Fulfillment::class, [$shipmentId]);

        return [$order, $shipment];
    }

    /** @return array<string, mixed> */
    private function shipmentState(Fulfillment $shipment): array
    {
        $shipment->loadMissing('carrier:id,full_name,company_name');

        return [
            'id' => (string) $shipment->getKey(),
            'status' => $shipment->status->value,
            'channel' => $shipment->channel,
            'carrier' => $shipment->carrier ? ['id' => (int) $shipment->carrier->getKey(), 'name' => $shipment->carrier->company_name ?: $shipment->carrier->full_name] : null,
            'tracking_number' => $shipment->tracking_number,
            'tracking_url' => $shipment->tracking_url,
            'carrier_reference' => $shipment->carrier_reference,
            'package_count' => $shipment->package_count,
            'expected_delivery_at' => $shipment->expected_delivery_at?->toIso8601String(),
            'note' => $shipment->note,
            'handed_to_carrier_at' => $shipment->handed_to_carrier_at?->toIso8601String(),
            'in_transit_at' => $shipment->in_transit_at?->toIso8601String(),
            'delivered_at' => $shipment->delivered_at?->toIso8601String(),
            'stock_left_at' => $shipment->stock_consumed_at?->toIso8601String(),
        ];
    }

    /** What a recorded change depends on: the shipment row as previewed. */
    private function stateHash(Fulfillment $shipment): string
    {
        return hash('sha256', json_encode([$shipment->getKey(), $shipment->status->value, $shipment->updated_at?->format('Y-m-d H:i:s.u'), $this->shipmentState($shipment)]));
    }

    /** @param array<string, mixed> $payload @param list<string> $scopes @return array<string, mixed> */
    private function binding(string $entity, string $shipmentId, array $payload, string $stateHash, array $scopes): array
    {
        return [
            'entity' => $entity,
            'target_id' => strtolower($shipmentId),
            'payload_hash' => $this->previews->hash($payload),
            'state_hash' => $stateHash,
            'required_scopes' => $scopes,
        ];
    }

    /** @return array<string, mixed> */
    private function orderSummary(SalesOrder $order): array
    {
        return [
            'id' => (int) $order->getKey(),
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'fulfillment_status' => $order->fulfillment_status->value,
            'customer' => ['id' => $order->customer_id, 'name' => $order->customer_name],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function issues(ValidationException $exception): array
    {
        $issues = [];
        foreach ($exception->errors() as $field => $messages) {
            $message = mb_substr((string) ($messages[0] ?? 'Rejected.'), 0, 300);
            $issues[] = match ($field) {
                'already_delivered' => ['path' => 'shipment.status', 'code' => 'already_delivered', 'message' => $message],
                'status', 'mode' => ['path' => 'shipment.status', 'code' => 'invalid_shipment_state', 'message' => $message],
                'carrier_contact_id' => ['path' => 'tracking.carrier_contact_id', 'code' => 'invalid_carrier', 'message' => $message],
                'delivered_at' => ['path' => 'delivered_at', 'code' => 'invalid_delivered_at', 'message' => $message],
                default => ['path' => $field, 'code' => 'invalid', 'message' => $message],
            };
        }

        return $issues;
    }
}
