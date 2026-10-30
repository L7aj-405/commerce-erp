<?php

namespace App\Actions\Fulfillment;

use App\Enums\FulfillmentStatus;
use App\Models\Fulfillment;
use App\Models\OrganizationContact;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Fulfillment\FulfillmentEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * "Modifier le suivi": carrier, tracking number / URL, carrier reference,
 * package count, expected delivery and note of an open shipment — before or
 * after hand-over. Tracking data only: never a status change, stock movement,
 * reservation, order, invoice or payment change. Audited with old/new values
 * (fulfillment.tracking_updated); an unchanged submit writes nothing.
 */
class UpdateFulfillmentShipmentDetailsAction
{
    public const FIELDS = ['carrier_contact_id', 'tracking_number', 'tracking_url', 'carrier_reference', 'package_count', 'expected_delivery_at', 'note'];

    public function __construct(private readonly FulfillmentEngine $engine) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'carrier_contact_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'tracking_number' => ['nullable', 'string', 'max:128'],
            'tracking_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'carrier_reference' => ['nullable', 'string', 'max:128'],
            'package_count' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'expected_delivery_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @param array<string, mixed> $data */
    public function execute(User $actor, SalesOrder $order, Fulfillment $fulfillment, array $data, ?string $expectedStatus = null): Fulfillment
    {
        $this->engine->authorize($actor, $order, 'fulfillment.manage');
        $data = Validator::make(array_intersect_key($data, array_flip(self::FIELDS)), self::rules())->validate();

        return $this->engine->transaction($order, function (SalesOrder $order) use ($actor, $fulfillment, $data, $expectedStatus) {
            $fulfillment = $this->engine->lockFulfillment($order, $fulfillment, $expectedStatus);
            if (in_array($fulfillment->status, [FulfillmentStatus::Closed, FulfillmentStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['status' => 'Une expédition clôturée ou annulée ne se modifie plus.']);
            }
            $old = [];
            $new = [];
            foreach ($data as $field => $value) {
                $value = is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value);
                if ($field === 'carrier_contact_id') $value = $this->carrier($order, $fulfillment, (int) $value)->getKey();
                $current = $field === 'expected_delivery_at' ? $fulfillment->expected_delivery_at?->toDateTimeString() : $fulfillment->{$field};
                $compare = $field === 'expected_delivery_at' && $value !== null ? CarbonImmutable::parse($value)->toDateTimeString() : $value;
                if ((string) ($current ?? '') === (string) ($compare ?? '')) continue;
                $old[$field] = $current;
                $new[$field] = $compare;
                $fulfillment->{$field} = $value;
            }
            if ($new === []) return $fulfillment;
            $fulfillment->save();
            if (isset($new['carrier_contact_id'])) {
                $fulfillment->load('carrier:id,full_name,company_name');
                $new['carrier_name'] = $fulfillment->carrier?->company_name ?: $fulfillment->carrier?->full_name;
            }
            $this->engine->record('fulfillment.tracking_updated', $actor, $order, $fulfillment, $old, [...$new, 'status' => $fulfillment->status->value]);

            return $fulfillment;
        });
    }

    /** Only a carrier shipment has a carrier, and only an active transport contact of this organization may be it. */
    private function carrier(SalesOrder $order, Fulfillment $fulfillment, int $contactId): OrganizationContact
    {
        if ($fulfillment->channel !== Fulfillment::CHANNEL_CARRIER) {
            throw ValidationException::withMessages(['carrier_contact_id' => 'Une remise directe n’a pas de transporteur.']);
        }

        return OrganizationContact::query()->where('organization_id', $order->organization_id)
            ->where('contact_type', OrganizationContact::TYPE_TRANSPORT)->where('active', true)->whereKey($contactId)->first()
            ?? throw ValidationException::withMessages(['carrier_contact_id' => 'Choisissez un contact transporteur actif de cette organisation.']);
    }
}
