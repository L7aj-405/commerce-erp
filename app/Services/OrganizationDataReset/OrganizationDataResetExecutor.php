<?php

namespace App\Services\OrganizationDataReset;

use App\Models\Organization;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class OrganizationDataResetExecutor
{
    public function __construct(
        private readonly OrganizationDataResetPlanner $planner,
        private readonly OrganizationDataResetBundleRegistry $bundles,
        private readonly OrganizationDataResetDependencyGraph $graph,
        private readonly OrganizationDataResetOperationQuery $queries,
        private readonly OrganizationDataResetSequenceGuard $sequenceGuard,
        private readonly OrganizationDataResetOwnerGuard $ownerGuard,
        private readonly OrganizationDataResetPostconditionValidator $postconditions,
        private readonly OrganizationDataResetFileCleanup $fileCleanup,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(
        Organization $organization,
        User $actor,
        OrganizationDataResetPlan $approvedPlan,
    ): OrganizationDataResetResult {
        if ((int) $organization->getKey() !== $approvedPlan->organizationId) {
            throw new OrganizationDataResetException('The reset plan belongs to another organization.');
        }
        if ((int) $organization->owner_id !== (int) $actor->getKey()
            && ! $actor->hasPermission($organization, 'system.data_reset')) {
            throw new OrganizationDataResetException('The actor is not authorized to execute an organization data reset.');
        }
        if (! $approvedPlan->isExecutable()) {
            throw new OrganizationDataResetBlockedException($approvedPlan->blockers);
        }

        $scope = OrganizationDataResetScope::from($approvedPlan->requestedScope);
        $result = DB::transaction(function () use ($organization, $actor, $approvedPlan, $scope): OrganizationDataResetResult {
            $lockedOrganization = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $currentPlan = $this->planner->plan($lockedOrganization, $scope);
            if ($currentPlan->fingerprint !== $approvedPlan->fingerprint) {
                throw new OrganizationDataResetStalePlanException;
            }
            $alreadyCompleted = AuditLog::query()
                ->where('organization_id', $lockedOrganization->getKey())
                ->where('event', 'organization.data_reset.completed')
                ->get(['new_values'])
                ->contains(fn (AuditLog $log) => hash_equals(
                    (string) ($log->new_values['plan_fingerprint'] ?? ''),
                    $approvedPlan->fingerprint,
                ));
            if ($alreadyCompleted) {
                throw new OrganizationDataResetAlreadyExecutedException;
            }
            if (! $currentPlan->isExecutable()) {
                throw new OrganizationDataResetBlockedException($currentPlan->blockers);
            }

            $operations = $this->graph->order($this->bundles->operationsFor($scope));
            $numberingBefore = $this->sequenceGuard->snapshot($lockedOrganization);
            $ownerBefore = $this->ownerGuard->snapshot($lockedOrganization);
            $preservedBefore = $this->postconditions->preservedCounts($lockedOrganization);
            $affected = [];

            foreach ($operations as $operation) {
                $affected[$operation->key] = $this->queries->execute($lockedOrganization, $operation);
                if ($affected[$operation->key] !== ($approvedPlan->counts[$operation->key] ?? null)) {
                    throw new OrganizationDataResetException("Affected count drifted while executing [{$operation->key}].");
                }
            }

            $this->postconditions->validate($lockedOrganization, $operations, $preservedBefore);
            $this->sequenceGuard->assertUnchanged($lockedOrganization, $numberingBefore);
            $this->ownerGuard->assertUnchanged($lockedOrganization, $ownerBefore);

            $audit = $this->audit->record(
                'organization.data_reset.completed',
                $actor,
                $lockedOrganization,
                auditable: $lockedOrganization,
                newValues: [
                    'scope' => $scope->value,
                    'plan_fingerprint' => $approvedPlan->fingerprint,
                    'affected' => $affected,
                ],
            );

            // No current reset bundle deletes persisted files. This hook makes
            // future file-bearing operations commit-safe by construction.
            $this->fileCleanup->afterCommit([]);

            return new OrganizationDataResetResult(
                (int) $lockedOrganization->getKey(),
                $scope->value,
                $affected,
                (int) $audit->getKey(),
            );
        });

        return $result;
    }
}
