<?php

namespace App\Services\OrganizationDataReset;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrganizationDataResetPlanner
{
    public function __construct(
        private readonly OrganizationDataResetBundleRegistry $bundles,
        private readonly OrganizationDataResetDependencyGraph $graph,
        private readonly OrganizationDataResetOperationQuery $queries,
        private readonly OrganizationDataResetSequenceGuard $sequenceGuard,
        private readonly OrganizationDataResetLogicalReferenceRegistry $logicalReferences,
    ) {}

    public function plan(Organization $organization, OrganizationDataResetScope $scope): OrganizationDataResetPlan
    {
        $operations = $this->graph->order($this->bundles->operationsFor($scope));
        $this->sequenceGuard->assertOperationsSafe($operations);

        $counts = [];
        $states = [];
        foreach ($operations as $operation) {
            $counts[$operation->key] = $this->queries->count($organization, $operation);
            $states[$operation->key] = $this->queries->stateFingerprint($organization, $operation);
        }

        $blockers = array_map(
            fn (OrganizationDataResetBlocker $blocker) => $blocker->toArray(),
            $this->blockers($organization, $scope),
        );
        $serializedOperations = array_map(fn (OrganizationDataResetOperation $operation) => [
            'key' => $operation->key,
            'scope' => $operation->scope->value,
            'table' => $operation->table,
            'action' => $operation->action,
            'column' => $operation->column,
        ], $operations);
        $scopes = array_map(fn (OrganizationDataResetScope $expanded) => $expanded->value, $scope->expanded());
        $fingerprint = hash('sha256', json_encode([
            'organization_id' => (int) $organization->getKey(),
            'requested_scope' => $scope->value,
            'scopes' => $scopes,
            'operations' => $serializedOperations,
            'counts' => $counts,
            'states' => $states,
            'blockers' => $blockers,
        ], JSON_THROW_ON_ERROR));

        return new OrganizationDataResetPlan(
            (int) $organization->getKey(),
            $scope->value,
            $scopes,
            $serializedOperations,
            $counts,
            $blockers,
            array_values(array_unique(array_merge(
                $this->sequenceGuard->protectedTables(),
                array_keys(array_filter(
                    $this->logicalReferences->all(),
                    fn (array $definition) => $definition['policy'] === 'preserve',
                )),
            ))),
            $fingerprint,
            CarbonImmutable::now(),
        );
    }

    /** @return list<OrganizationDataResetBlocker> */
    private function blockers(Organization $organization, OrganizationDataResetScope $requested): array
    {
        $selected = array_fill_keys(array_map(fn ($scope) => $scope->value, $requested->expanded()), true);
        $has = fn (OrganizationDataResetScope $scope) => isset($selected[$scope->value]);
        $organizationId = (int) $organization->getKey();
        $blockers = [];

        if ($has(OrganizationDataResetScope::SalesAndDocuments) && ! $has(OrganizationDataResetScope::CatalogAndStock)) {
            $count = DB::table('transfer_requests as sales_requests')
                ->join('transfer_requests as retained_requests', function ($join) {
                    $join->on('retained_requests.organization_id', '=', 'sales_requests.organization_id')
                        ->on('retained_requests.stock_transfer_id', '=', 'sales_requests.stock_transfer_id');
                })
                ->where('sales_requests.organization_id', $organizationId)
                ->whereNotNull('sales_requests.sales_order_id')
                ->whereNotNull('sales_requests.stock_transfer_id')
                ->whereNull('retained_requests.sales_order_id')
                ->distinct('sales_requests.stock_transfer_id')
                ->count('sales_requests.stock_transfer_id');
            if ($count > 0) {
                $blockers[] = new OrganizationDataResetBlocker('sales.shared_stock_transfer', 'A completed stock transfer is shared with retained non-sales logistics data.', $count, [OrganizationDataResetScope::CatalogAndStock->value]);
            }
        }
        if ($has(OrganizationDataResetScope::TransactionalFinance) && ! $has(OrganizationDataResetScope::SalesAndDocuments)) {
            $count = $this->sum($organizationId, ['payment_allocations', 'payment_refunds', 'finance_expected_collections', 'customer_exchange_payments']);
            if ($count > 0) {
                $blockers[] = new OrganizationDataResetBlocker('finance.sales_dependencies', 'Transactional Finance records are allocated to retained sales/documents.', $count, [OrganizationDataResetScope::SalesAndDocuments->value]);
            }
        }
        if ($has(OrganizationDataResetScope::CatalogAndStock) && ! $has(OrganizationDataResetScope::SalesAndDocuments)) {
            $count = $this->countWhereNotNull($organizationId, 'sales_order_lines', 'product_variant_id')
                + $this->countWhereNotNull($organizationId, 'invoice_lines', 'product_variant_id')
                + $this->countWhereNotNull($organizationId, 'delivery_note_lines', 'product_variant_id')
                + $this->countWhereNotNull($organizationId, 'quotation_lines', 'product_variant_id')
                + $this->countWhereNotNull($organizationId, 'quotation_lines', 'non_stock_item_id')
                + $this->countWhereNotNull($organizationId, 'customer_return_lines', 'product_variant_id')
                + $this->countWhereNotNull($organizationId, 'out_of_stock_articles', 'resolved_product_variant_id');
            if ($count > 0) {
                $blockers[] = new OrganizationDataResetBlocker('catalog.sales_dependencies', 'Catalog records are referenced by retained sales/documents.', $count, [OrganizationDataResetScope::SalesAndDocuments->value]);
            }
        }
        if ($has(OrganizationDataResetScope::CatalogAndStock) && ! $has(OrganizationDataResetScope::SuppliersAndPurchasing)) {
            $count = $this->count($organizationId, 'sales_order_procurements');
            if ($count > 0) {
                $blockers[] = new OrganizationDataResetBlocker('catalog.procurement_dependencies', 'Catalog/stock records are referenced by retained procurement.', $count, [OrganizationDataResetScope::SuppliersAndPurchasing->value]);
            }
        }
        if ($has(OrganizationDataResetScope::Customers) && ! $has(OrganizationDataResetScope::SalesAndDocuments)) {
            $count = $this->countWhereNotNull($organizationId, 'sales_orders', 'customer_id')
                + $this->countWhereNotNull($organizationId, 'quotations', 'customer_id')
                + $this->countWhereNotNull($organizationId, 'invoices', 'customer_id')
                + $this->countWhereNotNull($organizationId, 'out_of_stock_articles', 'customer_id');
            if ($count > 0) {
                $blockers[] = new OrganizationDataResetBlocker('customers.sales_dependencies', 'Customers are referenced by retained sales/documents.', $count, [OrganizationDataResetScope::SalesAndDocuments->value]);
            }
        }
        if ($has(OrganizationDataResetScope::SuppliersAndPurchasing) && ! $has(OrganizationDataResetScope::TransactionalFinance)) {
            $count = $this->countWhereNotNull($organizationId, 'finance_supplier_installment_plans', 'supplier_id')
                + $this->countWhereNotNull($organizationId, 'finance_obligations', 'supplier_id')
                + $this->countWhereNotNull($organizationId, 'finance_outgoing_payments', 'supplier_id')
                + $this->countWhereNotNull($organizationId, 'finance_payment_instruments', 'supplier_id');
            if ($count > 0) {
                $blockers[] = new OrganizationDataResetBlocker('suppliers.finance_dependencies', 'Suppliers are referenced by retained Finance records.', $count, [OrganizationDataResetScope::TransactionalFinance->value]);
            }
        }
        if ($has(OrganizationDataResetScope::SuppliersAndPurchasing) && ! $has(OrganizationDataResetScope::SalesAndDocuments)) {
            $count = $this->count($organizationId, 'sales_order_procurements');
            if ($count > 0) {
                $blockers[] = new OrganizationDataResetBlocker('suppliers.sales_dependencies', 'Procurement is linked to retained sales orders.', $count, [OrganizationDataResetScope::SalesAndDocuments->value]);
            }
        }
        if ($has(OrganizationDataResetScope::SuppliersAndPurchasing) && ! $has(OrganizationDataResetScope::CatalogAndStock)) {
            $count = $this->countWhereNotNull($organizationId, 'sales_order_procurements', 'received_at');
            if ($count > 0) {
                $blockers[] = new OrganizationDataResetBlocker('suppliers.inventory_dependencies', 'Received procurement has retained inventory-ledger effects.', $count, [OrganizationDataResetScope::CatalogAndStock->value]);
            }
        }
        if ($has(OrganizationDataResetScope::SuppliersAndPurchasing)
            && $requested !== OrganizationDataResetScope::FullOrganization) {
            $count = $this->countWhereNotNull($organizationId, 'finance_recurring_obligation_templates', 'supplier_id');
            if ($count > 0) {
                $blockers[] = new OrganizationDataResetBlocker('suppliers.recurring_configuration', 'Suppliers are referenced by preserved recurring Finance configuration.', $count);
            }
        }
        if ($has(OrganizationDataResetScope::UsersAndAccess)) {
            $count = DB::table('organization_memberships as memberships')
                ->join('roles', function ($join) {
                    $join->on('roles.id', '=', 'memberships.role_id')
                        ->on('roles.organization_id', '=', 'memberships.organization_id');
                })
                ->where('memberships.organization_id', $organizationId)
                ->where('memberships.user_id', $organization->owner_id)
                ->where('roles.is_system', false)
                ->count();
            if ($count > 0) {
                $blockers[] = new OrganizationDataResetBlocker('access.owner_custom_role', 'The primary Owner is assigned to a custom role that the reset would remove.', $count);
            }
        }

        return $blockers;
    }

    /** @param list<string> $tables */
    private function sum(int $organizationId, array $tables): int
    {
        return array_sum(array_map(fn (string $table) => $this->count($organizationId, $table), $tables));
    }

    private function count(int $organizationId, string $table): int
    {
        return Schema::hasTable($table)
            ? DB::table($table)->where('organization_id', $organizationId)->count()
            : 0;
    }

    private function countWhereNotNull(int $organizationId, string $table, string $column): int
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column)
            ? DB::table($table)->where('organization_id', $organizationId)->whereNotNull($column)->count()
            : 0;
    }
}
