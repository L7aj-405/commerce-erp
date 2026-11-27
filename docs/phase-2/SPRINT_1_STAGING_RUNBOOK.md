# Phase 2 Sprint 1 — Supplier Invoices: staging release runbook

Scope: releasing PR #51 (`integration/p2s1`) to **staging only**. The feature ships **disabled**
(`SUPPLIER_INVOICES_ENABLED` defaults to `false`). No production deployment is covered here.

Facts this runbook does **not** know: how staging is deployed and who operates it (the repository contains no
deployment procedure — `docs/ops/ci-and-branch-protection.md` states CI has no deployment or staging credentials).
Every step marked **[operator]** must be done by whoever runs staging; confirm the exact commands with them.

## 0. Merge gate (do not skip)
- [ ] Required CI on the PR head is green (`ci-gate`: php-tests, frontend, pint-changed, npm-audit, composer-audit) **and** the
      MySQL 8.4 job and the MySQL concurrency job are green.
- [ ] Merge with a **merge commit** (not squash/rebase) so the integration history is preserved.
- [ ] Nothing in this release touches `main`. Promotion of staging to `main` is a separate, later decision
      (remember the PR #24 revert trap: a plain staging→main merge must be checked against the expected tree).

## 1. What the release changes in the database
Four additive migrations, run in filename order:

| Migration | Effect | Risk |
|---|---|---|
| `2026_11_26_000001_add_fiscal_identity_to_suppliers` | 6 nullable columns on `suppliers` | low; `down()` refuses if any value is filled |
| `2026_11_26_000002_create_supplier_invoice_tables` | 5 new tables + CHECK constraints | low (new tables); `down()` refuses if invoices exist |
| `2026_11_26_000003_link_obligations_to_supplier_invoice_installments` | **ALTER on the existing `finance_obligations`**: nullable column, composite FK, unique index, exclusivity CHECK | **medium** — see 2.3 |
| `2026_11_26_000004_provision_supplier_invoice_permissions` | adds 4 permissions, grants them to holders of `procurement.manage` / `finance.monthly_situation.manage` (view/manage) and `finance.monthly_situation.manage` only (approve/void); never revokes | low |

No data is rewritten. Existing obligations, installment plans and payments keep the new column `NULL`.

## 2. Preflight (before running migrations) **[operator]**
1. **Confirm the target.** The database name and host are staging's. Never run these steps against production.
2. **Database snapshot** (a logical per-organization backup in the app is NOT a substitute). Example for MySQL:
   `mysqldump --single-transaction --routines --triggers --set-gtid-purged=OFF -h <host> -u <user> -p <staging_db> > staging_pre_p2s1_<date>.sql`
   Verify the file is non-empty and restorable on a scratch database **before** proceeding. Record snapshot name + time.
3. **Size check for M3.** `SELECT COUNT(*) FROM finance_obligations;` (the `linked` column does not exist yet — use the plain count).
   Adding a column plus a foreign key/unique index may rebuild the table on large data sets; schedule a quiet window if the count is large.
4. **Migration status.** `php artisan migrate:status` — confirm the last applied migration is `2026_11_25_000002_*` and nothing is pending
   that is not part of this release.
5. **Store-membership preflight** (there is no Owner/Admin bypass; approve/void require membership of the invoice's store). Query A returns
   users who will hold approve/void but have no membership in any active store — fix them through the existing membership screen
   *before* the pilot (it is audited as `store_membership.created`):

```sql
SELECT u.id AS user_id, u.email, om.organization_id
FROM organization_memberships om
JOIN users u ON u.id = om.user_id
JOIN role_permission rp ON rp.role_id = om.role_id
JOIN permissions p ON p.id = rp.permission_id AND p.`key` IN ('supplier_invoices.approve','supplier_invoices.void')
WHERE om.status = 'active'
  AND NOT EXISTS (SELECT 1 FROM store_memberships sm JOIN stores s ON s.id = sm.store_id AND s.status = 'active'
                  WHERE sm.user_id = om.user_id AND sm.organization_id = om.organization_id)
GROUP BY u.id, u.email, om.organization_id;
```
   (Run it **after** migration 4, when the permissions exist. Owner roles that hold all permissions through a wildcard may not appear in
   `role_permission`; check owners manually.)
6. **Baseline reconciliation.** `php artisan finance:reconcile` before migrating. Save the output: it must be the same shape/result after.
7. Confirm `SUPPLIER_INVOICES_ENABLED` is **not set** (or `false`) in staging's environment.

## 3. Deploy and migrate **[operator]**
1. Deploy the merge commit using staging's normal procedure.
2. `php artisan migrate --force` (watch for any error; do not re-run partially).
3. Clear/rebuild caches per staging's normal procedure (config cache must pick up `config/supplier_invoices.php`).

## 4. Post-deploy smoke (feature still OFF)
- [ ] `php artisan migrate:status` — the four `2026_11_26_*` migrations are `Ran`.
- [ ] Tables exist: `SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'supplier_invoice%';` (5 rows).
- [ ] Permissions exist: ``SELECT `key` FROM permissions WHERE `key` LIKE 'supplier_invoices.%';`` (4 rows).
- [ ] M3 applied and nothing linked: `SELECT COUNT(*) total, SUM(supplier_invoice_installment_id IS NOT NULL) linked FROM finance_obligations;` → `linked` is `0`/NULL.
- [ ] `php artisan finance:reconcile` — R-1…R-10 all pass (R-8…R-10 pass trivially with no invoices); compare with the baseline.
- [ ] With the flag OFF: `GET /finance/factures-fournisseurs` returns **404**, and the **navigation shows no "Factures fournisseurs" entry** for any user.
- [ ] Existing flows work: open Situation fin de mois, create/edit an ordinary obligation, record and reverse an outgoing payment on a **test** obligation
      in a test-fiscal-mode organization only.
- [ ] Supplier screens: the new fiscal-information section appears; existing suppliers still save.

## 5. Enabling for the pilot (only after explicit approval)
1. Use an organization declared in **test fiscal mode** (approved invoices in a `protected` organization cannot be wiped by a reset).
2. Set `SUPPLIER_INVOICES_ENABLED=true` for staging, refresh config, run the UAT checklist (`SPRINT_1_UAT_CHECKLIST.md`).
3. Run `php artisan finance:reconcile` after each UAT block; R-8…R-10 must pass.

## 6. Rollback, least to most invasive
| Level | Action | Data impact |
|---|---|---|
| L0 | Set `SUPPLIER_INVOICES_ENABLED=false` and refresh config | none; instant. Routes 404, menu entry hidden |
| L1 | Redeploy the previous release | tables/columns stay, unused. **Caveat:** the old code does not enforce the lock on invoice-linked obligations; if approved invoices exist, void them first (or accept freezing the feature) before rolling back code |
| L2 | `php artisan migrate:rollback` of M4→M1 | **Only while no invoice data exists.** Each `down()` refuses loudly if data exists (M3: linked obligations; M2: invoices; M1: filled supplier fiscal fields). M3's exclusivity CHECK on SQLite is a trigger |
| L3 | Restore the pre-release snapshot | full data loss since the snapshot — last resort |

Prefer a forward fix over `down()` once any real invoice exists. Never delete invoices by SQL: void them through the application
(history, audit and payment allocations are preserved by design).

## 6b. Known limitations to keep in mind during the pilot
- Supplier **credit notes are not implemented**; the void dialog states that avoirs/returns/rebates are not void reasons. Anything needing a
  credit note must be handled outside the application and reviewed by the accountant.
- Attachment **files** are not part of the application's organization backup/restore (only the rows are).
- Rounding level, VAT deduction timing, withholding tax, retention and mandatory-ICE rules are **not validated** by an accountant. The feature
  makes no tax-compliance claim; input-VAT reporting is not part of this release.

## 7. Evidence to attach to the release record
Snapshot name/time, `migrate:status` output, reconcile before/after, the smoke checklist with initials/date, CI run URLs, the merge commit SHA.
