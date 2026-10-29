<?php

namespace App\Actions\Fulfillment;

use App\Enums\FulfillmentStatus;
use App\Models\Fulfillment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Fulfillment\FulfillmentEngine;
use Illuminate\Validation\ValidationException;

/** Advanced mode: handed_to_carrier → in_transit. */
class MarkFulfillmentInTransitAction
{
    public function __construct(private readonly FulfillmentEngine $engine) {}

    public function execute(User $actor, SalesOrder $order, Fulfillment $fulfillment, ?string $expectedStatus = null): Fulfillment
    {
        $this->engine->authorize($actor, $order, 'fulfillment.dispatch');

        return $this->engine->transaction($order, function (SalesOrder $order) use ($actor, $fulfillment, $expectedStatus) {
            $fulfillment = $this->engine->lockFulfillment($order, $fulfillment, $expectedStatus);
            if ($fulfillment->workflow_mode !== Fulfillment::MODE_ADVANCED) {
                throw ValidationException::withMessages(['mode' => 'Le suivi « en transit » n’existe qu’en mode d’expédition avancé.']);
            }
            $this->engine->assertTransition($fulfillment, [FulfillmentStatus::HandedToCarrier], 'Seule une expédition remise au transporteur peut passer en transit.');
            $fulfillment->forceFill(['status' => FulfillmentStatus::InTransit, 'in_transit_at' => now()])->save();
            $this->engine->record('fulfillment.in_transit', $actor, $order, $fulfillment, ['status' => FulfillmentStatus::HandedToCarrier->value], ['status' => FulfillmentStatus::InTransit->value]);

            return $fulfillment;
        });
    }
}
