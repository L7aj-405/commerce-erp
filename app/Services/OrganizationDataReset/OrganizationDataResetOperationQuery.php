<?php

namespace App\Services\OrganizationDataReset;

use App\Models\Organization;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrganizationDataResetOperationQuery
{
    public function count(Organization $organization, OrganizationDataResetOperation $operation): int
    {
        if (! Schema::hasTable($operation->table)) {
            return 0;
        }

        return $this->query($organization, $operation)->count();
    }

    public function execute(Organization $organization, OrganizationDataResetOperation $operation): int
    {
        if (! Schema::hasTable($operation->table)) {
            return 0;
        }

        $query = $this->query($organization, $operation);

        return match ($operation->action) {
            OrganizationDataResetOperation::SET_NULL => $query->update([$operation->column => null]),
            OrganizationDataResetOperation::CLEAR_NON_OWNER_ACTIVE_CONTEXT => $query->update([
                'active_organization_id' => null,
                'active_store_id' => null,
            ]),
            default => $query->delete(),
        };
    }

    public function stateFingerprint(Organization $organization, OrganizationDataResetOperation $operation): string
    {
        if (! Schema::hasTable($operation->table)) {
            return hash('sha256', 'missing');
        }

        $rowHashes = $this->query($organization, $operation)
            ->get()
            ->map(function (object $row): string {
                $values = (array) $row;
                ksort($values);

                return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
            })
            ->sort()
            ->values()
            ->all();

        return hash('sha256', implode('|', $rowHashes));
    }

    private function query(Organization $organization, OrganizationDataResetOperation $operation): Builder
    {
        $organizationId = (int) $organization->getKey();
        $ownerId = (int) $organization->owner_id;

        return match ($operation->action) {
            OrganizationDataResetOperation::SET_NULL => DB::table($operation->table)
                ->where('organization_id', $organizationId)
                ->whereNotNull($operation->column),
            OrganizationDataResetOperation::CLEAR_NON_OWNER_ACTIVE_CONTEXT => DB::table('users')
                ->where('id', '!=', $ownerId)
                ->where(function ($query) use ($organizationId) {
                    $query->where('active_organization_id', $organizationId)
                        ->orWhereIn('active_store_id', DB::table('stores')->select('id')->where('organization_id', $organizationId));
                }),
            OrganizationDataResetOperation::DELETE_NON_OWNER_STORE_MEMBERSHIPS => DB::table('store_memberships')
                ->where('organization_id', $organizationId)
                ->where('user_id', '!=', $ownerId),
            OrganizationDataResetOperation::DELETE_NON_OWNER_MEMBERSHIPS => DB::table('organization_memberships')
                ->where('organization_id', $organizationId)
                ->where('user_id', '!=', $ownerId),
            OrganizationDataResetOperation::DELETE_CUSTOM_ROLE_PERMISSIONS => DB::table('role_permission')
                ->where('organization_id', $organizationId)
                ->whereIn('role_id', DB::table('roles')->select('id')
                    ->where('organization_id', $organizationId)->where('is_system', false)),
            OrganizationDataResetOperation::DELETE_CUSTOM_ROLES => DB::table('roles')
                ->where('organization_id', $organizationId)
                ->where('is_system', false),
            default => DB::table($operation->table)->where('organization_id', $organizationId),
        };
    }
}
