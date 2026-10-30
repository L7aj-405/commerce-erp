<?php

namespace App\Services\Fulfillment;

use App\Actions\Fulfillment\ShareFulfillmentWithCustomerAction;
use App\Enums\FulfillmentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Fulfillment;
use App\Models\FulfillmentAttachment;
use App\Models\FulfillmentCustomerNotification;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\FollowUps\FollowUpAccess;
use App\Services\FollowUps\FollowUpQuery;
use App\Support\InventoryQuantity;

/**
 * "Expédition & Livraison" read model of one Sales Order: mode, derived
 * operational state, per-line planned / shipped / delivered / shippable
 * quantities and every shipment with the actions its status, mode and the
 * viewer's permissions allow. Read-only; the actions re-check everything.
 */
final class FulfillmentQuery
{
    public function __construct(
        private readonly FulfillmentEngine $engine,
        private readonly FollowUpAccess $followUpAccess,
        private readonly FollowUpQuery $followUpQuery,
    ) {}

    /** @return array<string, mixed> */
    public function forOrder(SalesOrder $order, User $viewer): array
    {
        $orgId = (int) $order->organization_id;
        $can = [
            'manage' => $viewer->hasPermission($orgId, 'fulfillment.manage'),
            'dispatch' => $viewer->hasPermission($orgId, 'fulfillment.dispatch'),
            'deliver' => $viewer->hasPermission($orgId, 'fulfillment.mark_delivered'),
        ];
        $mode = FulfillmentEngine::modeFor($order->organization);
        $state = $this->engine->lineState($order);

        $lines = [];
        foreach ($state as $lineId => $line) {
            if (InventoryQuantity::compare($line['quantity'], InventoryQuantity::ZERO) <= 0) continue;
            $remaining = InventoryQuantity::subtract($line['quantity'], $line['planned']);
            $shippable = $remaining;
            if ($line['catalog']) {
                $free = InventoryQuantity::subtract($line['reserved'], $line['claimed_unshipped']);
                if (InventoryQuantity::compare($free, $shippable) < 0) $shippable = InventoryQuantity::compare($free, InventoryQuantity::ZERO) > 0 ? $free : InventoryQuantity::ZERO;
            }
            $lines[] = [
                'line_id' => $lineId,
                'article' => trim($line['line']->product_name.($line['line']->variant_name ? ' — '.$line['line']->variant_name : '')),
                'sku' => $line['line']->sku,
                'catalog' => $line['catalog'],
                'quantity' => $line['quantity'],
                'planned' => $line['planned'],
                'shipped' => $line['shipped'],
                'delivered' => $line['delivered'],
                'remaining' => $remaining,
                'shippable' => $shippable,
            ];
        }
        $allDelivered = $lines !== [] && collect($lines)->every(fn ($line) => InventoryQuantity::compare($line['delivered'], $line['quantity']) >= 0);
        $anyDelivered = collect($lines)->contains(fn ($line) => InventoryQuantity::compare($line['delivered'], InventoryQuantity::ZERO) > 0);
        $confirmed = $order->status === SalesOrderStatus::Confirmed;
        $canShipMore = $confirmed && ! $order->awaitingReplenishment() && collect($lines)->contains(fn ($line) => InventoryQuantity::compare($line['shippable'], InventoryQuantity::ZERO) > 0);
        $articles = collect($lines)->keyBy('line_id');

        $fulfillments = $order->fulfillments()->with(['items', 'carrier:id,full_name,company_name,phone', 'attachments', 'customerNotifications.sentBy:id,name', 'createdBy:id,name', 'handedBy:id,name',
            'followUps.assignee:id,name', 'followUps.customer:id,organization_id,display_name', 'followUps.salesOrder:id,organization_id,store_id,order_number,status', 'followUps.fulfillment:id,organization_id,sales_order_id,status'])
            ->orderBy('created_at')->orderBy('id')->get()
            ->values()
            ->map(fn (Fulfillment $fulfillment, int $index) => [
                'id' => (string) $fulfillment->getKey(),
                'sequence' => $index + 1,
                'status' => $fulfillment->status->value,
                'channel' => $fulfillment->channel,
                'workflow_mode' => $fulfillment->workflow_mode,
                'items' => $fulfillment->items->map(fn ($item) => [
                    'line_id' => (int) $item->sales_order_line_id,
                    'article' => $articles[$item->sales_order_line_id]['article'] ?? '—',
                    'quantity' => InventoryQuantity::normalize((string) $item->quantity),
                ])->values()->all(),
                'carrier' => $fulfillment->carrier ? [
                    'id' => (int) $fulfillment->carrier->getKey(),
                    'name' => $fulfillment->carrier->company_name ?: $fulfillment->carrier->full_name,
                    'contact' => $fulfillment->carrier->company_name ? $fulfillment->carrier->full_name : null,
                    'phone' => $fulfillment->carrier->phone,
                ] : null,
                'tracking_number' => $fulfillment->tracking_number,
                'tracking_url' => $fulfillment->tracking_url,
                'carrier_reference' => $fulfillment->carrier_reference,
                'package_count' => $fulfillment->package_count,
                'expected_delivery_at' => $fulfillment->expected_delivery_at?->toIso8601String(),
                'note' => $fulfillment->note,
                'preparation_started_at' => $fulfillment->preparation_started_at?->toIso8601String(),
                'ready_at' => $fulfillment->ready_at?->toIso8601String(),
                'handed_to_carrier_at' => $fulfillment->handed_to_carrier_at?->toIso8601String(),
                'handed_by' => $fulfillment->handedBy?->name,
                'in_transit_at' => $fulfillment->in_transit_at?->toIso8601String(),
                'delivered_at' => $fulfillment->delivered_at?->toIso8601String(),
                'closed_at' => $fulfillment->closed_at?->toIso8601String(),
                'cancelled_at' => $fulfillment->cancelled_at?->toIso8601String(),
                // Stock left exactly here (hand-over); everything after is logistics only.
                'stock_left_at' => $fulfillment->stock_consumed_at?->toIso8601String(),
                'customer_notifications' => $fulfillment->customerNotifications->sortBy('id')->values()->map(fn (FulfillmentCustomerNotification $notification) => [
                    'id' => (int) $notification->getKey(),
                    'event' => $notification->event,
                    'origin' => $notification->origin,
                    'channel' => $notification->channel,
                    'status' => $notification->status,
                    'recipient' => $notification->recipient,
                    'skip_reason' => $notification->skip_reason,
                    'sent_by' => $notification->sentBy?->name,
                    'sent_at' => ($notification->sent_at ?? ($notification->origin === FulfillmentCustomerNotification::ORIGIN_MANUAL ? $notification->created_at : null))?->toIso8601String(),
                ])->all(),
                'follow_up' => ($followUp = $fulfillment->followUps
                    ->filter(fn ($item) => $this->followUpAccess->canView($viewer, $item))
                    ->sortBy(fn ($item) => ($item->status->value === 'open' ? '0' : '1').str_pad((string) (999999999 - (int) $item->getKey()), 12, '0', STR_PAD_LEFT))
                    ->first()) ? $this->followUpQuery->present($viewer, $order->organization, $followUp) : null,
                'created_by' => $fulfillment->createdBy?->name,
                'attachments' => $fulfillment->attachments->sortBy('created_at')->values()->map(fn (FulfillmentAttachment $attachment) => [
                    'id' => (string) $attachment->getKey(),
                    'name' => $attachment->original_name,
                    'category' => $attachment->category,
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => $attachment->size_bytes,
                    'url' => route('sales.orders.fulfillments.attachments.show', [$order->getKey(), $fulfillment->getKey(), $attachment->getKey()]),
                ])->all(),
                'actions' => $this->actions($fulfillment, $can),
            ])->all();

        return [
            'mode' => $mode,
            'state' => $order->fulfillment_status->value,
            'delivery_state' => $allDelivered ? 'delivered' : ($anyDelivered ? 'partially_delivered' : 'not_delivered'),
            'logistics_state' => $allDelivered ? 'delivered' : $this->logisticsState($fulfillments),
            'awaiting_replenishment' => $confirmed && $order->awaitingReplenishment(),
            'lines' => $lines,
            'fulfillments' => $fulfillments,
            'actions' => array_values(array_filter([
                $mode === Fulfillment::MODE_SIMPLE && $can['dispatch'] && $canShipMore ? 'hand_to_carrier' : null,
                $mode === Fulfillment::MODE_ADVANCED && $can['manage'] && $canShipMore ? 'start_preparation' : null,
            ])),
            'can' => [...$can, 'attach' => $can['manage'] || $can['dispatch']],
        ];
    }

    /** @param list<array<string, mixed>> $fulfillments */
    private function logisticsState(array $fulfillments): string
    {
        $statuses = collect($fulfillments)->pluck('status');
        if ($statuses->contains(fn (string $status) => in_array($status, ['handed_to_carrier', 'in_transit'], true))) return 'shipped';
        if ($statuses->contains('ready')) return 'ready';
        if ($statuses->contains('preparing')) return 'preparing';

        return 'unprepared';
    }

    /** @param array<string, bool> $can @return list<string> */
    private function actions(Fulfillment $fulfillment, array $can): array
    {
        $advanced = $fulfillment->workflow_mode === Fulfillment::MODE_ADVANCED;
        $actions = match ($fulfillment->status) {
            FulfillmentStatus::Preparing => [$can['manage'] ? 'mark_ready' : null, $can['manage'] ? 'cancel' : null],
            FulfillmentStatus::Ready => [$can['dispatch'] ? 'hand_to_carrier' : null, $can['manage'] ? 'cancel' : null],
            FulfillmentStatus::HandedToCarrier => [$advanced && $can['dispatch'] ? 'mark_in_transit' : null, $can['deliver'] ? 'mark_delivered' : null],
            FulfillmentStatus::InTransit => [$can['deliver'] ? 'mark_delivered' : null],
            FulfillmentStatus::Delivered => [$can['manage'] ? 'close' : null],
            default => [],
        };
        if (! in_array($fulfillment->status, [FulfillmentStatus::Closed, FulfillmentStatus::Cancelled], true) && $fulfillment->channel === Fulfillment::CHANNEL_CARRIER && $can['manage']) {
            $actions[] = 'edit_details';
        }
        if (in_array($fulfillment->status, ShareFulfillmentWithCustomerAction::STATUSES, true) && $fulfillment->channel === Fulfillment::CHANNEL_CARRIER && ($can['manage'] || $can['dispatch'])) {
            $actions[] = 'share_with_customer';
        }

        return array_values(array_filter($actions));
    }
}
