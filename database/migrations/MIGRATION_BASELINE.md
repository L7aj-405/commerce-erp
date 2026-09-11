# Migration baseline

The `2026_08_23_0000XX_*` migrations are a **consolidated baseline**. They were
rebuilt on 2026-09-09 from ~30 incremental `create → alter → alter` migrations
into one create-in-final-form migration per domain, ordered by real table
dependency.

Execution order:

| # | Migration | Contents |
|---|-----------|----------|
| — | `0001_01_01_000000_create_users_table` | Framework: `users`, `password_reset_tokens`, `sessions` |
| — | `0001_01_01_000001_create_cache_table` | Framework: `cache`, `cache_locks` |
| — | `0001_01_01_000002_create_jobs_table` | Framework: `jobs`, `job_batches`, `failed_jobs` |
| 1 | `2026_08_23_000001_create_platform_core_tables` | `permissions`, `organizations`, `roles`, `stores` (+ `default_tax_rate_id` column), `organization_memberships`, `role_permission`, `store_memberships`, `audit_logs`; then alters `users` with `active_organization_id` / `active_store_id` (resolves the users ⇄ organizations cycle) |
| 2 | `2026_08_23_000002_create_catalog_tables` | `brands`, `categories`, `units_of_measure`, `tax_rates`, `products`, `product_variants`, `product_channel_identifiers`; then attaches `stores_default_tax_rate_fk` (needs `tax_rates`) |
| 3 | `2026_08_23_000003_create_inventory_tables` | `warehouses`, `inventory_balances`, `inventory_movements`, `inventory_reservations` |
| 4 | `2026_08_23_000004_create_sales_tables` | `customers`, `sales_order_sequences`, `sales_orders` (incl. all POS columns), `sales_order_lines`, `sales_order_inventory_allocations` |
| 5 | `2026_08_23_000005_create_payment_tables` | `financial_accounts`, `payment_sequences`, `payments`, `payment_allocations` |
| 6 | `2026_08_23_000006_create_document_tables` | `invoice_sequences`, `delivery_note_sequences`, `invoices`, `invoice_lines`, `delivery_notes`, `delivery_note_lines` |
| 7 | `2026_08_23_000007_create_product_import_tables` | `product_imports`, `product_import_rows` |
| 8 | `2026_08_23_000008_create_stock_transfer_tables` | `stock_transfer_sequences`, `stock_transfers`, `stock_transfer_lines` |
| 9 | `2026_08_23_000009_create_woocommerce_integration_tables` | `woocommerce_integrations`, `woocommerce_sync_runs`, `woocommerce_category_mappings` |
| 10 | `2026_08_23_000010_provision_permissions` | Data migration: seeds the permission catalogue + default-role assignments |

## ⚠️ Once this schema is in production

This baseline rewrite was only safe because the schema was a **disposable
development schema with no persistent customer data**, and `php artisan
migrate:fresh` is an accepted workflow here.

**As soon as this database reaches production or holds real, persistent data,
every future schema change MUST be a NEW migration file appended after
`2026_08_23_000010`. Do not edit or re-consolidate the baseline again.**
