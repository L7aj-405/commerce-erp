<?php

namespace App\Services\OrganizationDataReset;

class OrganizationDataResetBundleRegistry
{
    /** @return list<OrganizationDataResetOperation> */
    public function operationsFor(OrganizationDataResetScope $requestedScope): array
    {
        $operations = [];
        foreach ($requestedScope->expanded() as $scope) {
            foreach ($this->bundle($scope) as $operation) {
                if ($requestedScope === OrganizationDataResetScope::FullOrganization
                    && str_starts_with($operation->action, 'delete_sales_')) {
                    // The Full reset includes Catalog & Stock, whose broader
                    // operations already remove these rows. Avoid overlapping
                    // selective operations and affected-count drift.
                    continue;
                }
                $identity = implode('|', [$operation->table, $operation->action, $operation->column ?? '']);
                $operations[$identity] ??= $operation;
            }
        }

        if ($requestedScope === OrganizationDataResetScope::FullOrganization) {
            $operation = new OrganizationDataResetOperation(
                'full_organization.unlink_recurring_template_supplier',
                OrganizationDataResetScope::FullOrganization,
                'finance_recurring_obligation_templates',
                OrganizationDataResetOperation::SET_NULL,
                'supplier_id',
            );
            $operations[implode('|', [$operation->table, $operation->action, $operation->column])] = $operation;
        }

        return array_values($operations);
    }

    /** @return list<OrganizationDataResetOperation> */
    private function bundle(OrganizationDataResetScope $scope): array
    {
        return match ($scope) {
            OrganizationDataResetScope::SalesAndDocuments => array_merge($this->tables($scope, [
                'finance_expected_collection_matches',
                'document_stamp_appositions',
                'payment_refunds', 'payment_allocations',
                'credit_note_lines', 'credit_notes',
                'customer_exchange_payments', 'customer_exchanges',
                'customer_return_lines', 'customer_returns',
                'finance_expected_collections', 'payments', 'commission_entries',
                'delivery_note_lines', 'delivery_notes',
                'invoice_lines', 'invoices', 'invoice_families',
                'quotation_lines', 'quotations',
                'out_of_stock_articles',
                'sales_order_procurements',
                'sales_order_revisions', 'sales_order_addenda',
                'sales_order_lines', 'sales_orders',
            ], [
                ['invoices.unlink_correction', 'invoices', 'corrected_invoice_id'],
                ['quotations.unlink_revision_root', 'quotations', 'root_quotation_id'],
                ['quotations.unlink_revision_parent', 'quotations', 'revised_from_quotation_id'],
                ['sales_orders.unlink_current_revision', 'sales_orders', 'current_revision_id'],
                // Follow-ups are work items, not sales documents: they survive
                // the reset and only lose the quotation link.
                ['follow_ups.unlink_quotation', 'follow_ups', 'quotation_id'],
                // A quotation-linked recurring rule that loses its quotation pauses at its next generation.
                ['follow_up_rules.unlink_quotation', 'follow_up_recurring_rules', 'quotation_id'],
            ]), [
                // Attention about deleted quotations is meaningless history: removed with them.
                new OrganizationDataResetOperation('attention.quotation_items', $scope, 'attention_items', OrganizationDataResetOperation::DELETE_QUOTATION_ATTENTION),
                new OrganizationDataResetOperation('sales_and_documents.woocommerce_stock_tasks', $scope, 'woocommerce_stock_tasks', OrganizationDataResetOperation::DELETE_SALES_WOOCOMMERCE_STOCK_TASKS),
                new OrganizationDataResetOperation('sales_and_documents.stock_transfer_lines', $scope, 'stock_transfer_lines', OrganizationDataResetOperation::DELETE_SALES_STOCK_TRANSFER_LINES),
                new OrganizationDataResetOperation('sales_and_documents.transfer_request_lines', $scope, 'transfer_request_lines', OrganizationDataResetOperation::DELETE_SALES_TRANSFER_REQUEST_LINES),
                new OrganizationDataResetOperation('sales_and_documents.inventory_movements', $scope, 'inventory_movements', OrganizationDataResetOperation::DELETE_SALES_INVENTORY_MOVEMENTS),
                new OrganizationDataResetOperation('sales_and_documents.inventory_reservations', $scope, 'inventory_reservations', OrganizationDataResetOperation::DELETE_SALES_RESERVATION_GRAPH),
                new OrganizationDataResetOperation('sales_and_documents.transfer_graph', $scope, 'transfer_requests', OrganizationDataResetOperation::DELETE_SALES_TRANSFER_GRAPH),
            ]),
            OrganizationDataResetScope::TransactionalFinance => $this->tables($scope, [
                'finance_expected_collection_matches',
                'customer_exchange_payments',
                'payment_refunds', 'payment_allocations',
                'finance_expected_collections',
                'finance_payment_instruments',
                'finance_outgoing_payment_allocations',
                'payments', 'finance_outgoing_payments',
                'finance_obligations', 'finance_supplier_installment_plans',
                'financial_account_opening_positions',
                'finance_month_reviews', 'commission_entries',
            ]),
            OrganizationDataResetScope::CatalogAndStock => $this->tables($scope, [
                'woocommerce_stock_tasks', 'woocommerce_category_mappings',
                'product_channel_identifiers', 'woocommerce_sync_runs',
                'product_import_rows', 'product_imports',
                'product_cost_import_rows', 'product_cost_imports',
                'transfer_request_lines', 'transfer_requests',
                'stock_transfer_lines',
                'out_of_stock_articles',
                'sales_order_inventory_allocations',
                'inventory_reservations', 'inventory_movements',
                'stock_transfers', 'inventory_balances',
                'warehouse_replenishment_overrides', 'warehouse_replenishment_settings',
                'non_stock_items', 'product_variants', 'products',
                'categories', 'brands',
            ], [
                ['categories.unlink_parent', 'categories', 'parent_id'],
            ]),
            OrganizationDataResetScope::Customers => [
                new OrganizationDataResetOperation('contacts.unlink_customer', $scope, 'organization_contacts', OrganizationDataResetOperation::SET_NULL, 'customer_id'),
                new OrganizationDataResetOperation('follow_ups.unlink_customer', $scope, 'follow_ups', OrganizationDataResetOperation::SET_NULL, 'customer_id'),
                new OrganizationDataResetOperation('follow_up_rules.unlink_customer', $scope, 'follow_up_recurring_rules', OrganizationDataResetOperation::SET_NULL, 'customer_id'),
                new OrganizationDataResetOperation('attention.unlink_customer', $scope, 'attention_items', OrganizationDataResetOperation::SET_NULL, 'customer_id'),
                new OrganizationDataResetOperation('customers.delete', $scope, 'customers'),
            ],
            OrganizationDataResetScope::SuppliersAndPurchasing => array_merge(
                $this->tables($scope, [
                    'sales_order_procurements',
                ]),
                [
                    new OrganizationDataResetOperation('contacts.unlink_supplier', $scope, 'organization_contacts', OrganizationDataResetOperation::SET_NULL, 'supplier_id'),
                    new OrganizationDataResetOperation('suppliers.delete', $scope, 'suppliers'),
                ],
            ),
            OrganizationDataResetScope::UsersAndAccess => [
                new OrganizationDataResetOperation('access.active_context', $scope, 'users', OrganizationDataResetOperation::CLEAR_NON_OWNER_ACTIVE_CONTEXT),
                // Open work of removed members becomes "Unassigned" (never deleted);
                // completed/cancelled history keeps its assignee.
                new OrganizationDataResetOperation('follow_ups.unassign_removed_members', $scope, 'follow_ups', OrganizationDataResetOperation::UNASSIGN_NON_OWNER_FOLLOW_UPS),
                // Their recurring rules become Unassigned and stop generating (paused) until a manager acts.
                new OrganizationDataResetOperation('follow_up_rules.unassign_removed_members', $scope, 'follow_up_recurring_rules', OrganizationDataResetOperation::UNASSIGN_NON_OWNER_RECURRING_RULES),
                // Active attention owned by removed members becomes unassigned; the next scan re-applies the fallback.
                new OrganizationDataResetOperation('attention.unassign_removed_members', $scope, 'attention_items', OrganizationDataResetOperation::UNASSIGN_NON_OWNER_ATTENTION),
                new OrganizationDataResetOperation('access.store_memberships', $scope, 'store_memberships', OrganizationDataResetOperation::DELETE_NON_OWNER_STORE_MEMBERSHIPS),
                new OrganizationDataResetOperation('access.invitations', $scope, 'user_invitations'),
                new OrganizationDataResetOperation('access.memberships', $scope, 'organization_memberships', OrganizationDataResetOperation::DELETE_NON_OWNER_MEMBERSHIPS),
                new OrganizationDataResetOperation('access.custom_role_permissions', $scope, 'role_permission', OrganizationDataResetOperation::DELETE_CUSTOM_ROLE_PERMISSIONS),
                new OrganizationDataResetOperation('access.custom_roles', $scope, 'roles', OrganizationDataResetOperation::DELETE_CUSTOM_ROLES),
            ],
            OrganizationDataResetScope::FullOrganization => [],
        };
    }

    /**
     * @param list<string> $tables
     * @param list<array{0:string,1:string,2:string}> $preUpdates
     * @return list<OrganizationDataResetOperation>
     */
    private function tables(OrganizationDataResetScope $scope, array $tables, array $preUpdates = []): array
    {
        return array_merge(array_map(
            fn (array $definition) => new OrganizationDataResetOperation(
                $definition[0], $scope, $definition[1], OrganizationDataResetOperation::SET_NULL, $definition[2],
            ),
            $preUpdates,
        ), array_map(
            fn (string $table) => new OrganizationDataResetOperation($scope->value.'.'.$table, $scope, $table),
            $tables,
        ));
    }
}
