# Test Mode / Sandbox — Implementation Guide

## Phase 5 — Sandbox payments & finance isolation

### Status

**Complete** (finance scope). Test Mode records simulated payments (cash, TPE/card, bank transfer), payment allocations, refunds, expected collections and commission entries on a physically separate Test finance ledger. POS checkout works in Test Mode with Test stock and Test payments. Production cash / bank / TPE balances, receivables, revenue, commission reports and every Production finance report are unaffected.

### Finance isolation strategy — routed Test finance ledger (same as Phase 4)

- **Separate tables, same models.** `Payment`, `PaymentAllocation`, `PaymentRefund`, `CommissionEntry`, `FinanceExpectedCollection`, `FinanceExpectedCollectionMatch` use `RoutesToSandboxInventory` (now the shared inventory + finance ledger router): Production tables in Production; `sandbox_payments`, `sandbox_payment_allocations`, `sandbox_payment_refunds`, `sandbox_commission_entries`, `sandbox_finance_expected_collections`, `sandbox_finance_expected_collection_matches` in Test Mode. `RecordPaymentAction`, `RefundPaymentAction`, `ReversePaymentAction`, `CreatePosSaleAction`, `CancelSalesOrderAction`, the commission generator / reversal and `SalesOrderPaymentCalculator` run unchanged on the Test ledger.
- **Why routing instead of a `sandbox_id` column on Production finance tables:** Production finance reporting is built on many raw / query-builder queries (journal, CA encaissé, créances, monthly report, treasury forecast, account balances, commission dashboards, exports). With separate tables those queries **structurally cannot** see a Test transaction — no predicate can be forgotten — and none of them had to change.
- **Ownership hardened (applies to Phase 4 tables too).** Sandbox ownership moved from a removable global scope into `newModelQuery()`: every read, relation, update and delete on a routed model is constrained to the active sandbox, and `withoutGlobalScopes()` can no longer widen a Test query (several finance actions use it).
- **Order links.** `PaymentAllocation`, `PaymentRefund`, `CommissionEntry`, `FinanceExpectedCollection` declare `sandboxSalesOrderColumn()`: the referenced sales order must belong to the row's environment (404 otherwise). Internal Test references are composite `(sandbox_id, id)` FKs (allocation → payment, refund → payment, match → collection / allocation, commission → source entry).
- **Authorization.** `AuthorizesPaymentAction`: the order must belong to the request's environment (404); Test orders allow `payments.view`, `payments.create`, `payments.reverse` only. `PaymentPolicy` checks the order / payment environment. Test orders may now be **cancelled** (refund path) — `SalesOrder::sandboxStatuses()` = draft, confirmed, cancelled.
- **SQL guard.** `SandboxInventoryGuard` now also refuses, during Test requests, any SQL naming `payments`, `payment_allocations`, `payment_refunds`, `payment_sequences`, `payment_refund_sequences`, `commission_entries`, `finance_expected_collections`, `finance_expected_collection_matches`. Raw table names on Test-reachable paths were routed (`SalesOrderPaymentCalculator::summaries`, `ManageFinanceExpectedCollectionAction` capacity subquery).
- **Shared master data.** Financial accounts, stores, users and commission rule sets are shared and referenced, never copied. Account balances (`FinancialAccountBalanceService`) only read Production tables, so Test payments never change real account totals.
- **Numbering.** Test payments `TEST-PAY-000001`, Test refunds `TEST-REF-000001` (organization-wide Test document sequence); `payment_sequences` / `payment_refund_sequences` are never touched in Test Mode.

### Tables / migration

`database/migrations/2026_11_21_000005_create_sandbox_finance.php` — the six `sandbox_*` finance tables above: Production columns + `sandbox_id` (FK → `sandboxes`, RESTRICT), sandbox-qualified unique keys (`client_operation_id` idempotency, commission `source_key`), `(sandbox_id, id)` keys for composite FKs, master-data FKs to `financial_accounts`, `stores`, `sales_orders`, `users`, commission rule sets. **Rollback guard:** `down()` refuses while any Test finance row exists (dropping them would leave paid Test orders without payments).

### Supported Test payment flow

| Flow | Route | Behaviour |
|---|---|---|
| POS checkout | `pos.sales.store` | Confirm on Test stock → Test reservations; Test payments; Test commission entries. **Fulfillment is deferred** (audit `sales_order.pos_test_fulfillment_deferred`): the order ends confirmed + paid + unfulfilled. |
| Order payments | `sales.orders.payments.store` | Cash, card (TPE), bank transfer, cheque on shared accounts → Test ledger only |
| Payment list / detail / reversal | `payments.index`, `payments.show`, `payments.reverse` | Test payments only in Test Mode |
| Refund | `sales.orders.cancel` | Cancelling a paid Test order refunds every Test payment (`TEST-REF-…`), reverses Test commissions, releases Test stock reservations |
| Expected collections | `finance.monthly-situation.expected-collections.store / update / cancel / match` | Test collections against Test orders, capacity computed from Test payments |
| Commissions | (confirmation / cancellation) | Generated and reversed on `sandbox_commission_entries` |

UI: `SandboxPaymentBadge` shows **PAIEMENT TEST / SIMULÉ** on the POS payment step, the payments list and detail, and the order payment history; the global Test banner states that payments are simulated.

### Reports exclusions

Production finance pages and reports — journal, CA encaissé, créances, monthly situation / report, treasury forecast, executive dashboards, commission dashboard / ledger, financial account balances, exports — read only Production tables. They remain **outside the Test allowlist** (Production-only views) and are proven not to show Test numbers or Test orders after Test activity.

### Remaining blocked domains

Fulfillment / hand-over / shipping (`FulfillSalesOrderAction` refuses Test orders outright), returns and exchanges (and their refunds / credit notes), procurement and supplier receipts, transfer requests, financial account management and opening balances, finance report pages, outgoing (supplier) payments, integrations, sandbox reset.

### Validation results

- `SandboxFinanceTest` (4 tests / 153 assertions): Test POS checkout on Test stock + Test payment (order confirmed + paid, fulfillment deferred, `TEST-PAY-`, Test reservation held) with the Production finance ledger and the cash / bank / TPE account positions identical afterwards, while a Production checkout right after keeps its exact behaviour (`PAY-000001`, fulfilled, stock and positions change); Test bank + TPE payments, Test expected collections (capacity on Test payments), Test commission generation and reversal, Test refunds via cancellation — Production ledger unchanged, Production journal service and pages (journal, CA encaissé, créances, commission ledger / dashboard, payments) free of Test data; raw SQL on Production finance tables refused in Test; Test payment reversal stays on the Test ledger; Test session cannot pay / see a Production order or payment and vice versa (HTTP + direct action + model guard); store scope; another tenant's sandbox sees nothing; rollback guard.
- Full Sandbox suite (Phases 1–5): **20 tests / 691 assertions passed**.
- Finance (incl. commission dashboard / ledger / rule engine), Payments, Dashboards, MCP, Settings, Attention, Documents: **721 / 721 passed (5,538 assertions)**. A first run executed concurrently with the second batch showed one document-rendering image assertion failing; it did not reproduce in the full sequential re-run (likely shared render/storage interference between the parallel runs).
- POS, Sales, Quotations, Inventory, Returns, Exchanges, Fulfillment, Procurement, Security, Tenancy, Policies: **878 / 879 passed (6,564 assertions)** — the single failure is the pre-existing SQLite-only `CompletedTransfersVisibilityTest` (see Phase 4).
- **Exact commit content** (HEAD + staged diff applied in a clean worktree, i.e. without unrelated local work): Sandbox + POS + Payments + Sales **336 / 336 passed (2,873 assertions)**.
- `tsc --noEmit` / `vite build`: passed. Pint passes on every file Phase 5 created or made fail.

### Known limitations before Phase 6

- Test POS pickup sales stop before fulfillment (hand-over not sandboxed): Test reservations stay active until a Test cancellation.
- Returns / exchanges (and return refunds, credit notes, return commission reversals) are not sandboxed.
- Finance report pages are Production-only; Test finance is visible on Test orders, the Test payments list and Test expected collections only.
- Audit-log entries of Test finance operations are written to the normal audit trail, referencing Test ledger ids.
- No sandbox reset / purge; `RESTRICT` FKs keep Test rows.

## Phase 4 — Sandbox inventory isolation

### Status

**Complete** (inventory scope). Test Mode has its own stock: balances, immutable movements, reservations, sales allocations and warehouse-to-warehouse transfers live in physically separate `sandbox_*` tables. POS availability, cart allocations, shortage checks, stock IN/OUT, reservations (create / release / consume), transfers and **Test order confirmation** all run on Test stock. A Test operation never reads or changes Production stock.

### Chosen inventory architecture — environment-routed ledger

- **Separate tables, same models.** `InventoryBalance`, `InventoryMovement`, `InventoryReservation`, `SalesOrderInventoryAllocation`, `StockTransfer` and `StockTransferLine` use the `RoutesToSandboxInventory` trait: in Production they use their normal table; while a Test context is active they use `sandbox_inventory_balances`, `sandbox_inventory_movements`, `sandbox_inventory_reservations`, `sandbox_sales_order_inventory_allocations`, `sandbox_stock_transfers`, `sandbox_stock_transfer_lines`, scoped to the active sandbox (`sandbox_id`).
  - All existing logic — `InventoryBalanceLocker` (LockedRow + `FOR UPDATE`), `InventoryReservationManager`, `PosStockAllocator`, `TransferInventoryAction` / `CreateStockTransferAction`, `AdjustInventoryAction`, `ConfirmSalesOrderAction` — runs unchanged on the Test ledger. No inventory rule is duplicated or weakened.
  - A hydrated row keeps the table it was loaded from. Every insert/update/delete is checked: a sandbox-table row is only writable from its own Test context; a Production row is never writable from a Test (or invalidated) context.
- **Immutability.** Test movements use the same `InventoryMovement` model, so the same "movements are immutable" guards apply. The Production ledger and its rules are untouched.
- **Master data is shared, never duplicated.** Warehouses and product variants are referenced through composite `(organization_id, id)` FKs.
- **Cross-environment references are impossible.** Internal references on the Test ledger are composite `(sandbox_id, id)` FKs (allocation → reservation, transfer line → transfer / movements). A Test allocation therefore cannot reference a Production reservation, and a Production allocation (FK to `inventory_reservations`) cannot reference a Test reservation. `SalesOrderInventoryAllocation` additionally refuses an allocation whose order line belongs to the other environment (404).
- **Fail-closed SQL guard.** `SandboxInventoryGuard` hooks every database connection (`beforeExecuting`, registered for existing and newly established connections). During a Test request, any SQL statement naming a Production inventory table (`inventory_balances`, `inventory_movements`, `inventory_reservations`, `sales_order_inventory_allocations`, `stock_transfers`, `stock_transfer_lines`, `stock_transfer_sequences`) is refused before execution — raw query builder included. Only the initialization snapshot reads Production balances, inside an explicit `bypass()`. Production requests are never affected; Production stock calculators never include `sandbox_*` tables.
- **Raw qualifiers routed.** Code that qualifies inventory columns by table name now uses `Model::environmentTable()`: POS product availability (`PosController`), stock page (`InventoryStockController`), order origin allocations (`SalesOrderController`, `SalesOrder::reservedOutsideShowroom`), `FulfillmentEngine` reserved quantities.

### Initialization strategy — explicit snapshot, once per sandbox

- Taken when Test Mode starts (`POST /test-mode/enter`) and, for sandboxes created before Phase 4, on their first Test request (`ResolveSandboxContext`). Implemented by `SandboxInventoryInitializer::ensure()`.
- Copies Production `on_hand` per (warehouse, variant) with a single `INSERT … SELECT`; Test `reserved` starts at **0** (Production reservations belong to Production orders that do not exist in the sandbox). One `opening` movement per copied balance (`reference_type = sandbox_initialization`) keeps the Test ledger reconcilable.
- Race-safe and idempotent: the sandbox row is locked `FOR UPDATE`, `sandboxes.inventory_initialized_at` is re-checked under the lock, and the unique `(sandbox_id, organization_id, warehouse_id, product_variant_id)` key backs it up.
- **After the snapshot the sandbox evolves independently**: later Production movements never rewrite Test balances. Stock for a warehouse/variant created after the snapshot starts at 0 in Test (add it with a Test stock-in). Reasoning: one deterministic point in time, no read path ever falls back to Production, and no silent drift between environments.

### Tables / migration

`database/migrations/2026_11_21_000004_create_sandbox_inventory.php`

- `sandboxes.inventory_initialized_at`.
- `sandbox_inventory_balances`, `sandbox_inventory_movements`, `sandbox_inventory_reservations`, `sandbox_sales_order_inventory_allocations`, `sandbox_stock_transfers`, `sandbox_stock_transfer_lines` — Production columns + `sandbox_id` (FK → `sandboxes`, RESTRICT), sandbox-qualified unique keys and `(sandbox_id, id)` keys for composite FKs.
- **Rollback guard:** `down()` refuses while any Test reservation, allocation, transfer, non-initialization movement or confirmed Test order exists (the initialization snapshot alone is derived data and may be dropped). Verified on SQLite (test) and MySQL (rollback + re-apply with no activity).

### Supported Test inventory operations

| Operation | Route | Notes |
|---|---|---|
| Stock view / movements | `inventory.stock.index`, `inventory.movements.index` | Shows Test stock with a **Stock test** banner |
| Stock IN / OUT | `inventory.adjustments.store` | `adjustment_in` / `adjustment_out` on the Test ledger |
| Reservation create / release / consume | `inventory.reservations.store` / `.release` / `.consume` | Consume = Test stock OUT with movement |
| Warehouse transfer | `inventory.transfers.index` / `.create` / `.store` / `.show` | `TEST-TRF-000001` (Test document sequence); never touches `stock_transfer_sequences`; same warehouse/store rules |
| POS availability & cart | POS routes (Phase 2) | Availability, cart allocations and shortage use Test stock |
| Sales order confirmation | `sales.orders.confirm` | Allocations + reservations on Test stock; commissions and transfer requests are **not** generated |
| Documents (Phase 3) | unchanged | Test invoice / BL from a draft **or confirmed** Test order; BL issue stays document-only (no stock change) |

### Still blocked

Payments / finance, POS checkout (needs payments), order cancellation, fulfillment / hand-over / shipping (stock leaving through fulfillment), returns / refunds, procurement and supplier receipts, transfer requests (prepare / ship / receive), opening stock, warehouse management, integrations (WooCommerce stock sync), sandbox reset. All remain outside the route allowlist (`403 SANDBOX_DOMAIN_UNAVAILABLE`).

### UI

`SandboxStockBadge`: a **Stock test** banner on the stock, movements and transfer pages and a "Stock test" tag on POS product stock, rendered only in Test Mode (Production displays unchanged).

### Validation results

- `SandboxInventoryTest` (5 tests / 144 assertions): snapshot once + independence after Production changes; same product/warehouse with different Production and Test quantities; Test reservation / release / stock OUT / stock IN / transfer / consume change only `sandbox_*` rows; Production ledger (balances, movements, reservations, allocations, transfers, transfer sequence) byte-identical afterwards; Test movements immutable; raw SQL on Production inventory refused in Test; POS availability Test vs Production (5 vs 4); Test cart allocations and confirmation reservations on the Test ledger; shortage computed on Test stock; no commissions / transfer requests; Phase 3 invoice/BL still work and BL issue moves no stock; cross-environment allocation rejected both ways (composite FKs + model guard); Production code cannot write the sandbox ledger; tenant isolation (another organization's sandbox only sees its own Test stock, cannot use foreign warehouses); rollback guard.
- Full Sandbox suite (Phases 1–4), SQLite: **16 tests / 537 assertions passed**.
- **MySQL** (`commerce_erp_testing`): Sandbox + Inventory suites **169 tests / 1,601 assertions passed**.
- Inventory, POS, Sales, Quotations, Returns, Exchanges, Fulfillment, Procurement (SQLite): **700 / 701 passed (5,412 assertions)**. The single failure, `CompletedTransfersVisibilityTest::test_historical_transfer_whose_record_was_removed_is_rebuilt_from_its_movements`, is **pre-existing**: it fails identically at the previous commit without any Phase 4 code (SQLite returns raw decimals as `4` instead of `4.0000`) and passes on MySQL.
- Documents, Security, Tenancy, Policies, Finance, Dashboards, Settings, MCP, Payments, Catalog, Integrations, Attention, Platform: **1,054 tests / 7,589 assertions passed**.
- `tsc --noEmit` and `vite build` passed; Pint passes on every file Phase 4 created or made fail.

### Concurrency / MySQL notes

- `tests/Concurrency/sandbox_inventory_race.php` (new, 8 separate MySQL sessions, `commerce_erp_dev_test`): **reserve** (exactly 5 of 8 Test reservations succeed on a Test balance of 5 — no oversell), **first-use** (concurrent create-and-lock of a missing Test balance row — no lost update), **mixed** (Production and Test sessions reserving the same warehouse/variant simultaneously — each ledger only sees its own reservations) — all PASS, **InnoDB deadlocks +0**.
- Existing Production races still pass on the same server: `number_sequence_race.php` (SalesOrder, Invoice, DeliveryNote, StockTransfer, InventoryBalance; first-use + existing) PASS, deadlocks +0; `transfer_priority_race.php` 24 rounds PASS, deadlocks +0, lock-wait timeouts +0.
- The Test ledger uses the same lock order and the same `LockedRow` create-and-lock protocol as Production (`INSERT … ON DUPLICATE KEY UPDATE` on MySQL), with the sandbox-qualified unique key as the claim identity.
- The race harness forces the request-scoped Test context in CLI child processes from the script itself; no test-only hook exists in application code.

### Known limitations before Phase 5

- Payments are not sandboxed: POS checkout, settlement-dependent invoice rules and refunds stay blocked.
- No Test fulfillment: reservations of confirmed Test orders are only consumed/released manually; hand-over/shipping is blocked. Cancelling a confirmed Test order is blocked (it would need reservation release + finance effects).
- Transfer requests, procurement receipts and replenishment are Production-only; Test transfers skip the open-transfer-request "promise" check.
- Test stock for warehouses/variants created after the snapshot starts at 0.
- No sandbox reset/purge; `RESTRICT` FKs keep sandbox rows until a reset design exists.
- Audit-log entries of Test inventory operations go to the normal audit trail, referencing Test ledger ids.

## Phase 3 — Sandbox documents & Test numbering

### Status

**Complete** (document-only scope). Test invoices and Test Delivery Notes can be created from a Phase 2 Test draft order, edited as drafts, cancelled, issued with a non-official `TEST-` reference and rendered/printed/downloaded with an unmistakable Test marking. Credit Notes are environment-isolated and render-marked, but **no Test Credit Note can be created yet** (see blocked workflows). Production numbering, documents and PDFs are unchanged.

### Architecture

- **Explicit ownership.** `invoice_families`, `invoices`, `delivery_notes` and `credit_notes` carry `sandbox_id` (NULL = Production) and use `ScopesToSandbox`. Line tables (`invoice_lines`, `delivery_note_lines`, `credit_note_lines`) are scoped through their parent (`whereHas`). Invoice families are owned explicitly — isolation never relies on the `TEST-` string alone.
- **`ScopesToSandbox` (extended).** Per-model `sandboxStatuses()` (statuses a Test row may be persisted in; `null` = no status) and `sandboxLinks()` (column → root table). On every save, each linked root (`sales_order_id`, `invoice_family_id`, `corrected_invoice_id`, `invoice_id`) must exist in the same organization **and** the same environment, otherwise 404. The guard now uses the request environment for new rows (`saving` fires before `creating`), which also closes a Phase 2 gap where a brand-new Test row skipped the status check.
- **Authorization (defense in depth).** Route allowlist (`SandboxDocumentRules::ROUTES`) → policies (`InvoicePolicy`, `DeliveryNotePolicy`, `CreditNotePolicy` via `SandboxDocumentRules::allows`) → document action trait (`AuthorizesDocumentAction::authorizeDocumentEnvironment`: wrong environment = 404, Test permission outside `SandboxDocumentRules::PERMISSIONS` = 403) → model guards.
- **Raw queries.** Every Production `DB::table()` / subquery rooted on `invoices`, `invoice_families` or `credit_notes` carries an explicit `<table>.sandbox_id IS NULL` (finance journal, CA encaissé, receivables, monthly report/accounting exports, invoice read model, attention detector, numbering). Joined document tables hang off an already-filtered root.
- **Backups.** Production backup export excludes Test invoice families, invoices, delivery notes, credit notes and their lines (generic root/child map in `OrganizationBackupExporter`). `sandbox_document_sequences` is not part of the backup schema.

### Migration

`database/migrations/2026_11_21_000003_isolate_sandbox_documents.php`

- Nullable `sandbox_id` (FK → `sandboxes`, `ON DELETE RESTRICT`) + `{table}_environment_idx (organization_id, sandbox_id)` on `invoice_families`, `invoices`, `delivery_notes`, `credit_notes`.
- New `sandbox_document_sequences (organization_id, document_type, next_number)` with unique `(organization_id, document_type)`.
- Existing rows stay Production. **Rollback guard:** `down()` throws and drops nothing while any Test document exists (covered by `SandboxDocumentsTest::test_rollback_refuses_to_drop_document_isolation_while_test_documents_exist`).

### Test numbering strategy

- `App\Services\Sandbox\SandboxDocumentNumberGenerator` allocates `TEST-FAC-000001`, `TEST-BL-000001`, `TEST-AV-000001` from locked rows in `sandbox_document_sequences` (same `LockedRow` + `lockForUpdate` pattern as Production; transaction required; refuses outside Test Mode).
- Counters are per **organization + document type**, not per sandbox: a future recreated sandbox can never reissue a reference that collides with the per-organization unique indexes on document numbers.
- Production generators are untouched in behavior and now **refuse to run in Test Mode** (`InvoiceNumberGenerator::next`, `DeliveryNoteNumberGenerator::next`, `CreditNoteNumberGenerator::next` throw `LogicException`). `InvoiceNumberGenerator::maxAllocatedNumber()` filters `invoice_families.sandbox_id IS NULL`, so Test families can never influence the next official number or the "next number" settings.
- Test invoices create a Test-owned invoice family; its canonical reference is the `TEST-FAC-` number. A later Test version of the same family (order lines changed after issue) reuses that Test reference and supersedes the previous Test version — never a Production family.

### Supported Test document workflows

From a **Test draft** Sales Order (Phase 2 orders cannot be confirmed):

- **Invoice:** create draft (`POST /sales/orders/{order}/invoices`), edit draft, cancel draft, issue (`TEST-FAC-…`), view, list, print (HTML), PDF, download. Issuance has no payment gate and touches no payment row (payments are not sandboxed).
- **Delivery Note:** create draft, edit draft, cancel draft, issue (`TEST-BL-…`), view, list, print, PDF, download. Issuance is document-only: no fulfillment, hand-over, reservation consumption or inventory movement; the order stays `draft` / `unfulfilled`.
- **Credit Note:** view / print / PDF of an existing Test credit note only (none can be created yet).

### Blocked workflows

E-mail (invoice and BL), stamp (cachet), signed share links (`invoices.shared-pdf`, and no share link is generated for Test invoices), invoice corrections and correction lines, cross-store document opener, finance exports, every credit-note creation (Test credit notes are refused at the model level because they require a received customer return — stock, refund and commission effects), confirmation, payments, fulfillment, returns, procurement. Routes outside the allowlist return `403 SANDBOX_DOMAIN_UNAVAILABLE` (JSON) or redirect to `/test-mode` (GET).

### PDF / document rendering

- Same templates and renderers; no duplicated rendering system. Renderers call `SandboxDocumentMarking::apply()` only for sandbox-owned documents; it adds a `sandbox` payload key, suffixes the title with `(TEST)`, removes the official stamp and the status watermark.
- `documents/partials/sandbox-banner.blade.php` (included by the invoice, delivery-note and credit-note templates only when `sandbox` is set) draws a diagonal **DOCUMENT DE TEST** mark repeated on every page and a banner **DOCUMENT DE TEST — NON COMPTABLE — SANS VALEUR LÉGALE NI FISCALE** with the Test reference and status.
- Production payloads never contain `sandbox`, so the include is skipped and Production output is unchanged. Asserted: no Test markers on Production prints, identical Production HTML before/after Test documents exist, and the existing document rendering/PDF regression tests pass unchanged.

### UI

`SandboxDocumentBadge` ("TEST · NON COMPTABLE") on invoice, delivery-note and credit-note detail pages and the invoice / BL lists; the order page offers invoice / BL creation for a Test draft order; sharing, e-mail and stamp actions are hidden by policy for Test documents. The global Test banner (Phase 2) stays visible.

### Validation results

- `SandboxDocumentsTest` (5 tests) proves: Test invoices/BL never advance `invoice_sequences`, `delivery_note_sequences` or `credit_note_sequences`; Production `invoice_families` and `InvoiceNumberGenerator::settings()` unchanged after three Test invoices; Production continues at `2/YYYY` and `DN-000002`; official generators throw in Test Mode; Test families are sandbox-owned; mutual invisibility of invoices, BL and credit notes (show, PDF, index, Eloquent, lines) in both directions; cross-environment links rejected at action, route-binding and model level; store and tenant isolation; Test print/PDF marked with DOCUMENT DE TEST / NON COMPTABLE / TEST reference; Production render without markers and unchanged; e-mail, stamp, corrections, shared link, finance export, confirm, payments, fulfillment, returns blocked; Test credit note persistence refused; zero inventory movements / reservations / payments; rollback guard.
- Sandbox suite: 11 tests passed (Phase 1–3), within the final Documents + Sandbox run: **154 tests / 1,219 assertions passed**.
- Quotations, Sales, POS, Returns, Security, Tenancy, Policies, Exchanges: **581 tests / 4,140 assertions passed**.
- Finance, Dashboards, Settings (backup/reset), MCP, Payments, Attention, Fulfillment, Timeline: **609 tests / 5,085 assertions passed** (run before the final link-check refinement, which only removed an abort path and was re-validated by the two runs above).
- `tsc --noEmit` passed; `vite build` passed; Pint passes on every file Phase 3 created or made fail (files already failing before Phase 3 left unformatted).

### Known limitations before Phase 4

- Credit Notes cannot be produced in Test Mode until customer returns (and their stock/refund/commission effects) are sandboxed.
- Test invoices are issued from draft orders without payment settlement; no Test payment, receivable or finance view exists.
- Test documents cannot be e-mailed, stamped or shared; Test invoice corrections from commercial revisions are unavailable (revisions are not sandboxed).
- Audit-log entries for Test documents are written to the normal audit trail (traceability), referencing Test entity ids.
- No Sandbox reset/purge yet; `RESTRICT` FKs make deleting a sandbox with documents fail. The organization data reset/restore still needs an explicit design for Test rows.
- Raw queries rooted on line tables must join and filter the parent document.

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
