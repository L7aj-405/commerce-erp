<?php

namespace App\Services\OrganizationDataReset;

class OrganizationDataResetDependencyGraph
{
    /**
     * A deterministic child-before-parent order. It is intentionally explicit:
     * database cascades are not the reset algorithm.
     *
     * @var list<string>
     */
    private const ORDER = [
        'finance_expected_collection_matches',
        'document_stamp_appositions',
        'credit_note_lines', 'customer_exchange_payments', 'payment_refunds',
        'payment_allocations', 'finance_payment_instruments',
        'finance_outgoing_payment_allocations',
        'customer_exchanges', 'credit_notes', 'customer_return_lines',
        'quotation_supplier_availability_requests', 'product_suppliers', 'product_variant_suppliers',
        'delivery_note_lines', 'invoice_lines', 'quotation_lines',
        'transfer_request_lines', 'stock_transfer_lines',
        'sales_order_inventory_allocations',
        'product_import_rows', 'product_cost_import_rows',
        'product_channel_identifiers', 'woocommerce_stock_tasks', 'woocommerce_category_mappings', 'woocommerce_sync_runs',
        'inventory_movements', 'inventory_reservations', 'inventory_balances',
        'finance_expected_collections', 'payments',
        'finance_outgoing_payments', 'finance_obligations', 'finance_supplier_installment_plans',
        'financial_account_opening_positions', 'finance_month_reviews', 'commission_entries',
        'customer_returns', 'delivery_notes', 'invoices', 'invoice_families', 'quotations',
        'out_of_stock_articles', 'procurement_receipts', 'purchase_order_lines', 'purchase_orders', 'sales_order_procurements', 'transfer_requests', 'stock_transfers',
        'sales_order_lines', 'sales_order_revisions', 'sales_order_addenda', 'sales_orders',
        'product_imports', 'product_cost_imports',
        'warehouse_replenishment_overrides', 'warehouse_replenishment_settings',
        'non_stock_items', 'product_variants', 'products', 'categories', 'brands',
        'organization_contacts', 'customers', 'suppliers',
        'users', 'store_memberships', 'user_invitations', 'organization_memberships', 'role_permission', 'roles',
    ];

    /** @param list<OrganizationDataResetOperation> $operations @return list<OrganizationDataResetOperation> */
    public function order(array $operations): array
    {
        $rank = array_flip(self::ORDER);
        usort($operations, function (OrganizationDataResetOperation $left, OrganizationDataResetOperation $right) use ($rank): int {
            $leftIsPreUpdate = $left->action === OrganizationDataResetOperation::SET_NULL;
            $rightIsPreUpdate = $right->action === OrganizationDataResetOperation::SET_NULL;
            if ($leftIsPreUpdate !== $rightIsPreUpdate) {
                return $leftIsPreUpdate ? -1 : 1;
            }

            $tableOrder = ($rank[$left->table] ?? PHP_INT_MAX) <=> ($rank[$right->table] ?? PHP_INT_MAX);

            return $tableOrder !== 0 ? $tableOrder : $left->key <=> $right->key;
        });

        return $operations;
    }
}
