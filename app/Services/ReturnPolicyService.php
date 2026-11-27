<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;

class ReturnPolicyService
{
    /** @return array<string, mixed> */
    public function resolve(Organization $organization, Store $store): array
    {
        $defaults = [
            'enabled' => false,
            'window_value' => 7,
            'window_unit' => 'days',
            'window_minutes' => 10080,
            'allow_partial' => true,
            'allow_full' => true,
            'manager_override_allowed' => false,
            'require_reason' => true,
            'default_disposition' => 'restock',
        ];
        $organizationPolicy = array_replace($defaults, (array) data_get($organization->settings, 'return_policy', []));
        $storePolicy = data_get($store->settings, 'return_policy');

        return is_array($storePolicy) && ! ($storePolicy['inherit'] ?? false)
            ? array_replace($organizationPolicy, $storePolicy, ['source' => 'store'])
            : array_replace($organizationPolicy, ['source' => 'organization']);
    }

    /** @return array<string, mixed> */
    public function evaluate(SalesOrder $order, ?CarbonImmutable $evaluatedAt = null): array
    {
        $order->loadMissing(['organization', 'store']);
        $policy = $this->resolve($order->organization, $order->store);
        // Every instant is compared and reported in the organization's business timezone (the comparison itself is
        // timezone-independent; only the serialized offsets follow the business calendar).
        $evaluatedAt = $evaluatedAt !== null
            ? BusinessTime::toLocal($order->organization, $evaluatedAt)
            : BusinessTime::now($order->organization);
        $fulfilledAt = $order->fulfilled_at ? BusinessTime::toLocal($order->organization, $order->fulfilled_at) : null;
        $deadline = $fulfilledAt?->addMinutes((int) $policy['window_minutes']);
        // Purely temporal: is "now" still inside the window, regardless of whether returns are enabled?
        $windowOpen = $deadline !== null && $evaluatedAt->lessThanOrEqualTo($deadline);
        $within = (bool) $policy['enabled'] && $windowOpen;

        // The single named reason a return can or cannot start. UIs must show this, never infer it from within_policy
        // (which is false both when returns are disabled and when the deadline has passed).
        $status = match (true) {
            $fulfilledAt === null => 'not_fulfilled',
            ! $policy['enabled'] => 'disabled',
            $windowOpen => 'within_window',
            default => 'window_expired',
        };

        return [
            ...$policy,
            'fulfilled_at' => $fulfilledAt?->toIso8601String(),
            'evaluated_at' => $evaluatedAt->toIso8601String(),
            'deadline' => $deadline?->toIso8601String(),
            'window_open' => $windowOpen,
            'within_policy' => $within,
            'status' => $status,
        ];
    }

    public function normalizeWindow(int $value, string $unit): int
    {
        return $value * match ($unit) {
            'hours' => 60, 'days' => 1440, default => 1
        };
    }
}
