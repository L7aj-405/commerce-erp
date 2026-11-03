<?php

namespace App\Services\OrganizationDataReset\Selective;

/**
 * The operational data an owner may clean selectively, mapped to the tables
 * that actually exist. Master data (customers, suppliers, contacts,
 * catalogue, warehouses, stores, users, roles, settings, numbering,
 * integrations, audit) is never a category: it can only be referenced.
 *
 *  - `roots`: the documents the user selects (by category, record or date).
 *    `date` is the BUSINESS date of the document (an SQL expression), never
 *    blindly `created_at` — except where the document has no other date.
 *  - `owns`: rows that only exist as part of a root (lines, revisions,
 *    shipments, allocations…). They go with their root; nothing else does
 *    implicitly. Any other row that references a deleted row is either
 *    unlinked (optional link) or blocks the plan (see the planner).
 */
class SelectiveResetCategoryRegistry
{
    /**
     * @return array<string, array{module:string, roots:list<array{table:string,number:string,date:string,status:?string,party:?array{0:string,1:string,2:string},warehouses:list<string>}>, owns:list<string>}>
     */
    public function all(): array
    {
        return [
            'sales_orders' => [
                'module' => 'sales',
                'roots' => [$this->root('sales_orders', 'order_number', 'sale_date', 'status', ['customers', 'customer_id', 'display_name'], ['pos_warehouse_id'])],
                'owns' => [
                    'sales_order_lines', 'sales_order_revisions', 'sales_order_addenda', 'sales_order_inventory_allocations',
                    'fulfillments', 'fulfillment_items', 'fulfillment_attachments', 'fulfillment_customer_notifications',
                    'commission_entries', 'out_of_stock_articles',
                    'finance_expected_collections', 'finance_expected_collection_matches',
                    'quotation_supplier_availability_requests',
                ],
            ],
            'quotations' => [
                'module' => 'sales',
                'roots' => [$this->root('quotations', 'quotation_number', 'quotation_date', 'status', ['customers', 'customer_id', 'display_name'])],
                'owns' => ['quotation_lines', 'quotation_supplier_availability_requests'],
            ],
            'customer_returns' => [
                'module' => 'sales',
                'roots' => [
                    $this->root('customer_returns', 'return_number', 'COALESCE(received_at, created_at)', 'status', null, ['warehouse_id']),
                    $this->root('customer_exchanges', 'exchange_number', 'created_at', 'status'),
                ],
                'owns' => ['customer_return_lines', 'customer_exchange_payments'],
            ],
            'invoices' => [
                'module' => 'documents',
                'roots' => [
                    $this->root('invoices', 'invoice_number', 'invoice_date', 'status', ['customers', 'customer_id', 'display_name']),
                    $this->root('credit_notes', 'credit_note_number', 'credit_note_date', 'status'),
                ],
                'owns' => ['invoice_lines', 'credit_note_lines'],
            ],
            'delivery_notes' => [
                'module' => 'documents',
                'roots' => [$this->root('delivery_notes', 'delivery_note_number', 'delivery_date', 'status')],
                'owns' => ['delivery_note_lines'],
            ],
            'payments' => [
                'module' => 'finance',
                'roots' => [
                    $this->root('payments', 'payment_number', 'payment_date', 'status'),
                    $this->root('payment_refunds', 'refund_number', 'refund_date', 'status'),
                ],
                'owns' => ['payment_allocations', 'finance_expected_collection_matches', 'customer_exchange_payments'],
            ],
            'purchase_orders' => [
                'module' => 'purchasing',
                'roots' => [$this->root('purchase_orders', 'purchase_order_number', 'order_date', 'status', ['suppliers', 'supplier_id', 'name'])],
                'owns' => ['purchase_order_lines'],
            ],
            'supplier_procurements' => [
                'module' => 'purchasing',
                'roots' => [$this->root('sales_order_procurements', 'procurement_number', 'COALESCE(ordered_at, created_at)', 'status', ['suppliers', 'supplier_id', 'name'], ['receiving_warehouse_id'])],
                'owns' => ['procurement_receipts'],
            ],
            'transfers' => [
                'module' => 'logistics',
                'roots' => [
                    $this->root('transfer_requests', 'request_number', 'requested_at', 'status', null, ['source_warehouse_id', 'destination_warehouse_id']),
                    $this->root('stock_transfers', 'transfer_number', 'transferred_at', 'status', null, ['source_warehouse_id', 'destination_warehouse_id']),
                ],
                'owns' => ['transfer_request_lines', 'stock_transfer_lines'],
            ],
            'product_imports' => [
                'module' => 'history',
                'roots' => [
                    $this->root('product_imports', 'original_file_name', 'created_at', 'status'),
                    $this->root('product_cost_imports', 'original_file_name', 'created_at', 'status'),
                ],
                'owns' => ['product_import_rows', 'product_cost_import_rows'],
            ],
            'integration_sync' => [
                'module' => 'history',
                'roots' => [
                    $this->root('woocommerce_sync_runs', 'type', 'COALESCE(started_at, created_at)', 'status'),
                    $this->root('woocommerce_stock_tasks', 'source_reference', 'created_at', 'status', null, ['warehouse_id']),
                ],
                'owns' => [],
            ],
            'follow_ups' => [
                'module' => 'activity',
                'roots' => [$this->root('follow_ups', 'title', 'due_date', 'status', ['customers', 'customer_id', 'display_name'])],
                'owns' => [],
            ],
        ];
    }

    /**
     * Never deletable here, shown as "kept" and guarded by the executor:
     * they keep the ERP usable for a fresh operational start. Stock levels
     * and the stock ledger are part of it — selective cleanup never changes
     * on-hand quantities (only active reservations of deleted orders are
     * released).
     *
     * @return list<string>
     */
    public function protectedTables(): array
    {
        return [
            'organizations', 'organization_memberships', 'store_memberships', 'users', 'roles', 'role_permission',
            'stores', 'warehouses', 'customers', 'suppliers', 'organization_contacts',
            'products', 'product_variants', 'product_suppliers', 'product_variant_suppliers', 'categories', 'brands', 'non_stock_items',
            'financial_accounts', 'tax_rates', 'woocommerce_integrations', 'follow_up_recurring_rules', 'follow_up_templates',
            'inventory_balances', 'inventory_movements', 'invoice_families',
        ];
    }

    /** @return array{module:string, roots:list<array<string,mixed>>, owns:list<string>}|null */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, array<string,mixed>> root table => root definition (+ category) */
    public function roots(): array
    {
        $roots = [];
        foreach ($this->all() as $key => $category) {
            foreach ($category['roots'] as $root) {
                $roots[$root['table']] = $root + ['category' => $key];
            }
        }

        return $roots;
    }

    /** Categories a table belongs to (as root or owned row). @return list<string> */
    public function categoriesOf(string $table): array
    {
        $out = [];
        foreach ($this->all() as $key => $category) {
            if (in_array($table, $category['owns'], true) || in_array($table, array_column($category['roots'], 'table'), true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** @param list<string> $warehouses */
    private function root(string $table, string $number, string $date, ?string $status, ?array $party = null, array $warehouses = []): array
    {
        return ['table' => $table, 'number' => $number, 'date' => $date, 'status' => $status, 'party' => $party, 'warehouses' => $warehouses];
    }
}
