<?php

namespace App\Services\OrganizationDataReset;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrganizationDataResetPostconditionValidator
{
    /** @var list<string> */
    private const PRESERVED_COUNT_TABLES = [
        'organizations', 'stores', 'warehouses', 'financial_accounts', 'tax_rates', 'units_of_measure',
        'organization_mail_settings', 'organization_security_settings', 'organization_document_stamps',
        'organization_backup_settings', 'organization_backups', 'organization_cloud_backup_connections',
        'organization_backup_cloud_copies', 'woocommerce_integrations',
        'finance_recurring_obligation_templates', 'commission_rule_sets', 'commission_rule_tiers',
        'audit_logs', 'document_email_deliveries', 'user_notifications',
    ];

    public function __construct(private readonly OrganizationDataResetOperationQuery $queries) {}

    /** @return array<string,int> */
    public function preservedCounts(Organization $organization): array
    {
        $counts = [];
        foreach (self::PRESERVED_COUNT_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $counts[$table] = $table === 'organizations'
                ? DB::table($table)->where('id', $organization->getKey())->count()
                : DB::table($table)->where('organization_id', $organization->getKey())->count();
        }

        return $counts;
    }

    /**
     * @param list<OrganizationDataResetOperation> $operations
     * @param array<string,int> $preservedBefore
     */
    public function validate(Organization $organization, array $operations, array $preservedBefore): void
    {
        foreach ($operations as $operation) {
            if ($this->queries->count($organization, $operation) !== 0) {
                throw new OrganizationDataResetException("Reset postcondition failed for [{$operation->key}].");
            }
        }

        if ($preservedBefore !== $this->preservedCounts($organization)) {
            throw new OrganizationDataResetException('A protected configuration, credential, audit or infrastructure record changed during reset.');
        }

        $this->assertNoProtectedOrphans($organization);

        if (DB::connection()->getDriverName() === 'sqlite' && DB::select('PRAGMA foreign_key_check') !== []) {
            throw new OrganizationDataResetException('Reset left invalid database foreign-key references.');
        }
    }

    private function assertNoProtectedOrphans(Organization $organization): void
    {
        $organizationId = (int) $organization->getKey();

        $missingStoreMembership = DB::table('store_memberships as memberships')
            ->leftJoin('stores', function ($join) {
                $join->on('stores.id', '=', 'memberships.store_id')
                    ->on('stores.organization_id', '=', 'memberships.organization_id');
            })
            ->leftJoin('organization_memberships as organization_membership', function ($join) {
                $join->on('organization_membership.organization_id', '=', 'memberships.organization_id')
                    ->on('organization_membership.user_id', '=', 'memberships.user_id');
            })
            ->where('memberships.organization_id', $organizationId)
            ->where(fn ($query) => $query->whereNull('stores.id')->orWhereNull('organization_membership.id'))
            ->exists();

        $missingWooReference = DB::table('woocommerce_integrations as integrations')
            ->leftJoin('stores', function ($join) {
                $join->on('stores.id', '=', 'integrations.default_store_id')
                    ->on('stores.organization_id', '=', 'integrations.organization_id');
            })
            ->leftJoin('warehouses', function ($join) {
                $join->on('warehouses.id', '=', 'integrations.default_warehouse_id')
                    ->on('warehouses.organization_id', '=', 'integrations.organization_id');
            })
            ->where('integrations.organization_id', $organizationId)
            ->where(function ($query) {
                $query->where(fn ($inner) => $inner->whereNotNull('integrations.default_store_id')->whereNull('stores.id'))
                    ->orWhere(fn ($inner) => $inner->whereNotNull('integrations.default_warehouse_id')->whereNull('warehouses.id'));
            })
            ->exists();

        if ($missingStoreMembership || $missingWooReference) {
            throw new OrganizationDataResetException('Reset would leave protected tenant access or integration references orphaned.');
        }
    }
}
