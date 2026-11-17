<?php

namespace App\Actions\Settings;

use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FiscalDocumentProtection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Switch an organization between real (`protected`) and declared test
 * (`test`) fiscal data — the explicit distinction the data-reset engines
 * rely on (see FiscalDocumentProtection).
 *
 *  - test → protected ("passer en production"): protection starts now.
 *    Documents created while in test mode stay cleanable test data;
 *    everything issued / posted from now on is protected.
 *  - protected → test: PLATFORM OPERATORS ONLY (`organization:fiscal-data-mode`),
 *    reserved for legacy demonstration organizations. Test data now lives in
 *    Test Mode (`sandbox_id`, see App\Services\Sandbox): no tenant user can
 *    lift fiscal protection from the UI any more. Without --force the command
 *    still refuses while a protected fiscal document exists.
 *
 * Runs under the organization row lock: inserts of fiscal documents take a
 * shared lock on that row (foreign-key check), so no document can slip in
 * between the "nothing protected" check and the switch.
 */
class SetOrganizationFiscalDataModeAction
{
    public function __construct(private readonly FiscalDocumentProtection $fiscal, private readonly AuditLogger $audit) {}

    public function execute(?User $actor, Organization $organization, string $mode, string $reason, bool $platformOverride = false): Organization
    {
        if (! in_array($mode, [FiscalDocumentProtection::MODE_PROTECTED, FiscalDocumentProtection::MODE_TEST], true)) {
            throw ValidationException::withMessages(['mode' => 'Mode de données fiscales inconnu.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Le motif est obligatoire.']);
        }
        if (! $platformOverride && $mode === FiscalDocumentProtection::MODE_TEST) {
            throw ValidationException::withMessages(['mode' => 'Les données de test se gèrent dans le Mode Test. Cette organisation ne peut pas être déclarée comme organisation de test depuis l’application.']);
        }
        if (! $platformOverride) {
            abort_unless($actor !== null && $actor->active_organization_id === $organization->getKey(), 403);
            $isOwner = (int) $organization->owner_id === (int) $actor->getKey();
            abort_unless($mode === FiscalDocumentProtection::MODE_TEST
                ? $isOwner
                : ($isOwner || $actor->hasPermission($organization, 'system.data_reset')), 403);
        }

        return DB::transaction(function () use ($actor, $organization, $mode, $reason, $platformOverride) {
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $old = ['fiscal_data_mode' => $this->fiscal->mode($locked), 'fiscal_protection_started_at' => $locked->fiscal_protection_started_at?->toIso8601String()];
            if ($this->fiscal->mode($locked) === $mode) {
                return $locked;
            }

            if ($mode === FiscalDocumentProtection::MODE_TEST) {
                $counts = array_filter($this->fiscal->protectedCounts($locked));
                if ($counts !== [] && ! $platformOverride) {
                    throw ValidationException::withMessages(['mode' => 'Cette organisation contient déjà '.array_sum($counts).' document(s) fiscal(aux) réel(s) (factures émises, avoirs, paiements ou remboursements). '
                        .'Elle ne peut plus être déclarée comme organisation de test : ces documents restent protégés contre toute suppression.']);
                }
                $locked->fiscal_data_mode = FiscalDocumentProtection::MODE_TEST;
            } else {
                $locked->fiscal_data_mode = FiscalDocumentProtection::MODE_PROTECTED;
                $locked->fiscal_protection_started_at = now();
            }
            $locked->save();

            $this->audit->record('organization.fiscal_data_mode_changed', $actor, $locked, auditable: $locked, oldValues: $old, newValues: [
                'fiscal_data_mode' => $locked->fiscal_data_mode,
                'fiscal_protection_started_at' => $locked->fiscal_protection_started_at?->toIso8601String(),
                'reason' => trim($reason),
                'platform_override' => $platformOverride,
            ]);

            return $locked;
        });
    }
}
