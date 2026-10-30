<?php

namespace App\Actions\Fulfillment;

use App\Enums\FulfillmentStatus;
use App\Models\Fulfillment;
use App\Models\FulfillmentCustomerNotification;
use App\Models\OrganizationContact;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Fulfillment\FulfillmentCustomerNotifier;
use App\Services\Fulfillment\FulfillmentEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * "Remis au transporteur" — NOT delivered. The goods leave with a transport
 * contact and the shipped quantities leave stock (once).
 *
 * - Simple mode: creates the shipment from explicit line quantities.
 * - Advanced mode (or an existing ready shipment): ready → handed_to_carrier.
 */
class HandFulfillmentToCarrierAction
{
    public function __construct(
        private readonly FulfillmentEngine $engine,
        private readonly FulfillmentCustomerNotifier $notifier,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'carrier_contact_id' => ['required', 'integer', 'min:1'],
            'handed_at' => ['required', 'date'],
            'tracking_number' => ['nullable', 'string', 'max:128'],
            'tracking_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'carrier_reference' => ['nullable', 'string', 'max:128'],
            'package_count' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'expected_delivery_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @param array<string, mixed> $data shipment fields (rules()) + optional items (line id => quantity)
     */
    public function execute(User $actor, SalesOrder $order, array $data, ?Fulfillment $fulfillment = null, ?string $expectedStatus = null, ?string $clientOperationId = null): Fulfillment
    {
        $this->engine->authorize($actor, $order, 'fulfillment.dispatch');
        $shipment = Validator::make($data, self::rules())->validate();
        $handedAt = CarbonImmutable::parse($shipment['handed_at']);
        if ($handedAt->greaterThan(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['handed_at' => 'La date de remise ne peut pas être dans le futur.']);
        }

        return $this->engine->transaction($order, function (SalesOrder $order) use ($actor, $data, $shipment, $handedAt, $fulfillment, $expectedStatus, $clientOperationId) {
            if ($fulfillment === null && $clientOperationId !== null) {
                $existing = Fulfillment::query()->where('organization_id', $order->organization_id)->where('client_operation_id', $clientOperationId)->first();
                if ($existing) {
                    if ((int) $existing->sales_order_id !== (int) $order->getKey()) {
                        throw ValidationException::withMessages(['client_operation_id' => 'Cette opération appartient à une autre commande.']);
                    }

                    return $existing;
                }
            }
            $this->engine->assertDispatchable($order);
            $carrier = OrganizationContact::query()->where('organization_id', $order->organization_id)
                ->where('contact_type', OrganizationContact::TYPE_TRANSPORT)->where('active', true)->whereKey($shipment['carrier_contact_id'])->first()
                ?? throw ValidationException::withMessages(['carrier_contact_id' => 'Choisissez un contact transporteur actif de cette organisation.']);
            $attributes = [
                'carrier_contact_id' => $carrier->getKey(),
                'tracking_number' => filled($shipment['tracking_number'] ?? null) ? trim($shipment['tracking_number']) : null,
                'tracking_url' => filled($shipment['tracking_url'] ?? null) ? trim($shipment['tracking_url']) : null,
                'carrier_reference' => filled($shipment['carrier_reference'] ?? null) ? trim($shipment['carrier_reference']) : null,
                'package_count' => $shipment['package_count'] ?? null,
                'expected_delivery_at' => $shipment['expected_delivery_at'] ?? null,
                'handed_to_carrier_at' => $handedAt,
                'handed_by_user_id' => $actor->getKey(),
            ];
            if (filled($shipment['note'] ?? null)) $attributes['note'] = trim($shipment['note']);

            if ($fulfillment === null) {
                if (FulfillmentEngine::modeFor($order->organization) !== Fulfillment::MODE_SIMPLE) {
                    throw ValidationException::withMessages(['mode' => 'En mode avancé, préparez puis marquez l’expédition prête avant la remise au transporteur.']);
                }
                $items = $this->engine->validateItems($order, (array) ($data['items'] ?? []));
                $fulfillment = $this->engine->create($actor, $order, $items, FulfillmentStatus::HandedToCarrier, Fulfillment::CHANNEL_CARRIER, $attributes, $clientOperationId);
                $from = null;
            } else {
                $fulfillment = $this->engine->lockFulfillment($order, $fulfillment, $expectedStatus);
                $this->engine->assertTransition($fulfillment, [FulfillmentStatus::Ready], 'Seule une expédition prête peut être remise au transporteur.');
                $fulfillment->forceFill([...$attributes, 'status' => FulfillmentStatus::HandedToCarrier])->save();
                $from = FulfillmentStatus::Ready->value;
            }

            $movements = $this->engine->consumeStock($actor, $order, $fulfillment);
            $this->engine->syncOrder($actor, $order);
            $this->engine->record('fulfillment.handed_to_carrier', $actor, $order, $fulfillment, $from ? ['status' => $from] : [], [
                'status' => FulfillmentStatus::HandedToCarrier->value,
                'carrier_contact_id' => $carrier->getKey(), 'carrier_name' => $carrier->company_name ?: $carrier->full_name,
                'tracking_number' => $fulfillment->tracking_number, 'tracking_url' => $fulfillment->tracking_url, 'carrier_reference' => $fulfillment->carrier_reference,
                'package_count' => $fulfillment->package_count, 'handed_at' => $handedAt->toIso8601String(),
                'consumed_reservation_ids' => $movements,
            ]);
            // "Votre commande … a été confiée au transporteur" — queued after commit, once per shipment.
            $this->notifier->request($fulfillment, FulfillmentCustomerNotification::EVENT_HANDED_TO_CARRIER);

            return $fulfillment->refresh();
        });
    }
}
