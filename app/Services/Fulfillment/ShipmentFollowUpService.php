<?php

namespace App\Services\Fulfillment;

use App\Enums\FollowUpPriority;
use App\Models\Fulfillment;
use App\Models\FulfillmentCustomerNotification;
use App\Models\FollowUp;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\FollowUps\FollowUpAccess;
use App\Services\FollowUps\FollowUpQuery;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;

/** F1.3 proposal shown after a successful manual shipment share. */
final class ShipmentFollowUpService
{
    public function __construct(
        private readonly FollowUpAccess $access,
        private readonly FollowUpQuery $followUps,
        private readonly ShipmentFollowUpSchedule $schedule,
    ) {}

    /** @return array<string, mixed>|null */
    public function proposal(User $actor, SalesOrder $order, Fulfillment $fulfillment, FulfillmentCustomerNotification $notification): ?array
    {
        $organization = $order->organization;
        if (! $this->access->canCreate($actor, $organization)) {
            return null;
        }
        if ((int) $fulfillment->organization_id !== (int) $organization->getKey()
            || (int) $fulfillment->sales_order_id !== (int) $order->getKey()
            || (int) $notification->organization_id !== (int) $organization->getKey()
            || $notification->fulfillment_id !== $fulfillment->getKey()
            || $notification->origin !== FulfillmentCustomerNotification::ORIGIN_MANUAL
            || ! in_array($notification->status, [FulfillmentCustomerNotification::STATUS_SENT, FulfillmentCustomerNotification::STATUS_OPENED], true)) {
            return null;
        }

        $sharedAt = $notification->sent_at ?? $notification->created_at ?? now();
        $local = BusinessTime::toLocal($organization, CarbonImmutable::instance($sharedAt));
        $existing = FollowUp::query()->where('organization_id', $organization->getKey())
            ->where('source_notification_id', $notification->getKey())->value('id');

        return [
            'fulfillment_id' => (string) $fulfillment->getKey(),
            'source_notification_id' => (int) $notification->getKey(),
            'existing_follow_up_id' => $existing === null ? null : (int) $existing,
            'defaults' => [
                'title' => "Confirmer la réception de {$order->order_number} avec le client",
                'note' => 'Contacter le client pour confirmer la réception de la commande et vérifier que tout est conforme.',
                'priority' => FollowUpPriority::Normal->value,
                'due_date' => $this->schedule->suggestedDate($local)->toDateString(),
                'due_time' => $local->format('H:i'),
            ],
            'prefill' => [
                'customer_id' => $order->customer_id === null ? null : (int) $order->customer_id,
                'quotation_id' => null,
                'sales_order_id' => (int) $order->getKey(),
                'fulfillment_id' => (string) $fulfillment->getKey(),
                'source_notification_id' => (int) $notification->getKey(),
            ],
            'context' => $this->followUps->formContext($actor, $organization, (int) $order->store_id),
        ];
    }

}
