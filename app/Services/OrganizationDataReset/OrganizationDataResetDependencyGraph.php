<?php

namespace App\Services\OrganizationDataReset;

class OrganizationDataResetDependencyGraph
{
    public function __construct(private readonly OrganizationDataResetForeignKeyMap $foreignKeys) {}

    /**
     * Preferred (tie-break) order. The binding order is derived from the REAL
     * foreign keys ({@see order()}): this list alone once deleted
     * quotation_supplier_availability_requests before the procurements that
     * reference it (proc_src_qsar_fk) and the reset failed at execution while
     * the preview reported no blocker.
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
        'fulfillment_customer_notifications', 'fulfillment_attachments', 'fulfillment_items', 'fulfillments',
        'sales_order_lines', 'sales_order_revisions', 'sales_order_addenda', 'sales_orders',
        'product_imports', 'product_cost_imports',
        'warehouse_replenishment_overrides', 'warehouse_replenishment_settings',
        'non_stock_items', 'product_variants', 'products', 'categories', 'brands',
        'organization_contacts', 'customers', 'suppliers',
        'users', 'store_memberships', 'user_invitations', 'organization_memberships', 'role_permission', 'roles',
    ];

    /**
     * Unlinking updates first, then every other operation in an order where a
     * table is always emptied before any table it references (child before
     * parent), from the database's own foreign keys. Ties keep the preferred
     * order above, so the result is deterministic.
     *
     * @param list<OrganizationDataResetOperation> $operations
     * @return list<OrganizationDataResetOperation>
     */
    public function order(array $operations): array
    {
        $rank = array_flip(self::ORDER);
        $preferred = function (OrganizationDataResetOperation $left, OrganizationDataResetOperation $right) use ($rank): int {
            $tableOrder = ($rank[$left->table] ?? PHP_INT_MAX) <=> ($rank[$right->table] ?? PHP_INT_MAX);

            return $tableOrder !== 0 ? $tableOrder : $left->key <=> $right->key;
        };

        $updates = array_values(array_filter($operations, fn ($op) => $op->action === OrganizationDataResetOperation::SET_NULL));
        $others = array_values(array_filter($operations, fn ($op) => $op->action !== OrganizationDataResetOperation::SET_NULL));
        usort($updates, $preferred);
        usort($others, $preferred);
        // Links the plan clears before deleting anything never constrain the order.
        $cleared = array_fill_keys(array_map(fn ($op) => $op->table.'.'.$op->column, $updates), true);

        // must[i] = indexes of operations that have to run before operation i.
        $must = array_fill(0, count($others), []);
        foreach ($others as $i => $parentOp) {
            foreach ($others as $j => $childOp) {
                if ($i === $j) {
                    continue;
                }
                if ($this->references(self::tablesDeletedBy($childOp), self::tablesDeletedBy($parentOp), $cleared)) {
                    $must[$i][$j] = true;
                }
            }
        }

        $ordered = [];
        $done = [];
        while (count($ordered) < count($others)) {
            $progress = false;
            foreach ($others as $i => $op) {
                if (isset($done[$i]) || array_diff_key($must[$i], $done) !== []) {
                    continue;
                }
                $ordered[] = $op;
                $done[$i] = true;
                $progress = true;
                break; // restart from the preferred order each time
            }
            if (! $progress) {
                throw new OrganizationDataResetException('The reset operations form a foreign-key cycle and cannot be ordered safely.');
            }
        }

        return array_merge($updates, $ordered);
    }

    /** Tables whose rows an operation removes (graph operations touch two). @return list<string> */
    public static function tablesDeletedBy(OrganizationDataResetOperation $operation): array
    {
        return match ($operation->action) {
            OrganizationDataResetOperation::SET_NULL,
            OrganizationDataResetOperation::CLEAR_NON_OWNER_ACTIVE_CONTEXT,
            OrganizationDataResetOperation::UNASSIGN_NON_OWNER_FOLLOW_UPS,
            OrganizationDataResetOperation::UNASSIGN_NON_OWNER_RECURRING_RULES,
            OrganizationDataResetOperation::UNASSIGN_NON_OWNER_ATTENTION => [],
            OrganizationDataResetOperation::DELETE_SALES_TRANSFER_GRAPH => ['transfer_requests', 'stock_transfers'],
            OrganizationDataResetOperation::DELETE_SALES_RESERVATION_GRAPH => ['sales_order_inventory_allocations', 'inventory_reservations'],
            default => [$operation->table],
        };
    }

    /**
     * Does any of `$children` hold a non-self foreign key to any of `$parents`
     * that would refuse (RESTRICT / NO ACTION) or cascade if the parent went
     * first? ON DELETE SET NULL edges and links cleared beforehand do not.
     *
     * @param list<string> $children
     * @param list<string> $parents
     * @param array<string,bool> $cleared
     */
    private function references(array $children, array $parents, array $cleared = []): bool
    {
        foreach ($children as $child) {
            foreach ($this->foreignKeys->referencesFrom($child) as $edge) {
                if ($edge['on_delete'] === 'set null' || isset($cleared[$child.'.'.$edge['column']])) {
                    continue;
                }
                if ($edge['parent'] !== $child && in_array($edge['parent'], $parents, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
