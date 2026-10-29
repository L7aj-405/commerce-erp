<?php

namespace App\Actions\Fulfillment;

use App\Enums\FulfillmentStatus;
use App\Models\Fulfillment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Fulfillment\FulfillmentEngine;

/** delivered → closed. Only a delivered shipment can be closed. */
class CloseFulfillmentAction
{
    public function __construct(private readonly FulfillmentEngine $engine) {}

    public function execute(User $actor, SalesOrder $order, Fulfillment $fulfillment, ?string $expectedStatus = null): Fulfillment
    {
        $this->engine->authorize($actor, $order, 'fulfillment.manage');

        return $this->engine->transaction($order, function (SalesOrder $order) use ($actor, $fulfillment, $expectedStatus) {
            $fulfillment = $this->engine->lockFulfillment($order, $fulfillment, $expectedStatus);
            $this->engine->assertTransition($fulfillment, [FulfillmentStatus::Delivered], 'Seule une expédition livrée peut être clôturée.');
            $fulfillment->forceFill(['status' => FulfillmentStatus::Closed, 'closed_at' => now()])->save();
            $this->engine->record('fulfillment.closed', $actor, $order, $fulfillment, ['status' => FulfillmentStatus::Delivered->value], ['status' => FulfillmentStatus::Closed->value]);

            return $fulfillment;
        });
    }
}
