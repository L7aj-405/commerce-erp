<?php

namespace App\Services\OrganizationBackups;

use Illuminate\Support\Facades\Schema;

class OrganizationBackupSchema
{
    /**
     * Explicit business-data restore allowlist. Authorization, security
     * configuration, audit history, queues and backup metadata are excluded
     * deliberately and are never inferred from the database schema.
     *
     * @var list<string>
     */
    private const RESTORABLE_TABLES = [
        'organizations', 'stores',
        'brands', 'categories', 'units_of_measure', 'tax_rates', 'products', 'product_variants', 'product_channel_identifiers',
        'warehouses', 'inventory_balances', 'inventory_movements', 'inventory_reservations',
        'customers', 'sales_order_sequences', 'sales_orders', 'sales_order_lines', 'sales_order_inventory_allocations', 'sales_order_addenda', 'sales_order_revisions',
        'financial_accounts', 'payment_sequences', 'payments', 'payment_allocations', 'payment_refund_sequences', 'payment_refunds',
        'invoice_sequences', 'invoice_families', 'invoices', 'invoice_lines', 'delivery_note_sequences', 'delivery_notes', 'delivery_note_lines',
        'quotation_sequences', 'non_stock_items', 'quotations', 'quotation_lines',
        'stock_transfer_sequences', 'stock_transfers', 'stock_transfer_lines',
        'transfer_request_sequences', 'transfer_requests', 'transfer_request_lines',
        'warehouse_replenishment_settings', 'warehouse_replenishment_overrides',
        'suppliers', 'procurement_sequences', 'sales_order_procurements', 'out_of_stock_articles',
        'product_imports', 'product_import_rows',
        'woocommerce_sync_runs', 'woocommerce_category_mappings', 'woocommerce_stock_tasks',
        'customer_return_sequences', 'customer_returns', 'customer_return_lines', 'credit_note_sequences', 'credit_notes', 'credit_note_lines',
        'customer_exchange_sequences', 'customer_exchanges', 'customer_exchange_payments',
        'organization_document_stamps', 'document_stamp_appositions', 'organization_contacts',
    ];

    /** @return list<string> */
    public function tenantTables(): array
    {
        return collect(self::RESTORABLE_TABLES)
            ->filter(fn (string $table) => Schema::hasTable($table))
            ->filter(fn (string $table) => $table === 'organizations' || Schema::hasColumn($table, 'organization_id'))
            ->values()
            ->all();
    }

    public function isRestorableTable(string $table): bool
    {
        return in_array($table, self::RESTORABLE_TABLES, true) && Schema::hasTable($table);
    }

    public function isKnownTenantTable(string $table): bool
    {
        return Schema::hasTable($table)
            && ($table === 'organizations' || Schema::hasColumn($table, 'organization_id'));
    }

    /** @param list<string> $columns */
    public function hasOnlyKnownColumns(string $table, array $columns): bool
    {
        if (! $this->isKnownTenantTable($table)) {
            return false;
        }

        return array_diff($columns, $this->columns($table)) === [];
    }

    /** @return list<string> */
    public function columns(string $table): array
    {
        return Schema::getColumnListing($table);
    }

    public function hasOrganizationColumn(string $table): bool
    {
        return Schema::hasColumn($table, 'organization_id');
    }

    public function isSensitiveColumn(string $column): bool
    {
        $column = strtolower($column);

        return str_contains($column, 'password')
            || str_contains($column, 'secret')
            || str_contains($column, 'token')
            || str_contains($column, 'api_key')
            || str_contains($column, 'consumer_key')
            || str_contains($column, 'consumer_secret')
            || str_contains($column, 'access_key')
            || str_contains($column, 'private_key');
    }

}
