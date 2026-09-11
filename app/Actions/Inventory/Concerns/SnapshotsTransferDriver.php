<?php

namespace App\Actions\Inventory\Concerns;

use App\Models\TransferRequest;
use App\Models\User;

trait SnapshotsTransferDriver
{
    /**
     * Apply the optional chauffeur / vehicle details to a Transfer Request as an
     * immutable display snapshot. If an ERP user id is supplied it is validated
     * to be an active member of the same organisation and their current name is
     * captured as `driver_name` (unless the caller passed an explicit name).
     * Only keys actually present in `$data` are touched; an empty string clears
     * a field.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null  the snapshot written, or null when nothing was supplied
     */
    private function applyDriverSnapshot(TransferRequest $request, array $data): ?array
    {
        if ($data === [] || collect($data)->every(fn ($value) => $value === null || trim((string) $value) === '')) {
            return null;
        }

        $member = null;
        if (! empty($data['driver_user_id'])) {
            $member = User::query()
                ->whereKey($data['driver_user_id'])
                ->whereHas('organizationMemberships', fn ($query) => $query
                    ->where('organization_id', $request->organization_id)
                    ->where('status', 'active'))
                ->first();
            abort_unless($member !== null, 404);
        }

        if (array_key_exists('driver_user_id', $data)) {
            $request->driver_user_id = $member?->getKey();
        }

        $name = trim((string) ($data['driver_name'] ?? ''));
        if ($name === '' && $member !== null) {
            $name = (string) $member->name;
        }
        if ($name !== '' || array_key_exists('driver_name', $data) || $member !== null) {
            $request->driver_name = $name !== '' ? $name : null;
        }

        foreach (['driver_phone', 'vehicle', 'vehicle_registration', 'shipping_note'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = trim((string) $data[$field]);
                $request->{$field} = $value !== '' ? $value : null;
            }
        }

        return [
            'driver_user_id' => $request->driver_user_id,
            'driver_name' => $request->driver_name,
            'driver_phone' => $request->driver_phone,
            'vehicle' => $request->vehicle,
            'vehicle_registration' => $request->vehicle_registration,
        ];
    }
}
