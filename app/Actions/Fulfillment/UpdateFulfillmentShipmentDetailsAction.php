<?php

namespace App\Actions\Fulfillment;

use App\Enums\FulfillmentStatus;
use App\Models\Fulfillment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Fulfillment\FulfillmentEngine;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Tracking number, carrier reference, package count, expected delivery and note of an open shipment. */
class UpdateFulfillmentShipmentDetailsAction
{
    private const FIELDS = ['tracking_number', 'carrier_reference', 'package_count', 'expected_delivery_at', 'note'];

    public function __construct(private readonly FulfillmentEngine $engine) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, SalesOrder $order, Fulfillment $fulfillment, array $data, ?string $expectedStatus = null): Fulfillment
    {
        $this->engine->authorize($actor, $order, 'fulfillment.manage');
        $data = Validator::make(array_intersect_key($data, array_flip(self::FIELDS)), [
            'tracking_number' => ['nullable', 'string', 'max:128'],
            'carrier_reference' => ['nullable', 'string', 'max:128'],
            'package_count' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'expected_delivery_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return $this->engine->transaction($order, function (SalesOrder $order) use ($actor, $fulfillment, $data, $expectedStatus) {
            $fulfillment = $this->engine->lockFulfillment($order, $fulfillment, $expectedStatus);
            if (in_array($fulfillment->status, [FulfillmentStatus::Closed, FulfillmentStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['status' => 'Une expédition clôturée ou annulée ne se modifie plus.']);
            }
            $old = [];
            $new = [];
            foreach ($data as $field => $value) {
                $value = is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value);
                $current = $field === 'expected_delivery_at' ? $fulfillment->expected_delivery_at?->toDateTimeString() : $fulfillment->{$field};
                $compare = $field === 'expected_delivery_at' && $value !== null ? \Carbon\CarbonImmutable::parse($value)->toDateTimeString() : $value;
                if ((string) ($current ?? '') === (string) ($compare ?? '')) continue;
                $old[$field] = $current;
                $new[$field] = $compare;
                $fulfillment->{$field} = $value;
            }
            if ($new === []) return $fulfillment;
            $fulfillment->save();
            $this->engine->record('fulfillment.tracking_updated', $actor, $order, $fulfillment, $old, $new);

            return $fulfillment;
        });
    }
}
