<?php

namespace App\Actions\Fulfillment;

use App\Enums\FulfillmentStatus;
use App\Models\Fulfillment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Fulfillment\FulfillmentEngine;

/** Advanced mode: preparing → ready. */
class MarkFulfillmentReadyAction
{
    public function __construct(private readonly FulfillmentEngine $engine) {}

    public function execute(User $actor, SalesOrder $order, Fulfillment $fulfillment, ?string $expectedStatus = null): Fulfillment
    {
        $this->engine->authorize($actor, $order, 'fulfillment.manage');

        return $this->engine->transaction($order, function (SalesOrder $order) use ($actor, $fulfillment, $expectedStatus) {
            $fulfillment = $this->engine->lockFulfillment($order, $fulfillment, $expectedStatus);
            $this->engine->assertTransition($fulfillment, [FulfillmentStatus::Preparing], 'Seule une expédition en préparation peut être marquée prête.');
            $fulfillment->forceFill(['status' => FulfillmentStatus::Ready, 'ready_at' => now()])->save();
            $this->engine->record('fulfillment.ready', $actor, $order, $fulfillment, ['status' => FulfillmentStatus::Preparing->value], ['status' => FulfillmentStatus::Ready->value]);

            return $fulfillment;
        });
    }
}
