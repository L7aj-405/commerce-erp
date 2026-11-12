<?php

namespace App\Actions\Sales;

use App\Enums\FulfillmentStatus;
use App\Enums\SalesOrderFulfillmentStatus;
use App\Models\Fulfillment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Fulfillment\FulfillmentEngine;
use Illuminate\Validation\ValidationException;

/**
 * Explicit DIRECT hand-over of everything left to ship: the customer takes the
 * goods now (POS counter sale, or the explicit "fulfill" action for a pickup).
 * No transport contact is involved. It records one `direct` fulfillment,
 * delivered at once, and consumes exactly its quantities through the
 * fulfillment engine — so a carrier shipment can never deduct the same stock
 * twice. Never called by document operations: issuing a Bon de livraison
 * moves no stock. Readiness rules are unchanged: every internal transfer and
 * supplier procurement must be received first.
 */
class FulfillSalesOrderAction
{
    public function __construct(private readonly FulfillmentEngine $engine) {}

    public function execute(User $actor, SalesOrder $order): SalesOrder
    {
        $this->engine->authorize($actor, $order, 'sales_orders.fulfill');

        return $this->engine->transaction($order, function (SalesOrder $order) use ($actor) {
            if (! in_array($order->fulfillment_status, [SalesOrderFulfillmentStatus::Unfulfilled, SalesOrderFulfillmentStatus::PartiallyFulfilled], true)) {
                throw ValidationException::withMessages(['order' => 'Only a confirmed, unfulfilled order can be fulfilled.']);
            }
            // Showroom pickup can only complete once every remote quantity has
            // physically arrived. Until the linked Transfer Request is received
            // the order stays operationally "À approvisionner".
            if ($order->awaitingReplenishment()) {
                throw ValidationException::withMessages([
                    'order' => 'À approvisionner : un transfert interne doit être réceptionné avant la remise au client.',
                ]);
            }
            // A confirmed Order still waiting on supplier-procured goods is not
            // ready for customer fulfilment until every procurement is received.
            if ($order->awaitingSupplierProcurement()) {
                throw ValidationException::withMessages([
                    'order' => 'À approvisionner fournisseur : la marchandise fournisseur doit être réceptionnée avant la remise au client.',
                ]);
            }
            if (Fulfillment::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->getKey())
                ->whereIn('status', [FulfillmentStatus::Preparing->value, FulfillmentStatus::Ready->value])->exists()) {
                throw ValidationException::withMessages(['order' => 'Des expéditions sont en préparation : remettez-les ou annulez-les avant la remise directe.']);
            }
            $remaining = $this->engine->remaining($order);
            if ($remaining === []) {
                throw ValidationException::withMessages(['order' => 'Only a confirmed, unfulfilled order can be fulfilled.']);
            }

            $fulfillment = $this->engine->create($actor, $order, $this->engine->validateItems($order, $remaining), FulfillmentStatus::Delivered, Fulfillment::CHANNEL_DIRECT, [
                'delivered_at' => now(),
                'delivered_by_user_id' => $actor->getKey(),
            ]);
            $this->engine->consumeStock($actor, $order, $fulfillment);
            $this->engine->record('fulfillment.delivered', $actor, $order, $fulfillment, [], ['status' => FulfillmentStatus::Delivered->value, 'channel' => Fulfillment::CHANNEL_DIRECT]);

            return $this->engine->syncOrder($actor, $order);
        });
    }
}
