<?php

namespace App\Actions\Settings;

use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\BusinessTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Sets the organization business timezone (settings['timezone']), the single
 * source read by Organization::businessTimezone(). Only that key is touched;
 * other settings sections are preserved under a row lock. Audited with the
 * existing `organization.settings_changed` event.
 */
class UpdateOrganizationTimezoneAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Organization $organization, string $timezone): Organization
    {
        $timezone = trim($timezone);
        Validator::make(['timezone' => $timezone], ['timezone' => BusinessTime::rules()])->validate();

        return DB::transaction(function () use ($actor, $organization, $timezone) {
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $settings = is_array($locked->settings) ? $locked->settings : [];
            $previous = $settings['timezone'] ?? null;
            if ($previous === $timezone) {
                return $locked;
            }

            $settings['timezone'] = $timezone;
            $locked->settings = $settings;
            $locked->save();

            $this->audit->record(
                'organization.settings_changed',
                $actor,
                $locked,
                auditable: $locked,
                oldValues: ['timezone' => $previous],
                newValues: ['timezone' => $timezone],
            );

            return $locked;
        });
    }
}
