<?php

namespace App\Actions\Fulfillment;

use App\Enums\FulfillmentStatus;
use App\Models\Fulfillment;
use App\Models\FulfillmentCustomerNotification;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Fulfillment\FulfillmentCustomerNotifier;
use App\Services\Fulfillment\FulfillmentEngine;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * handed_to_carrier → delivered (the explicit shortcut, valid in both modes)
 * or in_transit → delivered. Logistics only: stock already left at hand-over,
 * so nothing here consumes a reservation, moves stock or re-syncs the order's
 * fulfillment_status; handed_to_carrier_at is kept.
 *
 * Retry-safe: an already delivered (or closed) shipment is returned as is —
 * no second audit event, timestamp change or customer notification — so a
 * double-click or a retried request is a no-op success.
 */
class MarkFulfillmentDeliveredAction
{
    public function __construct(
        private readonly FulfillmentEngine $engine,
        private readonly FulfillmentCustomerNotifier $notifier,
    ) {}

    public function execute(User $actor, SalesOrder $order, Fulfillment $fulfillment, ?string $deliveredAt = null, ?string $expectedStatus = null): Fulfillment
    {
        $this->engine->authorize($actor, $order, 'fulfillment.mark_delivered');
        $at = filled($deliveredAt) ? CarbonImmutable::parse($deliveredAt) : CarbonImmutable::now();

        return $this->engine->transaction($order, function (SalesOrder $order) use ($actor, $fulfillment, $at, $expectedStatus) {
            $fulfillment = $this->engine->lockFulfillment($order, $fulfillment);
            if ($fulfillment->status->isDelivered()) return $fulfillment;
            if ($expectedStatus !== null && $fulfillment->status->value !== $expectedStatus) {
                throw ValidationException::withMessages(['status' => 'Cette expédition a changé entre-temps : actualisez la page.']);
            }
            $this->engine->assertTransition($fulfillment, [FulfillmentStatus::HandedToCarrier, FulfillmentStatus::InTransit], 'Seule une expédition remise au transporteur ou en transit peut être marquée livrée.');
            if ($at->greaterThan(now()->addMinutes(5)) || ($fulfillment->handed_to_carrier_at && $at->lessThan($fulfillment->handed_to_carrier_at))) {
                throw ValidationException::withMessages(['delivered_at' => 'La date de livraison doit être entre la remise au transporteur et maintenant.']);
            }
            $from = $fulfillment->status->value;
            $fulfillment->forceFill(['status' => FulfillmentStatus::Delivered, 'delivered_at' => $at, 'delivered_by_user_id' => $actor->getKey()])->save();
            $this->engine->record('fulfillment.delivered', $actor, $order, $fulfillment, ['status' => $from], ['status' => FulfillmentStatus::Delivered->value, 'delivered_at' => $at->toIso8601String()]);
            $this->notifier->request($fulfillment, FulfillmentCustomerNotification::EVENT_DELIVERED);

            return $fulfillment;
        });
    }
}
