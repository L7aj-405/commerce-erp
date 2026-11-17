# 10xScale ERP — Post-Test-Mode Architecture & Roadmap Audit

- **Type:** Read-only audit. The only file written is this report. No branch change, commit, push, deploy, migration or database access.
- **Date:** 2026-11-16
- **Method:** read-only Git (`status`, `log`, `diff`, `ls-tree`, `stash show`, `show`), source inspection, and local tests on the isolated in-memory SQLite config (`phpunit.xml`).
- **Classification:** VERIFIED IMPLEMENTED · PARTIALLY IMPLEMENTED · MISSING · UNVERIFIED · BLOCKED

---

## 1. Executive summary

1. **Test Mode is merged.** The local ref `origin/main` is stale (`c532070`). The real remote `main` is `da8834d` (PR #21, 2026-11-15 16:07). It contains `a3c6b4a`, the current HEAD of `feat/test-mode-sandbox`, and the trees are identical. Nothing is left to merge on this branch.
2. **Sprint 0 is not committed anywhere.** It exists only in `stash@{0}` ("local-work-before-migration-hotfix", 2026-11-12), on top of base `6ca7924`: 44 tracked files plus 12 untracked files in the stash's untracked commit, including both `docs/audits/*.md` reports. The branch, `main` and production do not have it. `docs/audits/` does not exist in the working tree.
3. **That stash is now 33 commits behind HEAD and overlaps 14 files** that the Test Mode work changed after it (including `routes/web.php`, `CreatePosSaleAction`, both data-reset planners and `DataReset.tsx`). It must be rebased deliberately, not popped blindly.
4. **Test Mode is a strong, well-tested isolation layer** (physically separate `sandbox_*` ledgers, SQL guard, generation fence, reset). It does not cover customers, products or supplier master data. These are shared and read-only in Test Mode by design. It also overlaps conceptually with Sprint 0's `fiscal_data_mode = test`.
5. **Finance gaps from the original analysis are unchanged on HEAD.** There is no supplier invoice, no AP, no priced PO, no standalone PO, no supplier fiscal identity. Invoices still require full payment (`IssueInvoiceAction`). The selective reset can still delete issued invoices and payments. Month close is still advisory. UTC "today" is still used for payment, refund and instrument dates.
6. **MCP "delivery" gap is mostly a naming and design question, not missing ERP code.**
   - The ERP already has a full Fulfillment/shipment engine. MCP exposes the logistics half only (tracking, mark delivered), and **deliberately not hand-over**, which is the step that deducts stock.
   - `preview_stock_adjustment` and `apply_stock_adjustment` exist on HEAD (`a3c6b4a`), merged into `main`.
   - `preview_delivery`, `confirm_delivery`, `get_delivery` and `get_delivery_document` have never existed under those names. `DeliveryNote` (BL) has no MCP tools at all.
7. **Production deployment is UNVERIFIED.** There is no Coolify access from here. The MCP connector available in this session lists no fulfillment, stock-adjustment, invoice or payment tools. That is consistent with an older deployment or a narrow scope, but it is not proof.
8. **Recommended order: A → (reconcile with B) → C → D → E.** Stabilize and integrate Sprint 0 first. Then expose delivery/stock MCP (small). Then Sprint 1 (supplier profiles) and Sprint 2 (priced standalone POs). See §9.

---

## 2. Git / local / production status

| Item | Finding | Status |
|---|---|---|
| Current branch | `feat/test-mode-sandbox` @ `a3c6b4a` (2026-11-15 15:58), tracks `origin/feat/test-mode-sandbox` | VERIFIED |
| Working tree | Clean. No local modifications and no untracked files | VERIFIED |
| Local `origin/main` ref | Stale at `c532070` (PR #19). `git ls-remote` shows remote `main` = `da8834d` | VERIFIED |
| Remote `main` | `da8834d` = merge of PR #21; ancestors include PR #20 and #19. `a3c6b4a` is an ancestor. `git diff a3c6b4a da8834d` is empty | VERIFIED |
| Ahead/behind (local stale ref) | Showed "5 behind / 2 ahead". This is an artifact of the stale ref. Against `da8834d`, the branch is fully merged | VERIFIED |
| Test Mode merged? | Yes, PRs #15–#21 into `main` | VERIFIED |
| Other branches | `ui/v1-final-qa` (`37b17b0`, merged into main). No Sprint 0 branch | VERIFIED |
| Stash | `stash@{0}`: Sprint 0 work. Only one stash | VERIFIED |
| Sprint 0 committed? | No | VERIFIED |
| Sprint 0 merged? | No | VERIFIED |
| Conflict risk | 14 files overlap between the stash and post-base history (list below). Both data-reset planners and `routes/web.php` are the high-risk ones | VERIFIED |
| Duplicated changes | Concept overlap: Sprint 0 `organizations.fiscal_data_mode` (`test`/`protected`) vs Test Mode `sandboxes`. The first declares an organization's production data as disposable. The second makes disposable data physically separate. They need a single policy (§3, §7) | VERIFIED (concept) |
| Migration ordering | Sprint 0 `2026_11_20_000001` sorts before the sandbox `2026_11_21_00000x`. Laravel runs pending migrations regardless of order, but dev, CI and production must be checked. Rename it later than `…11_21_000008` to keep history linear | VERIFIED |
| Production commit / version | Not accessible | **UNVERIFIED** |
| Production migration status | Not accessible | **UNVERIFIED** |
| Worker / scheduler version | Not accessible. `DEPLOYMENT_CHECKLIST.md` describes a single Coolify app: web + one queue worker + scheduler. The worker version must be checked separately, because an old worker running new job classes breaks | **UNVERIFIED** |

Overlapping files (stash vs `6ca7924..HEAD`):
`CreatePosSaleAction`, `RefundCustomerReturnAction`, `FinanceMonthlySituationController`, `ExecutiveDashboardService`, `OrganizationDataResetPlanner`, `SelectiveResetPlanner`, `SelectiveReset.tsx`, `translations.ts`, `Finance/MonthlySituation.tsx`, `Sales/Exchanges/Show.tsx`, `Sales/Orders/Show.tsx`, `Settings/DataReset.tsx`, `types/app.ts`, `routes/web.php`.

`FinanceMonthlySituationController` is the most delicate. Test Mode fixed a receivables leak there (`whereNull('sales_orders.sandbox_id')`). Sprint 0 rewrote the same query path (the receivables consolidation).

**Production-data note:** the user states real November 2026 sales are in production. If production runs a build older than `a3c6b4a`, or older than the sandbox migrations, deploying `main` will run `2026_11_21_000001…8`. These create tables and add nullable `sandbox_id` columns to `sales_orders`, `invoices`, `quotations`, `customer_returns` and others (per the guide; not run here). They need a verified backup first.

---

## 3. Sprint 0 implementation status

Source of truth: `stash@{0}` and its untracked parent `stash@{0}^3`, read with `git show`. Nothing was applied.

| Item | Implemented (in stash) | Tested | Committed | Merged | Deployed |
|---|---|---|---|---|---|
| Fiscal document protection: `FiscalDocumentProtection` (`app/Services/Finance/FiscalDocumentProtection.php`, class L41, `selectiveBlockers` L135), `organizations.fiscal_data_mode` migration `2026_11_20_000001` | Yes (stash) | `FiscalDataProtectionTest` (11) | **No** | **No** | **UNVERIFIED (almost certainly no)** |
| Selective reset safeguard: executor postcondition `SelectiveResetExecutor` L79 and L139–140 (protected counts identical before and after) | Yes (stash) | Same | No | No | UNVERIFIED |
| Full reset safeguard (blocker `fiscal.protected_documents.<table>`) in `OrganizationDataResetPlanner` | Yes (stash) | `OrganizationDataResetEngineTest` updated | No | No | UNVERIFIED |
| **On HEAD today:** selective reset still lists `invoices` and `payments` as deletable (`SelectiveResetCategoryRegistry` L51–70) | **Gap is live** | n/a | n/a | n/a | n/a |
| Closed periods: `FinanceClosedPeriodGuard::assertOpen()` (L40; shared lock L69), wired into 13 actions (payments, refunds, outgoing payments, obligations, installments, instruments, recurring generation) | Yes (stash) | `FinanceClosedPeriodTest` (7) | No | No | UNVERIFIED |
| Reopening permission and audit: `ManageFinanceMonthReviewAction::reopen()` (L54–62) audits `finance_month.reopened` with reason. Permission is **still the same** `finance.monthly_situation.manage` as closing (L70) | **PARTIALLY IMPLEMENTED**: audit yes, segregation of duties no (Sprint 0 §"Remaining decisions" #3) | Part of the month-review tests | No | No | UNVERIFIED |
| Morocco timezone: `BusinessTime::today($org)` in `RecordPaymentAction` L75 (stash), POS, MCP payment, refunds, clearing value date, month review, `tenant.business_today` prop | Yes (stash) | `BusinessDateTimezoneTest` (6: UTC+1, Ramadan UTC+0, month boundary) | No | No | UNVERIFIED |
| **On HEAD today:** `RecordPaymentAction` L70 `now()->toDateString()`, `RefundPaymentAction` L107, `ManageFinancePaymentInstrumentAction` L85 all use UTC | **Gap is live** (00:00–01:00 Casablanca backdate false-positive) | n/a | n/a | n/a | n/a |
| Not covered by Sprint 0 (own report): invoice/BL/quotation/credit-note default dates, invoice backdate check, commission filters | MISSING | — | — | — | — |
| Receivables consistency: one order-exposure query, set-based SQL, DB pagination (`FinanceReceivablesService`, +237 lines in stash) | Yes (stash) | `ReceivablesConsistencyTest` (4) | No | No | UNVERIFIED |
| Regression tests: 28 new, 3 updated | Yes | Reported: SQLite 2,143/2,145 (1 pre-existing failure, `CompletedTransfersVisibilityTest`); MySQL 8.4 suites for Finance 222, Settings 130, Payments 47, Documents 143, Security 157, Attention 47 | No | No | n/a |

**Not run in Sprint 0 (its own admission):** MySQL suites for POS, Sales, Returns, Exchanges and MCP; a real parallel-session test of a posting racing a month close.

**Unresolved accountant decisions** (Sprint 0 report): legal retention period; numbering gap at go-live; whether delivery notes and quotations count as fiscal documents; customer exchange settlement payments are not protected.

**Integration problem to settle before restoring Sprint 0:**
- Sprint 0 treats `fiscal_data_mode = test` as "this organization's real tables hold throwaway data".
- Test Mode now keeps throwaway data in `sandbox_*` ledgers and rows with `sandbox_id`.
- Protection should apply only to rows with `sandbox_id IS NULL`. The Sprint 0 `protectedQuery()` (L81) must be checked against that.
- The "Production → test" toggle becomes mostly redundant. Keep it only as the platform escape hatch for already-polluted demo organizations.
- `SandboxProductionResetGuard` already blocks a production reset while Test data exists. The two guards must be composed, not duplicated.

---

## 4. Test Mode coverage matrix

Design (`docs/features/test-mode-sandbox/IMPLEMENTATION_GUIDE.md`, `app/Services/Sandbox/*`, `ResolveSandboxContext`, `ScopesToSandbox`, `RoutesToSandboxInventory`):
- Roots with a nullable `sandbox_id` (NULL = Production): sales orders, quotations, invoices and families, delivery notes, credit notes, returns, exchanges.
- Physically separate `sandbox_*` tables: inventory (balances, movements, reservations, allocations, transfers), payments, allocations, refunds, expected collections, commissions, procurement (requirements, POs, PO lines, receipts, availability requests), fulfillments.
- Guards and fences: `SandboxInventoryGuard` (SQL guard: a Test request naming a Production ledger table is refused), `SandboxGenerationFence` (stale-reset race fence), a route allowlist (137 routes), and a Test document number generator (`TEST-…`).
- Migrations `2026_11_21_000001…8`.

| Area | Isolation | Status | Evidence |
|---|---|---|---|
| Products, variants, catalog config | **Shared, read-only** in Test Mode (writes blocked `403 SANDBOX_DOMAIN_UNAVAILABLE`) | VERIFIED (intentional; not isolated) | `SandboxModuleCatalog::UNAVAILABLE` |
| Customers / contacts | **Shared, read-only**; customer creation blocked in Test Mode | VERIFIED (intentional) | guide Phase 5.5. Customer balance reads filter `sandbox_id IS NULL` (`CustomerFinancialSummary`) |
| Suppliers, supplier catalog | **Shared, read-only** | VERIFIED (intentional) | guide Phase 6 |
| Sales orders + POS | `sandbox_id` on roots; `TEST-<ULID>` numbers | VERIFIED IMPLEMENTED | Phase 2; `SandboxFoundationTest` |
| Quotations + documents | `sandbox_id`; `TEST-` numbering; watermark; e-mail simulated; share links, stamp and e-mail refused | VERIFIED IMPLEMENTED | Phases 3, 5.6; `SandboxQuotationFlowTest`, `SandboxDocumentsTest` |
| Invoices + numbering | `TEST-FAC` via `sandbox_document_sequences`; Production `N/YYYY` sequence never touched. Test invoices have **no payment gate**, unlike Production (`IssueInvoiceAction` ~L75–83) | VERIFIED IMPLEMENTED (behavioral difference) | `SandboxDocumentNumberGenerator` |
| Payments + financial accounts | `sandbox_payments` etc. **Financial accounts are shared master data**; Test payments do not alter real cash balances | VERIFIED IMPLEMENTED | Phase 5; `SandboxFinanceTest` |
| Inventory + stock movements | Separate ledger, snapshot taken on entering Test (counts as Test data until reset) | VERIFIED IMPLEMENTED | Phase 4; `SandboxInventoryTest`, `tests/Concurrency/sandbox_inventory_race.php` |
| Procurement + supplier transactions | `sandbox_*` tables, `TEST-PR` / `TEST-PO`, receipt only raises Test stock. Test receipts never create transfer requests | VERIFIED IMPLEMENTED | Phase 6; `SandboxProcurementTest` |
| Deliveries / fulfillment | `sandbox_fulfillments*`; notification recorded `test_simulated`, never sent; WooCommerce tasks skipped | VERIFIED IMPLEMENTED | Phase 7; `SandboxFulfillmentTest` |
| Returns / exchanges / credit notes | `sandbox_id` + `sandbox_customer_exchange_payments`; `TEST-RET/ECH/AV` | VERIFIED IMPLEMENTED | Phase 8 |
| Reset | `SandboxResetPlanner/Executor`: one transaction, preview fingerprint, FK-map preflight, per-step exact counts, generation bump, never touches Production | VERIFIED IMPLEMENTED | Phase 9 |
| Production reset vs Test data | `SandboxProductionResetGuard` blocks Production reset while Test rows exist | VERIFIED IMPLEMENTED | Phase 10 |
| Background jobs / scheduler | Scheduled commands run in console with no session, so always Production. `attention:scan`'s invoice subquery filters `sandbox_id IS NULL`. Queued jobs listed: backups, WooCommerce sync, fulfillment notification, MCP product ops | PARTIALLY VERIFIED. Documented, not independently traced for every job. `SendFulfillmentCustomerNotificationJob` must never be dispatched for Test rows (documented as skipped) | guide Phase 10 |
| External integrations (WooCommerce, carriers, e-mail) | Blocked or simulated in Test Mode | VERIFIED IMPLEMENTED (by design) | guide Phases 7, 10 |
| MCP | **MCP never carries a Test context and always writes Production** (`McpStockAdjustmentService` docblock; guide Phase 10) | VERIFIED. Safe for Production, but an AI/MCP client cannot practice in Test | — |
| Notifications | Test sessions get an empty feed; the Production inbox is not exposed | VERIFIED IMPLEMENTED | Phase 10.1; `SandboxRaceWindowsTest` |
| Finance reports (Créances, month review, dashboards) | Blocked in Test; Production queries filter `sandbox_id IS NULL`. One leak was found and fixed (`FinanceMonthlySituationController@index`) | PARTIALLY IMPLEMENTED. Any raw query or new report not filtering `sandbox_id` leaks Test orders into Production reports. This is the main future risk | guide Phase 10 |
| Permissions / audit | `sandbox.manage`, `sandbox.entered/left/reset/stale_rejected` audited | VERIFIED IMPLEMENTED | Phases 9–10 |
| Switch Test → Production contamination | The generation fence, SQL guard and composite `(sandbox_id,id)` FKs prevent cross-links. Reset's postcondition checks each table's org total dropped by exactly the deleted amount | VERIFIED by design and tests. Not re-verified on real MySQL in this audit | — |

**Remaining shared mutable data between environments:**
- Master data is intentional.
- Financial accounts: Test payments reference shared accounts and stay in `sandbox_*`, so balances are unaffected.
- Numbering: separate sequences.
- Documented autocommit single statements (POS draft header and others) were wrapped in a transactional save in Phase 10.1. A true two-connection test of the shared-lock wait does not exist; it is only asserted at transaction level on SQLite.

---

## 5. Finance & Procurement gap matrix (re-audit on HEAD)

The original gap analysis exists only in the stash. Re-verified against HEAD:

| Area | Finding | Status | Evidence |
|---|---|---|---|
| Supplier legal/fiscal identity (ICE, IF, RC, patente) | Supplier columns unchanged since creation (name, contact, phone, email, address, notes, active). No migration after `2026_09_16_000001` alters `suppliers` | MISSING | `database/migrations/2026_09_16_000001_*.php` L63; `Supplier` docblock L10–14 |
| Banking info (RIB) | None | MISSING | same |
| Supplier documents / certificates | None | MISSING | no migration |
| Commercial agreements / versioning / snapshots | None | MISSING | — |
| Payment terms per supplier | Only global env values | MISSING | — |
| Standalone replenishment PO | `purchase_order_lines.sales_order_procurement_id` and `sales_order_line_id` still NOT NULL | MISSING | `2026_11_06_000001_create_purchase_orders_tables.php` L89–91 |
| PO unit costs | `unit_price` column exists (nullable, L95) but is filled from `supplier_unit_cost`, which nothing writes | PARTIALLY (column only; always NULL) | `CreatePurchaseOrderFromProcurementRequirementsAction` L173 |
| Purchase VAT | None | MISSING | — |
| Goods receipt | Back-to-back only; no cost on movement, no reversal | PARTIALLY | `ReceiveProcurementAction` |
| Supplier invoices | No table | MISSING | no migration |
| Three-way match | Depends on the above | MISSING | — |
| Accounts payable / supplier balance | Manual `finance_obligations` only | MISSING (manual proxy) | — |
| Supplier payment schedules, partial payments, cheques/traites (outgoing) | Complete | VERIFIED IMPLEMENTED | installment plans, `FinanceOutgoingPayment`, `FinancePaymentInstrument` |
| Customer credit terms | Global env only | MISSING | — |
| **B2B invoicing before payment** | Still blocked: `La facture ne peut être émise qu'après le règlement intégral de la commande.` | MISSING | `IssueInvoiceAction` ~L75–83; `InvoiceLifecycleTest` L57–103 |
| Customer receivables | Three definitions on HEAD. Sprint 0 consolidation is only in the stash | PARTIALLY | stash `FinanceReceivablesService` |
| Customer cheques/traites (incoming) | Only cash, card, bank_transfer, cheque as a method; no portfolio | MISSING | `PaymentMethod` |
| Bank reconciliation | None | MISSING | — |
| Monthly close | Advisory on HEAD; guard only in the stash | PARTIALLY | HEAD has no `assertOpen` anywhere |
| Treasury report | Single-month, manual expected collections | PARTIALLY | `FinanceTreasuryForecastService` |
| VAT structure | Org `tax_rates`, per-line snapshot; no deductible VAT, no declaration report | PARTIALLY | `tax_rates` |
| Withholding tax extensibility | No code; nothing to extend | MISSING | grep: no "withholding"/"retenue" |
| Versioned tax rules | Rates are snapshotted per line, but the rate table itself is not effective-dated | PARTIALLY | — |
| Auditability | `AuditLogger` on every mutation; some events lack old values | PARTIALLY IMPLEMENTED | — |
| Electronic invoicing readiness | No structured e-invoice export, no signature or format layer. Do not infer any compliance | MISSING / UNVERIFIED legally | — |

No legal compliance is asserted anywhere in this report.

---

## 6. Sales, Deliveries & MCP

### Sales / fulfillment architecture (already exists)
- `app/Actions/Fulfillment/*`: Start preparation, Mark ready, Hand to carrier, In transit, Mark delivered, Close, Cancel, Update shipment details, Attach/Remove proof, Share with customer.
- `FulfillmentEngine` (`app/Services/Fulfillment/FulfillmentEngine.php`):
  - `lineState` L127 (ordered / shipped / remaining per line)
  - `validateItems` L177 (partial deliveries)
  - `create` L232
  - `consumeStock` L271 (stock OUT exactly once, guarded by `stock_consumed_at`, order and fulfillment row locks, and `client_operation_id`)
  - `syncOrder` L321
- Stock leaves **only at hand-over**, not at "delivered". Confirmation reserves stock.
- Delivery notes (BL): `app/Actions/Documents/*DeliveryNote*` (create full from order, update draft, issue, cancel, e-mail). **No stock movement** (the Test catalog says so: "TEST-BL, sans mouvement de stock"). No partial-BL action found.
- Invoice issuance is gated on full payment (§5). Historical invoice dates go through `AuthorizesDocumentAction`; the backdate check uses UTC (stash notes it as out of scope).

### MCP: the six named operations

| Requested | Actual state on HEAD | Class |
|---|---|---|
| `preview_delivery` | Does not exist. Closest: `preview_shipment_delivery` (logistics only, no stock) | MISSING (different semantics) |
| `confirm_delivery` | Does not exist. Closest: `mark_shipment_delivered` | MISSING (as named) |
| `get_delivery` | Does not exist. Closest: `get_sales_order_shipments` (state per line + every shipment) | PARTIALLY |
| `get_delivery_document` | No MCP tool for `DeliveryNote` (BL). The ERP has BL create/issue/print/e-mail actions and routes; MCP has none | MISSING |
| `preview_stock_adjustment` | **Exists** (`StockAdjustmentToolRegistrar`, scope `inventory.adjust` + `inventory.view`; `McpStockAdjustmentService` wraps `ManualStockAdjustmentAction` → `AdjustInventoryAction`) | VERIFIED IMPLEMENTED — added in `a3c6b4a`, merged in `da8834d`. Deployed: UNVERIFIED |
| `apply_stock_adjustment` | **Exists**, same files, idempotency target `inventory_movement` | VERIFIED IMPLEMENTED (same note) |

**Why delivery tools are missing:** the `FulfillmentToolRegistrar` docblock states this on purpose. *"No hand-over tool exists: the stock OUT can never be triggered (or repeated) over MCP."* Tools are logistics only (`LOGISTICS_ONLY` constant). The ERP capability exists (`HandFulfillmentToCarrierAction`, `FulfillmentEngine::consumeStock`). A previewed + fingerprinted + idempotent MCP wrapper follows the proven pattern already used in `McpStockAdjustmentService`. No parallel architecture is needed.

Other observations:
- The ERP_10X connector visible in this session has no fulfillment, stock-adjustment, invoice, payment or supplier tools. A connection only lists tools whose scope the connection holds (`McpServerFactory`). This could be a scope limitation, an older deployed build, or both. It is **UNVERIFIED** which.
- MCP always acts on Production; the web session's Test Mode never applies.

### Operations still missing for MCP (from code, not from a live listing)
Create full or partial delivery from an order (fulfillment create with items), hand over (stock OUT), BL read and issue and document, invoice before payment (blocked by the ERP rule), supplier invoice and payable tools (none exist), receivables and supplier balance reads, historical imports.

### Historical transaction imports
No import path was found for orders, payments or invoices (only product import: `catalog:cleanup-product-imports`, `ProcessMcpProductOperationChunkJob`). Real November sales therefore had to be entered through the normal create flows. Backdating requires `payments.backdate` and a document backdate permission; the invoice and BL backdate check uses UTC. **MISSING (no import framework).**

---

## 7. Production data risks

| # | Risk | Severity | Evidence | State |
|---|---|---|---|---|
| R1 | Selective reset can delete issued invoices, credit notes and posted payments of the real November data. Owner + 2FA + phrase are the only barriers | **High** | `SelectiveResetCategoryRegistry` L51–70; Sprint 0 protection only in stash | LIVE on HEAD |
| R2 | Month close is advisory. Real payments can be posted or backdated into a closed month | Med-High | no `assertOpen` on HEAD | LIVE |
| R3 | UTC business dates: a payment recorded 00:00–01:00 Casablanca is treated as backdated or gets the previous day | Medium | `RecordPaymentAction` L70, `RefundPaymentAction` L107, `ManageFinancePaymentInstrumentAction` L85 | LIVE |
| R4 | Invoices cannot be issued before payment, so real B2B credit sales cannot get compliant invoices at delivery | High (functional / regulatory, verify) | `IssueInvoiceAction` | LIVE |
| R5 | Real sales entered with no historical-import tool, so dates, numbering order and backdate permissions are handled by hand. Invoice numbers are sequential `N/YYYY`, so entry order defines number order | Medium | `InvoiceNumberGenerator` | LIVE |
| R6 | Migrating production to the sandbox schema adds `sandbox_id` and 20+ tables. Rollback `down()` refuses while Test rows exist | Medium | `2026_11_21_*` | Needs backup + staging run |
| R7 | Restoring the stash: overlapping files, receivables query collision, double "test" concept | Medium | §2 | Unresolved |
| R8 | A future report or raw query that forgets `sandbox_id IS NULL` leaks Test orders into Production figures. The precedent was fixed once | Medium | guide Phase 10 | Structural risk |
| R9 | Stock is unprotected by anything but permission on MCP: `apply_stock_adjustment` writes Production stock directly | Low-Med | `McpStockAdjustmentService` | Mitigated by preview + fingerprint + idempotency + audit (per tests) |
| R10 | Duplicate/invalid ICE entries: free text, no validation | Low-Med | `tax_identifier` | LIVE |

Positive controls verified: composite tenant FKs with RESTRICT, `lockForUpdate` on balances, sequences and transitions, idempotency keys (payments, POs, receipts, MCP), immutable invoice snapshots with versioned corrections, `stock_consumed_at` once-only stock OUT, audit on mutations.

**No destructive reset is recommended as a fix for any risk above.** Test data is removed by the Sandbox reset. The Production reset should become unusable once real invoices exist (Sprint 0 R1 fix).

---

## 8. Test results

Executed here, locally, on the isolated in-memory SQLite configuration from `phpunit.xml` (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`). Nothing touched production.

| Command | Result |
|---|---|
| `php artisan test tests/Feature/Sandbox tests/Feature/Mcp/McpStockAdjustmentToolsTest.php` | **55 / 55 passed, 1,578 assertions, 183 s, exit 0** |

The full suite was not run here. Fulfillment, Finance, Procurement and Documents tests were not re-run.

**Reported by the repository's own docs, not re-run here:**
- Sandbox suite 44/44 on SQLite (Phase 10.1); procurement, fulfillment, returns and migrations also run on MySQL 8.4 per guide.
- Sprint 0: 28 new tests, MySQL suites listed in §3, 1 pre-existing SQLite baseline failure (`CompletedTransfersVisibilityTest::…rebuilt_from_its_movements`, `'4.0000'` vs `'4'`).

**MySQL coverage:** `phpunit.mysql.xml` exists (`commerce_erp_dev_test`, localhost). MySQL was **not** run in this audit (requires a local MySQL service, not verified). Known MySQL-only issues that SQLite hides: the 64-character constraint-name limit (fixed in `6ca7924`) and VARCHAR widths (`2026_11_17_000001`).

**Missing tests:**
- A real two-connection concurrency test for the Test Mode shared-lock wait (only `tests/Concurrency/sandbox_inventory_race.php` exists for inventory).
- Posting racing a month close (Sprint 0).
- MySQL for POS, Sales, Returns, Exchanges and MCP after Sprint 0.
- MCP delivery tools (none exist).
- Cross-environment leak detection for new Production reports, such as an automated check that every Production read of a sandbox-capable root filters `sandbox_id`.

---

## 9. Recommended next sprint

| Option | Verdict | Reason |
|---|---|---|
| **A. Sprint 0 hardening + integration** | **Do first** | Closes live risks R1–R3 on real data. It is finished work that is at risk in a stash. Its blocking questions are small. |
| **B. Test Mode isolation gaps** | Fold into A | No true leak remains. The gaps are policy: reconcile `fiscal_data_mode` with `sandbox_id`, and add a guard test. |
| **C. Delivery/Inventory MCP** | **Second, short (≈2–3 days)** | Stock adjustment is already done. Remaining work wraps existing actions: BL read/document and shipment create/hand-over with preview + idempotency. |
| **D. Sprint 1 — Supplier profiles & agreements** | Third | Pure additive schema. It is a prerequisite for E (terms, VAT identity). |
| **E. Sprint 2 — Standalone priced POs** | Fourth | Needs D (terms, currency) and a decision on nullable `sales_order_procurement_id`. It also depends on the Test Mode procurement mirror (`sandbox_purchase_order_lines`) being migrated in step with Production. |

Dependencies:
- A (rebase + composition with Test Mode) must precede any new finance work, otherwise Sprint 1 and 2 pile onto an un-merged `FinanceReceivablesService`.
- Every new transactional table in D or E must choose between "Test-isolated" (a `sandbox_*` twin or `sandbox_id`) or "Production-only, blocked in Test", and add the table to `SandboxResetPlanner`, the SQL guard and the FK map. This is a new standing cost from Test Mode.
- Invoice before payment (R4) needs an accountant decision and is not blocked by A. It is the highest business-value item after A. Plan it with customer terms, after D.

Safest order: **A → C → D → E**, with the invoice-before-payment change scheduled right after A if the business needs it sooner. Do not start D or E until A is merged.

---

## 10. Concrete next implementation tasks

1. Verify production out-of-band: the Coolify deployed commit, `php artisan migrate:status` on production (read-only), the worker/scheduler image version, and a database backup before any further deploy.
2. Create `feat/finance-sprint-0` from `origin/main` (`da8834d`). Re-apply the stash on it in pieces (files by area), resolving the 14 overlaps by hand. Start with the receivables path in `FinanceMonthlySituationController`, keeping `whereNull('sales_orders.sandbox_id')`.
3. Rename migration `2026_11_20_000001` to a timestamp after `2026_11_21_000008`.
4. Restrict `FiscalDocumentProtection::protectedQuery()` to `sandbox_id IS NULL` rows. Compose it with `SandboxProductionResetGuard`. Decide the fate of `fiscal_data_mode = test`.
5. Add a dedicated reopen permission (e.g. `finance.periods.reopen`), once the business confirms (open decision #3).
6. Run the Sprint 0 tests plus the full MySQL suites, including the skipped POS, Sales, Returns, Exchanges and MCP, and a posting-vs-close race test.
7. Fix the `BusinessTime` gaps Sprint 0 left: invoice, BL and credit-note default dates and the invoice backdate check.
8. MCP: add `get_delivery_note` / `get_delivery_note_document` (read-only wrappers on `DeliveryNote`). Add `preview_shipment_handover` and `confirm_shipment_handover` (wrap `HandFulfillmentToCarrierAction`/`FulfillmentEngine::consumeStock`) with fingerprint, idempotency key and audit, and an explicit decision to lift the "no stock OUT over MCP" rule. Publish a tool-name mapping (`preview_delivery` → `preview_shipment_delivery`, etc.) to avoid client confusion.
9. Confirm the connection scopes for the ERP_10X connector, so fulfillment and inventory scopes are granted to the connection that needs them.
10. Define a historical-import path for sales and payments with the backdate and numbering rules, instead of manual entry.
11. Sprint 1: add supplier fiscal and banking columns, an agreements table with versioning, and a snapshot on POs. Add ICE validation. Decide Test Mode treatment (suppliers remain shared).
12. Sprint 2: make `purchase_order_lines.sales_order_procurement_id` / `sales_order_line_id` nullable. Add price, VAT and totals. Mirror to `sandbox_purchase_order_lines`.
13. Add a leak-detection test that enumerates sandbox-capable models and asserts that Production readers filter `sandbox_id`.
14. Get accountant answers: retention period, numbering gap after test invoices, invoice timing and VAT regime for B2B, whether BL and quotations are fiscal documents.
