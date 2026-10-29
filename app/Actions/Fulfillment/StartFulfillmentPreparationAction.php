<?php

namespace App\Actions\Fulfillment;

use App\Enums\FulfillmentStatus;
use App\Models\Fulfillment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Fulfillment\FulfillmentEngine;
use Illuminate\Validation\ValidationException;

/** Advanced mode: start preparing a shipment of explicit Sales Order line quantities. No stock moves yet. */
class StartFulfillmentPreparationAction
{
    public function __construct(private readonly FulfillmentEngine $engine) {}

    /** @param array<int|string, mixed> $items line id => quantity */
    public function execute(User $actor, SalesOrder $order, array $items, ?string $note = null, ?string $clientOperationId = null): Fulfillment
    {
        $this->engine->authorize($actor, $order, 'fulfillment.manage');

        return $this->engine->transaction($order, function (SalesOrder $order) use ($actor, $items, $note, $clientOperationId) {
            if ($clientOperationId !== null && ($existing = $this->replay($order, $clientOperationId)) !== null) return $existing;
            if (FulfillmentEngine::modeFor($order->organization) !== Fulfillment::MODE_ADVANCED) {
                throw ValidationException::withMessages(['mode' => 'La préparation n’est utilisée qu’en mode d’expédition avancé.']);
            }
            $this->engine->assertDispatchable($order);
            $validated = $this->engine->validateItems($order, $items);
            $fulfillment = $this->engine->create($actor, $order, $validated, FulfillmentStatus::Preparing, Fulfillment::CHANNEL_CARRIER, [
                'note' => filled($note) ? trim((string) $note) : null,
                'preparation_started_at' => now(),
                'prepared_by_user_id' => $actor->getKey(),
            ], $clientOperationId);
            $this->engine->record('fulfillment.preparation_started', $actor, $order, $fulfillment, [], ['status' => FulfillmentStatus::Preparing->value]);

            return $fulfillment;
        });
    }

    private function replay(SalesOrder $order, string $clientOperationId): ?Fulfillment
    {
        $existing = Fulfillment::query()->where('organization_id', $order->organization_id)->where('client_operation_id', $clientOperationId)->first();
        if ($existing && (int) $existing->sales_order_id !== (int) $order->getKey()) {
            throw ValidationException::withMessages(['client_operation_id' => 'Cette opération appartient à une autre commande.']);
        }

        return $existing;
    }
}
