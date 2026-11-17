# Sprint 0 — Safe Integration & Financial Hardening (integration report)

- **Date:** 2026-11-16
- **Status of the work tree:** changes are **staged, not committed**. No push, no deploy, no production access.
- **Recommendation: READY FOR PR, with the prerequisites in §9.** It is not ready to deploy, because production state is unverified.

## 1. Branch and base

| Item | Value |
|---|---|
| Branch | `feature/sprint-0-financial-hardening` |
| Base | `origin/main` = `da8834d` (PR #21), re-verified with `git ls-remote` and `git fetch` before branching |
| Test Mode merged | Yes (`a3c6b4a` is an ancestor of `da8834d`) |
| Source of Sprint 0 | `stash@{0}` = `b67e05a` (base `6ca7924`, untracked-files parent `470c754`), 2026-11-12 |
| Stash | **Left intact.** `git stash pop`, `reset --hard` and `clean` were never run. Keep it until this PR is merged and verified |
| Working tree before branching | Only my own untracked `docs/audits/` audit report. No user changes |

The stash was recovered by a 3-way patch (`git apply --3way` of `6ca7924..stash`) plus extraction of its untracked files. The old Test Mode branch was not reused.

## 2. Recovered Sprint 0 features

| Area | Result |
|---|---|
| A. Fiscal document protection (`FiscalDocumentProtection`, selective and full reset blockers, executor postcondition) | Recovered and adapted to Test Mode (§4) |
| B. Closed financial periods (`FinanceClosedPeriodGuard`, 13 actions) | Recovered; no-op in Test Mode; reopening now has its own authorization (§4) |
| C. Business dates (`BusinessTime`, `tenant.business_today`, `useBusinessToday`, clearing value date) | Recovered unchanged. `BusinessTime` already existed on `main`; no stored date is rewritten |
| D. Receivables consistency (`FinanceReceivablesService`, `Money::payableThresholdSql`, `SalesOrderPaymentCalculator`) | Recovered unchanged. `orderExposureQuery()` already carries `sandbox_id IS NULL` |
| E. Reset planners | Recovered and merged with the Test Mode guard (§3) |

## 3. Conflicts with the current architecture

Strict apply failed on 7 files; `--3way` left 5 with markers. All were "keep both":

| File | Resolution |
|---|---|
| `OrganizationDataResetPlanner` (3 hunks) | Kept both constructor dependencies (`SandboxProductionResetGuard`, `FiscalDocumentProtection`) and both blocker sources: `[...blockers(), ...fiscalBlockers()]`, then the sandbox `sandbox.test_data_present` blocker is added in front |
| `FinanceMonthlySituationController` | Took Sprint 0's canonical `orderExposureQuery()`. This keeps the Test Mode fix, because that query filters `sales_orders.sandbox_id IS NULL` |
| `SelectiveReset.tsx`, `DataReset.tsx`, `translations.ts` (fr + ar) | Kept both blocker mappings and both message sets |

No markers remain (`grep` clean); PHP lint passes.

## 4. Sandbox integration decisions

1. **`sandbox_id` is authoritative for Test data.**
   - `FiscalDocumentProtection::protectedQuery()` now excludes rows where `sandbox_id` is set. Only Production rows are protected fiscal documents.
   - Test rows are owned and reset by `SandboxResetPlanner`/`Executor`, which this change does not touch.
2. **`fiscal_data_mode` is restricted, not removed.**
   - Why not remove: the column is additive and defaults to `protected`. Removing it would force a data decision for any organization already set to `test`.
   - **Tenant users, including the Owner, can no longer declare an organization `test`.** `SetOrganizationFiscalDataModeAction` refuses it (message points to Test Mode), and the page state returns `can_declare_test = false`.
   - Only `php artisan organization:fiscal-data-mode` (platform operators, with a reason and `--force` when documents exist) can set `test`, for legacy demonstration organizations. Going to `protected` ("go live") stays available to the Owner or a reset operator.
   - Effect: no UI path can lift protection from real data.
3. **`FinanceClosedPeriodGuard::assertOpen()` returns immediately in a Test Mode request** (`SandboxContext::isSandbox()`). Month reviews describe Production books only, and Test postings go to `sandbox_*` ledgers. Test Mode is neither read by nor blocked by Production closings. A mutation check confirmed the new test fails without this line.
4. **Reopening a closed month** needs the Owner or the new permission `finance.periods.reopen`, plus a non-empty reason; audited as `finance_month.reopened`. Closing still needs `finance.monthly_situation.manage`.
   - The permission is declared in `config/platform.php` only. No role is granted it, and **no data migration was written**. Owners hold `*`, and the code also accepts the organization owner directly.
   - Note: `PermissionProvisioner::provisionAllOrganizations()` (called by earlier migrations) will add the permission row on its next run. That is additive.
5. The `SandboxInventoryGuard` SQL guard and the generation fence were not modified.

## 5. Files changed (60 staged: 14 added, 46 modified; +2,873 / −139 including docs)

Added:
- `app/Services/Finance/FiscalDocumentProtection.php`, `FinanceClosedPeriodGuard.php`
- `app/Actions/Settings/SetOrganizationFiscalDataModeAction.php`
- `app/Console/Commands/SetOrganizationFiscalDataModeCommand.php`
- `resources/js/utils/businessDate.ts`
- Tests: `BusinessDateTimezoneTest`, `FinanceClosedPeriodTest`, `FiscalDataProtectionTest`, `ReceivablesConsistencyTest`, `Sandbox/SandboxFiscalIntegrationTest`
- Docs: `finance-procurement-gap-analysis.md`, `finance-sprint-0.md` (with an integration addendum), `erp-post-test-mode-roadmap-audit.md`, and this report

Modified, notably:
- The 13 finance, payment and POS actions that call the period guard or `BusinessTime`
- `OrganizationDataResetPlanner`, `SelectiveResetPlanner/Executor`, `OrganizationDataResetController`
- `FinanceReceivablesService`, `FinancePeriod`, `FinanceMonthReviewService`, `FinanceTreasuryForecastService`
- `SalesOrderPaymentCalculator`, `Money`, `McpPaymentService`
- `config/platform.php`, `routes/web.php`, `HandleInertiaRequests`, `Organization`
- Frontend: `DataReset`, `SelectiveReset`, `MonthlySituation`, orders/exchanges pages, `translations.ts`
- 5 existing tests adjusted: `OrganizationDataResetEngineTest`, `InvoiceAttentionTest`, `FinanceMonthReviewExportTest`, `UsersAccessAuthorizationTest` (finance permission group 8 → 9), and the new reopen/mode tests

## 6. Migrations

Exactly one: `2026_11_22_000001_add_fiscal_protection_to_organizations` (renamed from `2026_11_20_000001` so it sorts after the Test Mode migrations).
- Adds `organizations.fiscal_data_mode varchar(16) default 'protected'` and `fiscal_protection_started_at timestamp null`.
- No row is rewritten or deleted; there is no backfill; `down()` drops the two columns.
- Existing organizations get `protected` / `NULL`, meaning everything already issued or posted is protected. Nothing is reinterpreted as test data and no fiscal policy is relaxed.
- Locking: on MySQL 8.4 `ADD COLUMN` with a default is an instant/in-place operation on a small `organizations` table. Not measured on production.
- The only behavioral change for existing data is stricter: resets refuse to delete issued fiscal documents.

No migration deletes, renumbers or backfills documents. Invoice numbering code is untouched (`InvoiceNumberGenerator` not modified).

## 7. Test results

All runs used isolated local databases: SQLite in-memory (`phpunit.xml`) and local MySQL 8.4.3 on `127.0.0.1`, forced to `commerce_erp_dev_test` by `phpunit.mysql.xml`. Nothing touched production. Local MySQL was started for this task.

| Run | Result |
|---|---|
| SQLite, Sprint 0 + Finance, Settings, Payments, Attention | 446 / 446 |
| SQLite, **full suite** | 2,209 / 2,210. The single failure was `UsersAccessAuthorizationTest` (finance permission group count 8 → 9, caused by `finance.periods.reopen`). **Fixed**, and `UsersRolesPermissions` re-run: 47 / 47 |
| SQLite, new tests after fixes | `FiscalDataProtectionTest` 12/12, `FinanceClosedPeriodTest` 8/8, `SandboxFiscalIntegrationTest` 1/1 |
| **MySQL 8.4**: Finance, Settings, Payments, Documents, Security, Attention, Sandbox, Sales, Returns, Exchanges, Inventory, Pos, Mcp, Fulfillment, Procurement | 1,546 / 1,552 (6 not passing, see below) |
| `tsc --noEmit` | Clean |
| `npm run build` (vite) | Success |

MySQL failures, all classified:
- **5 pre-existing.** The same 5 fail identically on pristine `origin/main` (`da8834d`), verified in a separate worktree on the same MySQL: `SandboxHardeningTest::test_stale_session_…`, three `SandboxRaceWindowsTest` tests, `SandboxResetTest::test_preview_counts_test_only_…` (`Unknown column 'key'` in a test query on `organizations`). They are Sandbox-suite issues on MySQL, unrelated to this change. The Test Mode guide itself says the shared-lock behavior is only asserted at transaction level on SQLite.
- **1 environmental.** `SalesOrderCorrectionAlignmentTest` failed only while the SQLite and MySQL suites ran concurrently (shared stamp file under `storage`). It passes alone on MySQL (10/10) and passed on main.

**Not run / limitations:**
- No true two-connection concurrency test for "posting racing a month close" exists. The guard uses a shared lock and gap-lock design, but only transaction-level behavior is tested.
- Idempotency is covered by the existing payment, refund and POS suites that passed. No new idempotency tests were added.
- The Test Mode dedicated race scripts (`tests/Concurrency/*`) were not run.
- Browser checks were not performed.

**Side effect to report:** while cleaning up the baseline worktree, `git worktree remove --force` followed a directory junction I had made and **emptied the project's `vendor/` directory**. `vendor/` is gitignored and reproducible. I started `composer install` to restore it; see §9 item 1. No tracked file or data was affected, and no test results above depend on it (all runs completed before the removal).

## 8. Outstanding risks

1. **Production state UNVERIFIED** (commit, migration status, worker version). The sandbox migrations (`2026_11_21_*`) plus this one will run on the next deploy.
2. Legal retention period, numbering gap after test invoices, and whether BL/quotations are fiscal documents are still **open accountant decisions** (see `finance-sprint-0.md`).
3. Reopen currently has no UI for granting `finance.periods.reopen`. Until a role is given it, only the Owner can reopen.
4. Still guarded only after the fact (not blocked): invoices and credit notes dated in a closed month, opening positions and expected collections (stale-close warning).
5. Invoice, BL and credit-note default dates and the invoice backdate check still use UTC dates (known, out of Sprint 0 scope).
6. Invoice issuance still requires full payment (D-3). Unchanged.
7. The 5 Sandbox-on-MySQL test failures on `main` should be fixed separately; they weaken confidence in the Test Mode race tests on MySQL.
8. Customer exchange settlement payments are not covered by fiscal protection.

## 9. Deployment prerequisites

1. Confirm `vendor/` is restored locally (`composer install`) before running any further local command.
2. Take and verify a production database backup.
3. Verify the production commit and `php artisan migrate:status` read-only, and confirm the Test Mode migrations' state.
4. Rehearse the migrations on a copy of production data (not production). `2026_11_22_000001` is additive; the sandbox ones add `sandbox_id` columns to live tables.
5. Deploy the queue worker and scheduler with the web app (same release).
6. After deploy, verify read-only: a protected organization refuses a selective reset of invoices and payments, and a closed month refuses a backdated payment.
7. Do **not** run any production reset. Do not apply the old stash. Keep `stash@{0}` until this PR is merged and verified.

## 10. Recommendation

**READY FOR PR.** It is code-complete, tested on SQLite (full suite) and MySQL 8.4 (all the required suites), and has no destructive migration, no stored-date rewrite and no invoice numbering change.

**NOT READY FOR DEPLOYMENT** until the production prerequisites above are verified.

No production data was read or modified. Nothing was committed, pushed or deployed.
