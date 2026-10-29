<?php

namespace App\Actions\Settings;

use App\Models\Fulfillment;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Fulfillment\FulfillmentEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

/**
 * Sets settings['fulfillment_mode'] (simple | advanced). Only that key is
 * touched, under a row lock. Existing shipments keep the mode they were
 * created with (fulfillments.workflow_mode). Audited as organization.settings_changed.
 */
class UpdateOrganizationFulfillmentModeAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Organization $organization, string $mode): Organization
    {
        Validator::make(['fulfillment_mode' => $mode], ['fulfillment_mode' => ['required', Rule::in(Fulfillment::MODES)]])->validate();

        return DB::transaction(function () use ($actor, $organization, $mode) {
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $previous = FulfillmentEngine::modeFor($locked);
            if ($previous === $mode && (($locked->settings['fulfillment_mode'] ?? null) === $mode)) return $locked;
            $settings = is_array($locked->settings) ? $locked->settings : [];
            $settings['fulfillment_mode'] = $mode;
            $locked->settings = $settings;
            $locked->save();
            $this->audit->record('organization.settings_changed', $actor, $locked, auditable: $locked,
                oldValues: ['fulfillment_mode' => $previous], newValues: ['fulfillment_mode' => $mode]);

            return $locked;
        });
    }
}
