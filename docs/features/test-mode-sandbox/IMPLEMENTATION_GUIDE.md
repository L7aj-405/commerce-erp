# Test Mode / Sandbox — Implementation Guide

## Phase 2 — Sales & POS sandbox isolation

### Status

**Complete** (draft-only scope). Sales Orders (including POS carts) and Quotations are environment-aware. Phase 2 supersedes the Phase 1 "everything blocked" route restriction for the sales routes listed below; every other module stays blocked exactly as in Phase 1.

### Migration

`database/migrations/2026_11_21_000002_isolate_sandbox_sales.php`

- Adds nullable `sandbox_id` to `sales_orders` and `quotations` (FK → `sandboxes`, `ON DELETE RESTRICT`).
- Adds `{table}_environment_idx` on `(organization_id, sandbox_id, store_id)`.
- Existing rows stay Production (`sandbox_id IS NULL`). Apply before serving the new model code.
- **Rollback guard:** `down()` throws `RuntimeException` and drops nothing while any Test sales/quotation row exists, so a rollback can never silently promote Test drafts into Production (covered by `SandboxSalesTest::test_rollback_refuses_to_drop_isolation_while_test_records_exist`).

### Files

Created:

- `app/Models/Concerns/ScopesToSandbox.php` — global scope + write guards
- `app/Services/Sandbox/SandboxSalesRoutes.php` — explicit Test route allowlist
- `database/migrations/2026_11_21_000002_isolate_sandbox_sales.php`
- `resources/js/components/SandboxBanner.tsx` — persistent TEST banner
- `tests/Feature/Sandbox/SandboxSalesTest.php`

Modified — isolation core: `app/Models/{SalesOrder,SalesOrderLine,Quotation,QuotationLine}.php`, `app/Policies/{SalesOrderPolicy,QuotationPolicy}.php`, `app/Actions/Sales/Concerns/AuthorizesSalesAction.php`, `app/Actions/Quotations/Concerns/AuthorizesQuotationAction.php`, `app/Http/Middleware/ResolveSandboxContext.php`, `bootstrap/app.php` (context middleware runs before route-model binding).

Modified — Test workflow: `app/Actions/Quotations/{CreateQuotationAction,SaveQuotationLineAction,RemoveQuotationLineAction,ConvertQuotationToSalesOrderAction}.php`, `app/Actions/Sales/SaveSalesOrderLineAction.php`, `app/Services/SalesOrderNumberGenerator.php`, `app/Services/Pos/PosCartStockGuard.php`.

Modified — Production queries that bypass model scopes (explicit `sandbox_id IS NULL`): `app/Services/QuotationNumberGenerator.php`, `app/Services/SalesMarginReportService.php`, `app/Services/CustomerFinancialSummary.php`, `app/Services/Dashboards/ExecutiveDashboardService.php`, `app/Services/Finance/{FinanceCaEncaisseService,FinanceJournalService,FinanceMonthlyReportService,FinanceReceivablesService}.php`, `app/Services/Commissions/{CommissionDashboardService,CommissionReconciliationService,CommissionSimulationService}.php`, `app/Services/OrganizationBackups/OrganizationBackupExporter.php`, `app/Http/Controllers/PlatformController.php`, `app/Actions/Sales/ConvertOrderToShowroomOriginAction.php`, `app/Actions/Commissions/CreateReturnCommissionReversalsAction.php`, `app/Actions/Finance/ManageFinanceExpectedCollectionAction.php`, `app/Console/Commands/{ConvertOrderToShowroomOriginCommand,RecalculateSalesOrderPaymentStatusCommand}.php`.

Modified — UI/tests: `resources/js/layouts/ApplicationShell.tsx`, `resources/js/pages/Sandbox/Index.tsx`, `tests/Feature/Sandbox/SandboxFoundationTest.php`.

### Test / Production isolation rules

1. **Environment comes from the server.** `SandboxContext::id()` (session-resolved, validated against org membership/policy) is the only source; Production is `null`. Queue workers, scheduler, CLI and MCP have no Test context and therefore only see Production.
2. **Eloquent scope.** `ScopesToSandbox` adds `where {table}.sandbox_id = <context>` (`IS NULL` in Production) to every `SalesOrder`/`Quotation` query. Lines (`SalesOrderLine`, `QuotationLine`) are scoped through `whereHas` on their parent.
3. **Writes.** New rows are stamped with the context's `sandbox_id`; a Test row is only created when the sandbox belongs to the row's organization. Persisted rows can only be saved from their own environment and `sandbox_id` is immutable. A Test row may only be persisted in `draft` (quotations also `converted`). A quotation's `converted_sales_order_id` must resolve in the current environment/org/store.
4. **Authorization.** Policies and the sales/quotation action traits return 404/false when the record's environment differs from the context, and restrict Test records to `view`/`update` (+ `quotations.convert`). Organization/store/RBAC checks still apply on top.
5. **Raw queries.** Every `DB::table()` / `join()` / `withoutGlobalScopes()` access to `sales_orders`/`quotations` in Production reporting, dashboards, commissions, finance, commands and numbering carries an explicit `sandbox_id IS NULL` predicate. Remaining unfiltered joins start from tables (commission entries, payment allocations) that can only reference confirmed/paid Production orders. **New raw queries on these tables must add the predicate.**
6. **Backups.** Production backup export excludes Test orders/quotations and their lines.
7. **Numbering.** Test references are `TEST-<ULID>`; Test never locks or advances `sales_order_sequences`, and `QuotationNumberGenerator::maxAllocatedNumber` ignores Test rows.

### Allowed Test workflow

Routes are listed explicitly in `SandboxSalesRoutes::ALLOWED`; anything not listed returns `403 SANDBOX_DOMAIN_UNAVAILABLE` (JSON) or redirects to `/test-mode` (GET).

- **POS:** open POS, product/customer selectors, create/edit cart, add/edit/remove lines, hold/resume. The same `pos_terminal` cookie keeps a separate Production cart and Test cart.
- **Quotations:** list, create, view, edit, line add/edit/remove, search; convert a **Draft** Test quotation into a linked **Draft** Test sales order (procurement derivation and inventory effects skipped). Existing non-stock catalogue entries may be selected; usage counters are not changed.
- **Sales orders:** list, create, view, edit, line add/edit/remove, search. Lines create no provisional inventory allocations.
- Store switching (authorized stores only), `/test-mode`, leave, logout.

### Blocked transitions (fail closed)

Confirm, cancel, POS checkout (`pos.sales.store`) and POS draft convert, payments, invoices, delivery notes, fulfillment/preparation/shipping, returns/exchanges/corrections, procurement/purchase orders, supplier sourcing requests, quotation issue/accept/reject/revise/duplicate/email/stamp/PDF, non-stock catalogue creation. Defense in depth: route allowlist (403) → policy/action trait (403/404) → `ScopesToSandbox` saving guard (status must stay draft) → `PosCartStockGuard` (403 for Test carts).

### Validation results

- Sandbox suite (`tests/Feature/Sandbox`): **6 tests / 163 assertions passed** — same-terminal cart separation, bidirectional invisibility, cross-store/cross-environment 404, Test draft conversion, TEST references, unchanged sequences, zero allocations/reservations/movements/procurements, 11 blocked mutation routes, direct-action confirmation refusal, rollback guard.
- POS / Quotations / Sales regression (`tests/Feature/{Pos,Quotations,Sales}`, after Phase 2 + Pint): **356 tests / 2,631 assertions passed**. Earlier broader batch incl. tenant/session/RBAC: 385 tests / 2,773 assertions passed.
- Sandbox + backup/dashboard batch (earlier run): 43 tests / 901 assertions passed.
- `tsc --noEmit`: passed. `vite build`: passed. Pint: every file created by Phase 2, or that Phase 2 made fail, now passes; files that already failed Pint before Phase 2 were left unformatted to keep the diff focused.

### Known limitations before Phase 3

- Only draft sales documents are isolated. Inventory, finance, payments, invoices, procurement, fulfillment, returns, integrations and official documents have no Test representation and stay blocked.
- No Sandbox reset/purge API yet; the Production organization data reset/restore does not yet define how retained Test drafts are handled (needs an explicit design). The FK is `RESTRICT`, so deleting a sandbox row with drafts fails.
- Child tables (e.g. `sales_order_lines`) have no own `sandbox_id`; raw queries on them must join the parent and filter there.
- Shared master data (customers, products, non-stock catalogue) is read-only from Test; creating customers from POS in Test is not in the allowlist.
- Test UI copy is French only.

---

# Phase 1 — Foundation

## Status and architecture

Phase 1 provides a restricted foundation only. No business table or operation is sandbox-aware yet. Production is represented by a null SandboxContext ID; there is no Production sandbox row.

SandboxContext is container-scoped and resolved after ActiveTenantContext. The session selection includes user, organization, sandbox ID and generation. Each request revalidates membership through the existing tenant resolver, permissions, organization policy and sandbox state. Store changes cannot change sandbox ownership. MCP, queues and other non-browser execution remain Production by default.

ResolveSandboxContext allows only the foundation page, explicit exit and logout while a selection exists. All other GET navigation redirects to the foundation page; other requests return 403 SANDBOX_DOMAIN_UNAVAILABLE. Invalidated selections remain restricted until explicit exit, preventing stale Test Mode submissions from falling through to Production. Later phases must expand this route allowlist only after implementing actual domain isolation.

## Configuration and permissions

Organization settings.sandbox_enabled defaults to false. Existing settings are merged under an organization row lock. sandbox.access and sandbox.switch permit entry; sandbox.manage permits policy changes and is reserved for future management/reset. Reset is not implemented. Owner/Admin defaults follow PermissionProvisioner; custom roles receive nothing automatically.

The organization lock serializes shared sandbox creation. A unique organization index enforces one sandbox in V1; names/status/generation support evolution. Supporting multiple named sandboxes will require deliberately replacing that unique constraint.

## UI and audit

The application header links authorized users to /test-mode. The standalone responsive foundation page shows policy controls in Production and a persistent banner/exit action in Test Mode. No operational navigation is offered. Banner: MODE TEST — Les opérations métier ne sont pas encore disponibles.

AuditLogger records sandbox.created, sandbox.entered, sandbox.left and sandbox.policy_changed. Entry does not activate Test Mode for other sessions. Repeated entry reuses the shared row. Credentials, numbering and business data are untouched.

## Files

Created: app/Models/Sandbox.php; app/Services/Sandbox/SandboxContext.php; app/Providers/SandboxServiceProvider.php; app/Http/Middleware/ResolveSandboxContext.php; app/Http/Controllers/SandboxController.php; resources/js/pages/Sandbox/Index.tsx; tests/Feature/Sandbox/SandboxFoundationTest.php; this guide.

Modified: bootstrap/app.php; bootstrap/providers.php; config/platform.php; resources/js/layouts/ApplicationShell.tsx.

Migration: database/migrations/2026_11_21_000001_create_sandbox_foundation.php. Creates sandboxes with short index names and provisions permissions for existing system roles. Down drops the foundation table; permission catalog entries are retained to avoid destructive role edits. Organization policy uses existing JSON settings, requiring no new organization column.

## Validation

Sandbox integration coverage: Production default; disabled policy; authorization; entry/exit; blocked reads/writes; one shared row; independent users/sessions; changed store; changed organization; policy revocation. Existing TenantContextTest, ActiveSessionTest, RbacAuthorizationTest and SessionIsolationTest passed (19 tests, 87 assertions). TypeScript and production build passed. SQLite in-memory database only; no production migration executed.

## Limitations / next phase

No reset, transactional data, reports, integration or external-client isolation is implemented. The foundation UI is French. Future phases must add domain partitioning, job context propagation, audit attribution and backup/reset contracts before admitting corresponding routes. Do not advertise isolated business operations until those guarantees exist. Existing production processes and background jobs continue normally.

Deploy the additive migration before enabling the organization setting. Test on MySQL before production rollout. Only phase-owned files are committed; unrelated local work is excluded.
