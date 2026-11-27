# Migration inventory — production-consolidation-01

15 new migrations on top of main `5285c6c` (132 → 147). No existing migration file is modified.
**Lock** = expected MySQL 8.4 behaviour (from the DDL type, not measured on production volumes).
Run the whole set inside the maintenance window with web writes, worker and scheduler stopped.

| # | Migration (`database/migrations/`) | Schema change | Data written to existing rows / backfill | Lock | If it fails part-way |
|---|---|---|---|---|---|
| 1 | `2026_11_23_000001_provision_payments_refund_permission` | – | Adds `payments.refund`; grants it to every role holding `payments.reverse` | rows | Idempotent, re-run |
| 2 | `2026_11_23_000002_repair_payments_refund_catalog` | – | Repairs the catalogue row only if missing | rows | Idempotent |
| 3 | `2026_11_24_000001_add_financial_check_constraints` | 12 `CHECK`s (payments, allocations, refunds, invoices, credit notes, stock balances + sandbox twins) | None. Scans first; refuses if any row violates | **Table copy, blocks writes** | Not idempotent: constraints already added stay and a re-run fails (MySQL 3822). Restore, or drop the added `chk_*` and re-run |
| 4 | `2026_11_25_000001_add_typed_policy_columns_to_organizations` | 4 columns on `organizations` | Column defaults only (today's behaviour) | instant | Drop added columns / restore |
| 5 | `2026_11_25_000002_provision_policy_permissions` | – | `settings.billing.manage`, `customers.manage_credit` to holders of `settings.update` | rows | Idempotent |
| 6 | `2026_11_26_000001_add_fiscal_identity_to_suppliers` | 6 nullable columns on `suppliers` | None | instant | Restore / drop columns |
| 7 | `2026_11_26_000002_create_supplier_invoice_tables` | 5 new tables, 6 `CHECK`s | None | metadata (new tables) | Drop the new tables / restore |
| 8 | `2026_11_26_000003_link_obligations_to_supplier_invoice_installments` | Column, unique key, FK, `CHECK` on `finance_obligations` | None (no backfill) | **Table copy** | Restore |
| 9 | `2026_11_26_000004_provision_supplier_invoice_permissions` | – | view/manage → holders of `procurement.manage` or `finance.monthly_situation.manage`; approve/void → holders of the latter | rows | Idempotent |
| 10 | `2026_11_28_000001_create_customer_finance_tables` | 7 new tables; `payments.customer_id` + FK + index | Re-syncs default roles (see below) | **`payments` copy** | Restore |
| 11 | `2026_11_29_000001_add_customer_allocation_effective_dates` | `payment_allocations.effective_date`, `payments.request_fingerprint`, 2 new tables, 2 indexes | None (`NULL` = legacy meaning) | instant + online index | Restore |
| 12 | `2026_12_01_000001_add_invoicing_policy_to_customer_financial_terms` | 1 nullable column | None, but **changes invoice eligibility** (below) | instant | Restore |
| 13 | `2026_12_02_000001_create_unified_contacts_party_tables` | 6 new tables; `party_id` on customers / suppliers / contacts, `payment_term_id` on suppliers and terms; FKs | Inserts 8 system contact roles | **Table copy** (customers, suppliers, contacts) | Restore |
| 14 | `2026_12_02_000002_backfill_parties_and_provision_contact_permissions` | – | **Backfill** (below); re-syncs default roles; 2 contact permissions | one transaction **per organization** | Idempotent: re-run resumes. Finished organizations stay migrated |
| 15 | `2026_12_03_000001_add_archive_snapshot_to_parties` | 1 nullable column | None | instant | Re-run |

MySQL DDL auto-commits. A migration that fails after its first statement is **not** recorded but leaves objects
behind. The default recovery is to restore the pre-migration dump (RUNBOOK §6), not to hand-edit the schema.
Do **not** use `migrate:rollback` in production: several `down()` methods refuse once data exists, others drop tables.

## Permission provisioning

- Equivalence grants (1, 5, 9) reach custom roles that hold the source permission. Nothing is revoked.
- Migrations 10 and 14 call `provisionAllOrganizations()` (the pattern main already uses ~25 times): every role
  whose **slug** is `owner`, `admin` or `sales-employee` receives all current config permissions of that slug.
  System roles cannot be edited in the application, so this only matters if a custom role reuses such a slug or
  rows were changed directly in SQL — preflight **P05a / P05b** detect both.
- `sales-employee` gains `supplier_invoices.view` and `.manage` (it holds `procurement.manage`); the feature is
  hidden while `SUPPLIER_INVOICES_ENABLED=false`.

## Unified Contacts backfill

- Only the new `party_id` (and `payment_term_id`) columns are written on legacy rows; ids and other columns are untouched.
- One party per customer and supplier. `suppliers.ice` is brand new, hence empty: **no customer/supplier pair is merged**.
- A contact is merged into its account only when it is linked to exactly one account, has no job title and bears
  exactly that account's name. Everything else stays separate and is listed in `party_migration_conflicts`.
- Legacy column widths fit the `parties` columns (checked for every copied field).
- Cost: the duplicate check reads the organization's company parties once per supplier. Time it on the clone.
- Rows created by **old** code afterwards (code-only rollback) have no party; re-running the service links them (rehearsed).

## Invoice eligibility change (needs explicit business acceptance)

Before: a Production invoice could be issued only once the order was fully paid.
After: `customer_financial_terms.invoicing_policy = NULL` resolves by customer type — **every existing Company
customer may be invoiced before payment, immediately**; individuals and orders without a customer are unchanged.
Existing draft invoices of company customers become issuable. No invoice is created or changed by the migration.
An authorized user can pin "after full payment" per customer afterwards (there is no bulk switch).
Preflight **P07a–c** quantifies the exposure.

## Compatibility with existing data

| Risk | Preflight |
|---|---|
| Row violating a new `CHECK` stops the release at migration 3 (migrations 1–2 stay applied) | P04 |
| MySQL older than 8.0.19 / MariaDB (untested) | P01 |
| Database not exactly at main, or leftovers of a partial run | P02, P03 |
| Missing composite unique keys needed by new foreign keys | P03d |
| Unexpected role permissions | P05 |
| Cross-organization contact links, blank names | P06 |
| Jobs queued by the old code | P08 |
