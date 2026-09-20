<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
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
        $evaluatedAt ??= CarbonImmutable::now(config('app.timezone'));
        $fulfilledAt = $order->fulfilled_at?->toImmutable();
        $deadline = $fulfilledAt?->addMinutes((int) $policy['window_minutes']);
        $within = $policy['enabled'] && $deadline !== null && $evaluatedAt->lessThanOrEqualTo($deadline);

        return [
            ...$policy,
            'fulfilled_at' => $fulfilledAt?->toIso8601String(),
            'evaluated_at' => $evaluatedAt->toIso8601String(),
            'deadline' => $deadline?->toIso8601String(),
            'within_policy' => $within,
        ];
    }

    public function normalizeWindow(int $value, string $unit): int
    {
        return $value * match ($unit) { 'hours' => 60, 'days' => 1440, default => 1 };
    }
}
