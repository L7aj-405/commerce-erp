<?php

namespace App\Actions\Settings;

use App\Models\Fulfillment;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Fulfillment\FulfillmentCustomerNotifier;
use App\Services\Fulfillment\FulfillmentEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

/**
 * Sets settings['fulfillment_mode'] (simple | advanced) and, when given,
 * settings['fulfillment_customer_notifications'] (F1.2 shipment emails to the
 * customer, on by default). Only those keys are touched, under a row lock.
 * Existing shipments keep the mode they were created with
 * (fulfillments.workflow_mode). Audited as organization.settings_changed.
 */
class UpdateOrganizationFulfillmentModeAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Organization $organization, string $mode, ?bool $customerNotifications = null): Organization
    {
        Validator::make(['fulfillment_mode' => $mode], ['fulfillment_mode' => ['required', Rule::in(Fulfillment::MODES)]])->validate();

        return DB::transaction(function () use ($actor, $organization, $mode, $customerNotifications) {
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $settings = is_array($locked->settings) ? $locked->settings : [];
            $old = [];
            $new = [];
            $previous = FulfillmentEngine::modeFor($locked);
            if ($previous !== $mode || ($settings['fulfillment_mode'] ?? null) !== $mode) {
                $old['fulfillment_mode'] = $previous;
                $new['fulfillment_mode'] = $mode;
                $settings['fulfillment_mode'] = $mode;
            }
            $notifying = FulfillmentCustomerNotifier::enabledFor($locked);
            if ($customerNotifications !== null && $customerNotifications !== $notifying) {
                $old[FulfillmentCustomerNotifier::SETTING] = $notifying;
                $new[FulfillmentCustomerNotifier::SETTING] = $customerNotifications;
                $settings[FulfillmentCustomerNotifier::SETTING] = $customerNotifications;
            }
            if ($new === []) return $locked;
            $locked->settings = $settings;
            $locked->save();
            $this->audit->record('organization.settings_changed', $actor, $locked, auditable: $locked, oldValues: $old, newValues: $new);

            return $locked;
        });
    }
}
