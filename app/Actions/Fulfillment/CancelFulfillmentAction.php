<?php

namespace App\Actions\Fulfillment;

use App\Enums\FulfillmentStatus;
use App\Models\Fulfillment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Fulfillment\FulfillmentEngine;

/**
 * Cancels a shipment that has NOT left yet (preparing / ready): no stock moved,
 * its quantities become available to ship again. Once handed over, stock has
 * left the company; reversing it needs the returns workflow, so it is refused.
 */
class CancelFulfillmentAction
{
    public function __construct(private readonly FulfillmentEngine $engine) {}

    public function execute(User $actor, SalesOrder $order, Fulfillment $fulfillment, ?string $reason = null, ?string $expectedStatus = null): Fulfillment
    {
        $this->engine->authorize($actor, $order, 'fulfillment.manage');

        return $this->engine->transaction($order, function (SalesOrder $order) use ($actor, $fulfillment, $reason, $expectedStatus) {
            $fulfillment = $this->engine->lockFulfillment($order, $fulfillment, $expectedStatus);
            $this->engine->assertTransition($fulfillment, [FulfillmentStatus::Preparing, FulfillmentStatus::Ready],
                'Une expédition déjà remise au transporteur ne peut pas être annulée : le stock est sorti (utilisez un retour client).');
            $from = $fulfillment->status->value;
            $fulfillment->forceFill(['status' => FulfillmentStatus::Cancelled, 'cancelled_at' => now()])->save();
            $this->engine->record('fulfillment.cancelled', $actor, $order, $fulfillment, ['status' => $from], [
                'status' => FulfillmentStatus::Cancelled->value, 'reason' => filled($reason) ? mb_substr(trim((string) $reason), 0, 500) : null,
            ]);

            return $fulfillment;
        });
    }
}
