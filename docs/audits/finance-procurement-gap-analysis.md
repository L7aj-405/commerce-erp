# 10xScale ERP — Finance & Procurement Functional Gap Analysis

- **Type:** Read-only audit (no source, migration, database or configuration changes)
- **Date:** 2026-11-04
- **Baseline:** `main` @ `24c58d9`
- **Method:** Inspection of migrations, models, actions, services, controllers, routes, Inertia pages, MCP tool registrars, permissions and feature tests; execution of the Finance / Procurement / Payments / Invoice-lifecycle test subset on in-memory SQLite.

Status vocabulary used throughout:

| Status | Meaning |
|---|---|
| **Complete** | Implemented end-to-end (UI → action → DB) with tests, fit for the stated scope |
| **Partial** | Exists but covers only part of the business need, or is not linked to adjacent domains |
| **Broken** | Exists but produces wrong / unusable results in a realistic scenario |
| **Missing** | No implementation found (searched schema, code and routes) |

---

## Executive summary

10xScale has a **well-engineered customer-side cash cycle** (orders, payments, refunds, invoices with immutable snapshots and versioned corrections, credit notes from returns, receivables reporting, accountant exports) and a **solid treasury-planning module** ("Situation fin de mois": obligations, outgoing payments, supplier installment schedules, cheques/traites lifecycle, expected collections, opening balances, month review/close). Engineering quality is high: tenant-safe composite foreign keys, `lockForUpdate()` on every race-sensitive transition, locked sequence rows for numbering, idempotency keys on payments/POs/receipts, decimal-string arithmetic and audit logging.

However, **Procurement is a back-to-back "special order" module, not a purchasing module, and there is no Accounts Payable at all.** The supplier model's own docblock states it is *"Deliberately NOT Accounts Payable — no balances, terms or ledgers."* Concretely:

1. **No supplier invoice entity.** Nothing records what a supplier billed. `sales_order_procurements.supplier_invoice_reference` exists but is never read or written.
2. **Purchase orders can only be created from customer Sales Order requirements.** `purchase_order_lines.sales_order_procurement_id` and `sales_order_line_id` are NOT NULL. Stock replenishment purchasing is impossible.
3. **Purchase orders carry no prices.** PO `unit_price` is copied from `sales_order_procurements.supplier_unit_cost`, which no code anywhere writes, so every app-created PO line has a NULL price. There is no PO total, no tax, and no commitment value.
4. **No three-way matching, no supplier returns, no supplier credit notes, no supplier balance or statement.**
5. **Supplier payables are typed in by hand** as generic `finance_obligations` (category `supplier`) or installment plans, with no link to POs, receipts or invoices beyond an optional procurement id.
6. **Customer invoices can only be issued once the order is fully paid** (`IssueInvoiceAction` L70–73, enforced by tests). That blocks standard Moroccan B2B practice: invoice at delivery, payment at 30/60/90 days, often by LCN (traite). It also conflicts with the newer invoice `due_date` and overdue-attention features.
7. **Moroccan identifiers are free text** (no ICE format validation), customers have a single `tax_identifier`, and **suppliers have no ICE / IF / RC / RIB fields at all**. Payment terms are one global env value (`INVOICE_PAYMENT_TERM_DAYS`), not per customer or supplier.
8. **There is no general ledger, chart of accounts (PCM), purchase journal, deductible-VAT tracking or bank reconciliation.** Accounting output is limited to sales-side Excel/PDF/ZIP packages.

**Recommended first sprint:** build the **Supplier Invoice (AP) foundation**. That means supplier fiscal and terms fields, priced PO lines, a `supplier_invoices` entity with lines and attachments matched against PO and receipt quantities, auto-generated payables obligations, and a supplier balance/statement. Ship it with a P0 fix that allows invoicing a confirmed order before payment (behind an organization setting). See §G.

---

## A. Current architecture

### A.1 Domain map

```
                PROCUREMENT (back-to-back special orders)                       FINANCE — PAYABLES / TREASURY
Quotation / POS cart ──► QuotationSupplierAvailabilityRequest                  FinanceObligation (manual, typed: supplier, salary,
        │                        (supplier confirms qty, no price)             CNSS, tax, rent, …)  ◄── FinanceRecurringObligationTemplate
        ▼                                                                        ▲            ▲
SalesOrder ─► SalesOrderProcurement (APV-xxxxxx, requirement per SO line)        │            └── FinanceSupplierInstallmentPlan (échéancier)
        │                │                                                       │
        │                ▼                                                       │ allocations
        │        PurchaseOrder (BCF-xxxxxx) + PurchaseOrderLine                FinanceOutgoingPayment ◄── FinancePaymentInstrument
        │                │   (line ↔ requirement ↔ SO line, NOT NULL)                │                     (chèque / traite: issued →
        │                ▼                                                       │                      presented → cleared/returned)
        │        ProcurementReceipt ─► InventoryMovement(supplier_receipt)       ▼
        │                          ─► InventoryReservation (earmarked for SO)  FinancialAccount (+ opening position) ─► balance service
        │                          ─► TransferRequest (cross-dock onward)        ▲
        ▼                                                                        │
CUSTOMER CASH CYCLE                                                              │
SalesOrder ─► Payment ─► PaymentAllocation (1 payment → 1 order) ───────────────┘
        │          └──► PaymentRefund
        ├──► Invoice (N/YYYY, InvoiceFamily versions, Superseded on correction)  — issuable only when fully paid
        ├──► CustomerReturn ─► CreditNote (+ lines)
        └──► FinanceExpectedCollection ─► matches PaymentAllocation (treasury forecast)

Supplier master: suppliers (name, contact, phone, email, address, notes, active)
                 organization_contacts (type=supplier, many per supplier)
                 product_variant_suppliers (catalog: SKU, price, MOQ, multiple, lead time, preferred, ratings)
                 product_suppliers (older product-level "remembered" suppliers — still used by quotation sourcing)
```

### A.2 Tables (finance & procurement relevant)

| Area | Tables | Migration |
|---|---|---|
| Suppliers | `suppliers`, `organization_contacts.supplier_id`, `product_variant_suppliers`, `product_suppliers` | 2026_09_16_000001, 2026_09_28_000001, 2026_11_08_000001 |
| Procurement | `sales_order_procurements`, `procurement_sequences`, `purchase_orders`, `purchase_order_lines`, `purchase_order_sequences`, `procurement_receipts`, `quotation_supplier_availability_requests`, `out_of_stock_articles` | 2026_09_16_000001, 2026_11_05/06/07/12/17 |
| Customer cash | `financial_accounts`, `payments`, `payment_allocations`, `payment_refunds`, `payment_sequences`, `payment_refund_sequences` | 2026_08_23_000005, 2026_09_24_000001 |
| Documents | `invoices`, `invoice_lines`, `invoice_families`, `credit_notes`, `credit_note_lines`, `customer_returns` | 2026_08_23_000006, 2026_09_09/10/23/26, 2026_11_01 |
| Payables / treasury | `finance_obligations`, `finance_outgoing_payments`, `finance_outgoing_payment_allocations`, `finance_supplier_installment_plans`, `finance_payment_instruments`, `finance_recurring_obligation_templates`, `finance_expected_collections`, `finance_expected_collection_matches`, `financial_account_opening_positions`, `finance_month_reviews`, plus five yearly sequence tables | 2026_10_17_000001…000006 |
| Tax | `tax_rates` (org-scoped, name + rate, one default) | 2026_08_23_000002 |

### A.3 Actions and services

| Layer | Procurement | Finance / Payments / Documents |
|---|---|---|
| **Actions** | `SaveSupplierAction`, `SaveProductVariantSupplierAction`, `CreateProcurementAction`, `DeriveSalesOrderProcurementRequirementsAction`, `RecordSupplierAvailabilityAction`, `ChangeProcurementSupplierAction`, `OrderProcurementAction`, `CreatePurchaseOrderFromProcurementRequirementsAction`, `CancelPurchaseOrderAction`, `ReceiveProcurementAction`, `CancelProcurementAction`, `Report/ResolveOutOfStockArticleAction` | `ManageFinanceObligationAction`, `RecordFinanceOutgoingPaymentAction`, `ReverseFinanceOutgoingPaymentAction`, `ManageFinanceSupplierInstallmentPlanAction`, `ManageFinancePaymentInstrumentAction`, `ManageFinanceRecurringTemplateAction`, `GenerateRecurringFinanceObligationsAction`, `ManageFinanceExpectedCollectionAction`, `ManageFinanceMonthReviewAction`, `SetFinancialAccountOpeningPositionAction`, `RecordPaymentAction`, `RefundPaymentAction`, `ReversePaymentAction`, `ManageFinancialAccountAction`, `Create/Issue/UpdateInvoice*`, `CreateCustomerReturnAction` (creates credit notes) |
| **Services** | `SalesOrderProcurementPlanner`, `SupplierCatalogQuery`, `SupplierRecommendationService`, `SupplierOptionsQuery`, `PurchaseOrderNumberGenerator`, `ProcurementNumberGenerator` | `FinanceReceivablesService`, `FinanceMonthlyReportService`, `FinanceJournalService`, `FinanceCaEncaisseService`, `FinanceMonthlySituationService`, `FinanceTreasuryForecastService`, `FinancialAccountBalanceService`, `FinanceObligationCalculator`, `FinanceInstrumentCommitmentService`, `FinanceMonthReviewService`, `FinanceAccessGuard`, `SalesOrderPaymentCalculator`, `InvoiceNumberGenerator`, eleven exporters under `Finance/Export` |
| **MCP** | `create_supplier`, `update_supplier`, `search/get_supplier(s)`, `create_purchase_order`, `preview_purchase_order_creation`, `receive_procurement`, `preview_procurement_receipt`, supplier catalog & sourcing tools | `search/get_invoice(s)`, `issue_invoice`, `preview_invoice_issue`, `get_invoice_document`, `record_payment`, `preview_payment`, `search/get_payment(s)` — **no payables tools** |

### A.4 Routes and pages

- **Procurement** (`routes/web.php` L711–750): `procurement.index`, `supplier-requests.*`, `purchase-orders.index|show|cancel|lines.receive`, `suppliers.index|store|update|show`, `supplier-catalog.*`, `{procurement}/availability|supplier|order|receive|cancel`, `out-of-stock-articles.*`. PO creation only via `sales.orders.purchase-orders.store` (L706). Pages: `Procurement/Index`, `PurchaseOrders/{Index,Show}`, `Suppliers/{Index,Show}`, `SupplierRequests`, `OutOfStockArticles`.
- **Finance** (L754–823): `finance.index|ventes|facturation|encaissements|creances|journal|ca-encaisse`, `monthly-situation.*` (obligations, outgoing payments, recurring charges, supplier installments, instruments, expected collections, review/close/reopen), commissions, exports. Pages: `Finance/{Situation, Ventes, Facturation, Encaissements, Creances, Journal, CaEncaisse, MonthlySituation, RecurringObligations, SupplierInstallments, SupplierInstallmentShow, Commission*}`.
- **Payments / documents**: `financial-accounts.*`, `payments.index|show|reverse`, `sales.orders.payments.store`, `invoices.*` (index, show, print, pdf, email, stamp, issue, cancel-draft, corrections), `credit-notes.show|print|pdf`.
- **Not present:** supplier invoice routes, standalone PO creation, PO print/PDF/email, supplier return, supplier credit note, supplier statement, bank statement import / reconciliation, accounting export.

---

## B. Functional coverage matrix

Priority: **P0** blocker / financial correctness · **P1** essential workflow · **P2** differentiation · **P3** advanced.

| # | Feature | Status | Evidence | Gaps | Business impact | Prio |
|---|---|---|---|---|---|---|
| 1.1 | Supplier master | **Partial** | `suppliers` table; `SaveSupplierAction` rules: name, contact_person, phone, email, address, notes, active | No ICE, IF, RC, Patente, RIB/bank, currency, payment terms, credit limit, category or city fields. Update audit records new name/active only (no old values) | Cannot print compliant supplier documents, pay by transfer with a stored RIB, or apply supplier terms | P1 |
| 1.2 | Supplier contacts | **Complete** | `organization_contacts.supplier_id`, `SaveOrganizationContactAction` validates org | Single-column FK (tenant check lives in the action only) | Low | P3 |
| 1.3 | Supplier catalog (variant ↔ supplier terms) | **Complete** | `product_variant_suppliers` (price, MOQ, multiple, lead time, preferred unique, ratings); `SupplierCatalogTest` | Coexists with legacy `product_suppliers` (duplicate concept, used by quotation sourcing) | Two sources of truth for "who supplies what" | P2 |
| 2.1 | Back-to-back requirement (APV) | **Complete** | `SalesOrderProcurement`, status lifecycle, `SupplierProcurementTest`, `SalesOrderProcurementRequirementTest` | — | Strength: special-order sourcing | — |
| 2.2 | Purchase order (BCF) from SO requirements | **Partial** | `CreatePurchaseOrderFromProcurementRequirementsAction`; idempotent; quantity caps | Created directly as `ordered` (no draft, no approval). **No prices** (2.4). No tax or totals. No print/PDF/email to the supplier | PO is an internal tracker, not a commercial document | P1 |
| 2.3 | Standalone / replenishment PO | **Missing** | `purchase_order_lines.sales_order_procurement_id` NOT NULL (migration 2026_11_06 L68–70) | Cannot buy for stock, consolidate needs, or act on replenishment suggestions (`ShowroomReplenishmentPlanner` exists for transfers only) | **Core purchasing workflow absent** for a distributor or wholesaler | P0/P1 |
| 2.4 | PO line pricing | **Broken** | `poLine->unit_price = $requirement->supplier_unit_cost` (L172); `supplier_unit_cost` is never written anywhere in `app/`, `database/factories` or `tests/` | Every app-created PO line has a NULL price. `SupplierCatalogQuery` "last purchase price" is therefore always empty for new POs | No PO value, no cost basis for receipts, no matching | **P0** |
| 3.1 | Goods receipt, full / partial | **Complete** (for back-to-back) | `ReceiveProcurementAction`: locked balance, movement, reservation, receipt row, PO status refresh, stale-figure guard, idempotency; `SalesOrderProcurementReceiptTest` | Receipt only while the SO is draft/confirmed; no over-receipt tolerance; no cost recorded on the movement; no receipt document (BR) | Goods cannot be received once a customer cancels (operator must cancel the PO; the supplier may still deliver) | P1 |
| 3.2 | Receipt into general stock | **Missing** | Only movement type `supplier_receipt`, always reserved for an SO | — | Cannot receive replenishment purchases | P1 |
| 3.3 | Receipt reversal / correction | **Missing** | No action or route | Wrong receipt quantity can only be fixed via inventory adjustment, which breaks PO ↔ receipt traceability | Data-integrity risk | P1 |
| 3.4 | Supplier returns (retour fournisseur) | **Missing** | No movement type, table or route | — | Defective goods cannot be returned with traceability | P1 |
| 4.1 | Supplier invoice entity | **Missing** | No table or model; `supplier_invoice_reference` column unused | — | **No AP**: what is owed is not derived from what was billed | **P0** |
| 4.2 | Supplier invoice import / OCR | **Missing** | — | — | Differentiator later | P2 |
| 4.3 | Attachments (supplier invoice / BL scans) | **Missing** | Only `fulfillment_attachments` exists | — | Audit evidence lives outside the ERP | P1 |
| 5 | Three-way match PO ↔ receipt ↔ invoice | **Missing** | Requires 2.4 and 4.1 | — | Overbilling and price drift go undetected | P1 |
| 6.1 | Supplier credit notes (avoirs fournisseur) | **Missing** | — | — | Supplier balance overstated | P1 |
| 6.2 | Supplier disputes / discrepancies | **Missing** | — | — | — | P2 |
| 7.1 | Supplier installment schedules | **Complete** | `ManageFinanceSupplierInstallmentPlanAction`: sum must equal total; locked restructure; `FinanceSupplierInstallmentInstrumentTest` | Not derived from an invoice; restructure blocked even by *cancelled* instruments (L47) | Strong treasury tool, disconnected from AP | P1 |
| 7.2 | Partial outgoing payments, multi-obligation allocation | **Complete** | `RecordFinanceOutgoingPaymentAction`: exact allocation sum, commitment check, idempotency + unique-violation recovery | Payment date unrestricted (no closed-period guard) | — | P1 |
| 7.3 | Cheques / traites issued to suppliers | **Complete** | `ManageFinancePaymentInstrumentAction`: issued → presented → cleared/cancelled, returned on reversal | Clearing date is server `now()` in UTC, not a user-entered value date in the org timezone (L85) | Wrong value date around midnight; cannot back-record bank clearing | P1 |
| 7.4 | Payment terms (supplier and customer) | **Partial** | Global `INVOICE_PAYMENT_TERM_DAYS` (30) and `SALES_ORDER_PAYMENT_TERM_DAYS` (7) | No per-customer or per-supplier terms, no "fin de mois" or "60 jours FDM" rules | Due dates wrong for most B2B accounts | P1 |
| 8.1 | Supplier balance / AP ledger / statement | **Missing** | Supplier show page returns catalog only | Totals derivable only from manual obligations | Cannot answer "what do we owe X?" | **P0/P1** |
| 8.2 | AP aging | **Partial** | Monthly-situation status (upcoming / partial / overdue) per obligation | No aging buckets per supplier | — | P1 |
| 9.1 | Customer invoicing | **Partial** | Snapshot-immutable invoices, `N/YYYY` locked numbering, families/versions, TVA per rate, amount in words, stamp, email, signed share link | **Issuable only after full payment** (IssueInvoiceAction L69–73, `InvoiceLifecycleTest` L71/94). One invoice per order; no grouped / monthly invoicing | **Blocks B2B deferred-payment invoicing**, the target market | **P0** |
| 9.2 | AR / receivables | **Partial** | `FinanceReceivablesService` (order-based and invoice-based), Créances page, invoice attention | **Three different receivable definitions** (see D-7). Payments allocate to one order only, so one bank transfer cannot settle several invoices | Inconsistent figures across screens | P1 |
| 9.3 | Collections / dunning | **Partial** | `InvoiceAttentionDetector` + follow-ups linked to invoices | Overdue invoices are practically impossible while 9.1 holds; no reminder letters or customer statement | — | P1 |
| 9.4 | Customer credit notes | **Partial** | Created only from `CreateCustomerReturnAction` | No commercial credit note (price adjustment, rebate) without a physical return | — | P1 |
| 9.5 | Customer LCN / traite receivable | **Missing** | `PaymentMethod`: cash, card, bank_transfer, cheque | No traite/LCN; no post-dated cheque portfolio for incoming instruments | Very common in Moroccan B2B | P1 |
| 9.6 | Customer credit limit | **Missing** | — | — | Credit risk is uncontrolled | P2 |
| 10.1 | Cash-flow forecast | **Partial** | `FinanceTreasuryForecastService`: opening balance + manual expected collections − obligations, one month | Ignores open SO balances, invoice due dates and open POs; no multi-week horizon | — | P2 |
| 10.2 | Bank reconciliation | **Missing** | No statement import, no reconciled flag (only a commissions-ledger "reconcile") | — | Balances are book-only | P1 |
| 10.3 | Financial account balances | **Complete** | `FinancialAccountBalanceService` (opening + incoming − refunds − outgoing) | No internal transfers between accounts (cash → bank deposit) | Cash deposits cannot be recorded | P1 |
| 10.4 | Month review / close | **Partial** | `ManageFinanceMonthReviewAction` snapshots and detects post-close changes | **Close does not block postings** in closed months | Closed figures can silently change | P1 |
| 10.5 | Financial reporting | **Partial** | Ventes, Facturation, Encaissements, Créances, Journal, CA encaissé, situation exports (xlsx/pdf/zip) | Sales-side only; no purchases journal, P&L, margin by supplier or VAT summary | — | P1 |
| 11.1 | MAD currency | **Complete** | `currency_code` everywhere, default MAD, French number-to-words | Multi-currency purchasing has no exchange rates | Imports in EUR/USD not supported | P2 |
| 11.2 | ICE / IF / RC / TP on documents | **Partial** | Seller: `tax_identifier` (labelled ICE), `registration_number` (RC), `patente_number` (TP), free `additional_identifiers` (IF/CNSS). Customer: one `tax_identifier` labelled ICE on invoices and "Identifiant fiscal" on credit notes | No format validation (ICE = 15 digits), no separate customer IF/RC, nothing on suppliers | Compliance quality depends on manual entry | P1 |
| 11.3 | TVA | **Partial** | Org tax rates, per-line rate snapshot, per-rate totals on invoice | No deductible (purchase) VAT, no TVA declaration report, no encaissement/débit regime setting | Cannot prepare the VAT return | P1 |
| 11.4 | Accounting export (PCM) | **Missing** | No account mapping or journal entries | — | Accountant re-keys everything | P1 |
| 12.1 | Tenant isolation | **Complete** | Composite `(organization_id, id)` FKs with RESTRICT; actions re-query by org; `FinanceAccessGuard`; `ScopesToActiveOrganization` on finance models; `Tenancy` tests | Payment/Invoice/CreditNote/PO-line/receipt models lack the global scope (they rely on policies and explicit filters — verified for the reviewed paths) | — | — |
| 12.2 | Audit trail | **Partial** | `AuditLogger` on every mutation | Some events have no old values (`supplier.updated`); no approval trail | — | P2 |
| 12.3 | Segregation of duties / approvals | **Missing** | No PO approval, no payment maker-checker, no thresholds; `finance.outgoing_payments.manage` both posts and reverses | — | Fraud / error risk | P2 |

---

## C. End-to-end workflow analysis

Target flow: **Supplier → Purchase Order → Receipt → Invoice → Approval → Payment → Reconciliation**.

| Step | What actually happens today | UI → persistence trace | Break points |
|---|---|---|---|
| **0. Need** | A need appears only as a customer-order shortage: Quotation/POS sourcing → `QuotationSupplierAvailabilityRequest` (supplier confirms quantity, **no price captured**) → `SalesOrderProcurement` (APV) on SO confirmation/derivation | `QuotationSourcingController` / `PosController@storeSupplierRequest` → `RecordSupplierAvailabilityAction` → `DeriveSalesOrderProcurementRequirementsAction` → `sales_order_procurements` | No stock-driven need (min/max, reorder point) produces a purchase |
| **1. Supplier** | Selected per requirement (recommendation service uses catalog price, lead time, preferred flag) | `SupplierRecommendationService`, `ChangeProcurementSupplierAction` | Supplier has no terms or fiscal identity |
| **2. Purchase order** | "Créer la commande fournisseur" on the SO → BCF created directly as `ordered`; requirement → `ordered` | `sales.orders.purchase-orders.store` → `CreatePurchaseOrderFromProcurementRequirementsAction` → `purchase_orders`, `purchase_order_lines` | **No price, no total, no approval, no document sent to the supplier.** One PO per store; SO-bound lines only |
| **3. Receipt** | Per PO line, full or partial; stock in + immediate reservation for the SO; optional cross-dock transfer request | `procurement.purchase-orders.lines.receive` → `ReceiveProcurementAction` → `inventory_movements`, `inventory_balances`, `inventory_reservations`, `procurement_receipts`, PO/requirement statuses | No cost on the movement; no reversal; no return; refused if the SO is no longer open |
| **4. Supplier invoice** | **Does not exist.** The user may type a `reference` on a manual obligation | — | **Chain breaks here.** No link PO/receipt → amount owed |
| **5. Approval** | **Does not exist** (neither invoice approval nor payment approval) | — | — |
| **6. Payable** | Manual `FinanceObligation` (category `supplier`) or `FinanceSupplierInstallmentPlan` (optional `sales_order_procurement_id`) | `monthly-situation.obligations.store` / `installments.store` → `finance_obligations` | Amount, due date and supplier are free input; duplicates and omissions are undetectable |
| **7. Payment** | Outgoing payment allocated to obligations, or a cheque/traite instrument issued → presented → cleared (auto-creates the outgoing payment) | `monthly-situation.payments.store` → `RecordFinanceOutgoingPaymentAction`; `instruments.*` → `ManageFinancePaymentInstrumentAction` | Solid. Clearing date is UTC `now()`; no closed-period guard |
| **8. Reconciliation** | **Does not exist.** Book balance only, from the opening position | `FinancialAccountBalanceService` | No bank statement, no reconciled flag, no inter-account transfer |

**Customer-side mirror (Sales → AR):** Quotation → SalesOrder (confirm, reserve) → Fulfillment / Delivery note → **Payment(s)** (one order per payment) → **Invoice issuable only when the balance is 0** → Credit note only from a physical return → Refund. The **"payment before invoice" ordering is the inverse of B2B practice** and is the single largest functional blocker on the sales side.

**Cross-domain integration:**

| Integration | Status |
|---|---|
| Procurement → Inventory | ✅ Real movements and reservations, locked balances |
| Procurement → Sales | ✅ Requirements tied to SO lines; SO cancel blocked while ordered |
| Procurement → Finance | ❌ Only an optional `sales_order_procurement_id` on obligations and plans; no automatic payable |
| Inventory → Finance (valuation / COGS) | ❌ Margin uses a manual `product_variants.purchase_price` snapshot, not actual purchase cost |
| Sales → Finance | ✅ Payments, refunds, invoices and credit notes feed reporting (with the inconsistencies in D-7) |

---

## D. Critical risks

| ID | Risk | Category | Evidence | Severity |
|---|---|---|---|---|
| D-1 | **No AP source of truth.** Supplier debt is whatever someone typed into obligations; nothing reconciles it to POs or receipts | Accounting correctness | No supplier-invoice table; `Supplier` docblock | **Critical** |
| D-2 | **PO lines always unpriced** (`supplier_unit_cost` never written) | Data integrity | `CreatePurchaseOrder…Action` L172; grep shows no writer | **High** |
| D-3 | **Invoices blocked until full payment**, so B2B customers cannot receive an invoice to pay against. Invoice-level AR and overdue detection become near-dead code | Business correctness / regulatory | `IssueInvoiceAction` L69–73 | **High** (regulatory verification needed: invoicing timing and VAT regime) |
| D-4 | **Selective data reset can delete issued invoices, credit notes and posted payments** (categories `invoices` and `payments`; sequences are deliberately kept, so numbering gaps appear) | Data deletion / compliance | `SelectiveResetCategoryRegistry` L51–70 | **High** (owner-only and guarded, but deleting issued fiscal documents conflicts with sequential-numbering and retention obligations — verify) |
| D-5 | **Month close is advisory**: payments, obligations and instruments can still be posted or back-dated into a closed month; only "stale" detection afterwards | Accounting correctness | `ManageFinanceMonthReviewAction`; no closed-period check in `RecordFinanceOutgoingPaymentAction` / `RecordPaymentAction` | Medium-High |
| D-6 | **UTC "today" used for business dates**, violating AGENTS rule 8: payment backdate check (`RecordPaymentAction` L70), refund date (`RefundPaymentAction` L107), instrument clearing date (`ManageFinancePaymentInstrumentAction` L85), month-review "future month" check. Morocco is UTC+1 most of the year, so between 00:00 and 01:00 local a same-day payment counts as backdated (403 without `payments.backdate`), and refunds or clearings get yesterday's date | Correctness | file:line cited | Medium |
| D-7 | **Inconsistent receivable definitions**: (a) `salesOrderReceivablesAsOf` — order total minus net paid, includes returns; (b) `totalAsOf` — issued invoices minus credit notes minus paid; (c) the inline query in `FinanceMonthlySituationController` L63–67 — order total minus net paid, **ignores returns**, `limit(250)` | Reporting correctness | file:line cited | Medium |
| D-8 | **Unbounded in-memory aggregation**: `salesOrderReceivablesAsOf` chunks but accumulates all rows before paginating; MonthlySituation loads all matching payment allocations unpaginated (`->get()` L55) | Scalability (AGENTS rule 6) | file:line cited | Medium |
| D-9 | Installment-plan restructure **silently un-cancels** obligations (`cancelled_at = null`, L57) and keeps old obligation numbers when the due-date year changes | Audit / integrity | `ManageFinanceSupplierInstallmentPlanAction` L50–58 | Low-Medium |
| D-10 | **No segregation of duties**: one permission records and reverses outgoing payments; POs are created already "ordered"; no thresholds | Security / fraud | permissions list; PO action L151 | Medium |
| D-11 | Receipts **cannot be reversed**; mistakes require inventory adjustments that break PO ↔ receipt ↔ stock traceability | Data integrity | No reversal action | Medium |
| D-12 | Supplier update audit has **no old values** | Audit | `SaveSupplierAction` L62 | Low |
| D-13 | Stale documentation: `FinanceJournalService` docblock says no due-date column exists on Invoice, but `invoices.due_date` was added on 2026_11_01 | Maintainability | L20–27 | Low |
| D-14 | Two supplier-catalog concepts (`product_suppliers` vs `product_variant_suppliers`) | Architecture | migration 2026_11_08 header | Low |

**Positive controls observed (keep these):** composite tenant FKs with RESTRICT throughout; `lockForUpdate()` before every balance, sequence and status transition; locked yearly sequences (`N/YYYY`, `BCF-`, `APV-`, `OBL`/payment/instrument per year); idempotency keys plus unique-violation recovery on payments, POs and receipts; immutable invoice snapshots with versioned corrections (originals become `Superseded`, never edited); credit-note-aware invoice AR; exact-sum allocation checks; refunds can never exceed collections (`LogicException`); `withoutOverlapping()` on the recurring-obligation scheduler.

---

## E. Moroccan market readiness

| Topic | What exists | Needs improvement | Requires external (legal / accounting) verification |
|---|---|---|---|
| **Currency (MAD)** | Default MAD, `DECIMAL(19,4)`, French amount-in-words on invoice, quotation and credit note | FX for imported purchases (EUR/USD supplier invoices) | — |
| **ICE** | Seller ICE (`tax_identifier`) and customer ICE printed on invoices | 15-digit format validation; mandatory-for-B2B rule; supplier ICE field | Exact mandatory mentions on B2B invoices and on purchase documents |
| **IF, RC, TP (Patente), CNSS** | Seller RC and TP fields; IF and CNSS only as free "additional identifiers" | Dedicated IF field; customer and supplier IF/RC | Which identifiers are mandatory on invoices, and for which taxpayer types |
| **TVA** | Org-defined rates, per-line rate snapshot, per-rate summary on invoice, line-level Excel export | Deductible VAT on purchases (needs supplier invoices); VAT summary per period; "régime des encaissements vs débits" setting; prorata | Applicable rates by product category, regime rules, filing format (e.g. SIMPL-TVA / "relevé des déductions") |
| **Invoice numbering and immutability** | Locked sequential `N/YYYY`, immutable snapshots, superseded versions, stamping | Prevent deletion of issued documents by data reset (D-4) | Whether a re-issued correction may reuse the original number vs. requiring a credit note plus a new invoice; retention period |
| **Invoice timing** | Only after full payment (D-3) | Allow invoice at delivery / confirmation | Legal obligation on when an invoice must be issued for B2B sales |
| **Payment terms (délais de paiement)** | Global 30-day invoice term, 7-day order term | Per-customer and per-supplier terms, end-of-month rules, aging, statement | **Law 69-21 (amending law 15-95)**: maximum terms, default term, late-payment penalties, any periodic declaration obligations — verify current thresholds and reporting duties with a Moroccan accountant |
| **Payment instruments** | Outgoing cheques/traites with lifecycle | Incoming LCN/traites and post-dated cheques from customers; returned-cheque (impayé) handling on the customer side | Mentions required on LCN and cheque-related bookkeeping |
| **Cash payments** | Cash method on payments | Cash-limit warnings | Any legal cap on cash settlements and related tax treatment |
| **Accounting export** | Sales journal and CA-encaissé packages (xlsx / pdf / zip) | Mapping to the Moroccan chart of accounts (PCM classes 3/4/5/6/7, e.g. 3421 customers, 4411 suppliers, 4455/3455 VAT), journal-entry CSV for common local accounting software | Target software import formats; accountant validation of the account mapping |
| **Withholding (retenue à la source)** | — | Possibly needed on some supplier payments | Applicability to the target SME segment |

**Claim boundary:** this audit does **not** establish compliance with any Moroccan regulation. The items above mark where evidence is needed.

---

## F. Prioritized roadmap

### P0 — Blockers and financial correctness

1. **Supplier Invoice (AP) foundation**: `supplier_invoices` + lines + attachments, numbered internally, with the supplier's own invoice number unique per supplier; status `draft → validated → (partially) paid → cancelled`; validation auto-generates the payable `FinanceObligation`(s), honoring supplier terms or an installment plan.
2. **Priced purchase orders**: capture unit price (HT), tax rate and currency on PO lines (prefill from catalog / last price); PO totals; fix the dead `supplier_unit_cost` path (remove it or write it).
3. **Invoice before payment** for confirmed B2B orders, behind an organization setting (keep current behavior as the default for POS/B2C). Due date from customer terms.
4. **Closed-period guard**: refuse or require a privileged override for postings dated inside a closed month (customer payments, refunds, outgoing payments, instrument clearing, obligations).
5. **Business-timezone fixes** (D-6): replace `now()->toDateString()` with `BusinessTime::today($organization)`; let users enter the instrument clearing value date.
6. **Protect fiscal documents from data reset**: exclude issued/superseded invoices, issued credit notes and posted payments from selective reset (or require an explicit legal-retention export first).

### P1 — Essential business workflows

7. Supplier master: ICE / IF / RC / RIB / terms / currency / default account; ICE validation for customers and suppliers.
8. Standalone and replenishment POs (make SO linkage nullable on PO lines), draft → approved → sent, PO PDF/email (BCF document).
9. Receipts into general stock; receipt reversal; supplier returns (new movement type plus return document); over/under-delivery tolerance.
10. Three-way matching (quantity and price, with tolerances) and a discrepancy queue.
11. Supplier credit notes; supplier balance, statement and aging; MCP payables read tools.
12. Customer side: per-customer terms, multi-order payment allocation (one transfer → many invoices), incoming LCN/cheque portfolio, commercial credit notes, customer statement.
13. Inter-account transfers (cash → bank deposit) and a **bank reconciliation v1** (CSV statement import, manual match, reconciled flag).
14. Single canonical receivable definition used by every screen (D-7); paginated, DB-side aggregation (D-8).
15. Purchases journal, VAT summary (collected vs deductible) per month, PCM-mapped accounting export.

### P2 — Differentiation and automation

16. Supplier invoice OCR / e-mail ingestion with draft creation and PO suggestion.
17. Approval workflows with amount thresholds (PO, supplier invoice, outgoing payment), maker-checker on payment reversal.
18. Cash-flow forecast from real data (SO and invoice due dates, open POs, validated supplier invoices, instruments by maturity), 13-week horizon.
19. Credit limits and automatic blocking; dunning sequences (WhatsApp/email) built on the existing follow-up engine.
20. Consolidate `product_suppliers` into `product_variant_suppliers`.
21. Landed cost and weighted-average cost from receipts → real COGS and margin.

### P3 — Advanced capabilities

22. Full double-entry general ledger and trial balance.
23. Multi-currency with FX revaluation.
24. Bank API feeds where Moroccan banks offer them.
25. Supplier portal (PO acknowledgement, invoice upload).

---

## G. First development sprint (≈ 2 weeks, 1–2 engineers)

**Theme:** *"Supplier invoices you can trust"* — create the missing AP backbone without redesigning Finance. It reuses `FinanceObligation`, `RecordFinanceOutgoingPaymentAction` and instruments unchanged.

### Scope

| # | Story | Complexity |
|---|---|---|
| S1 | **Supplier fiscal and terms fields**: `ice` (15-digit validation), `tax_id_if`, `rc`, `rib`, `payment_term_days`, `payment_term_end_of_month`, `currency_code` on `suppliers`; form, MCP rules (shared `SaveSupplierAction::rules()`), audit old/new values | S |
| S2 | **Priced PO lines**: `unit_price_ht`, `tax_rate`, line totals and PO totals; prefilled from the catalog price (`product_variant_suppliers.purchase_price`), editable in the PO creation dialog; idempotent create preserved | M |
| S3 | **`supplier_invoices` + `supplier_invoice_lines` + attachments** (Storage disk, not `storage_path`): supplier, supplier invoice number (unique per org+supplier), invoice date, due date (from supplier terms, overridable), HT/TVA/TTC, optional PO and receipt line links; statuses `draft`, `validated`, `cancelled`; locked internal sequence `FF-YYYY-n` | L |
| S4 | **Validation creates the payable**: on `validate`, in one transaction with locks, create one `FinanceObligation` (category `supplier`, `supplier_invoice_id` FK) or link an installment plan whose total must equal invoice TTC. Cancel is allowed only if nothing is paid or committed (reuse `FinanceObligationCalculator` + `FinanceInstrumentCommitmentService`) | M |
| S5 | **Quantity/price matching v1** (read-only warnings, no blocking): per line, invoiced qty vs received qty vs ordered qty and invoiced price vs PO price, with configurable tolerance; badge on the invoice; a "received but not invoiced" list per supplier | M |
| S6 | **Supplier balance and statement**: supplier page tab with validated invoices − posted allocations = balance, aging buckets (0-30 / 31-60 / 61-90 / 90+) computed in SQL, paginated statement lines | M |
| S7 | **P0 hardening bundle**: `BusinessTime::today()` in `RecordPaymentAction`, `RefundPaymentAction`, `ManageFinancePaymentInstrumentAction` (plus a user-entered clearing date) | S |

**Explicitly out of sprint:** invoice-before-payment on the sales side (D-3). It needs a product decision and regulatory input, so plan it as Sprint 2 together with customer terms. Closed-period guard and reset protection also go to Sprint 2 unless capacity allows.

### Acceptance criteria

1. A user with `supplier_invoices.manage` can record a supplier invoice for supplier X with lines linked to a received PO line. Totals are computed server-side with `Decimal`, and a scanned PDF is attached and downloadable via a signed or authorized route.
2. A duplicate supplier invoice number for the same supplier is refused by a **DB unique index**, not only by validation.
3. Validating the invoice creates exactly one obligation, even when submitted twice or concurrently (client operation id + unique `supplier_invoice_id` on obligations). Its due date follows the supplier's terms in the **organization timezone**.
4. Paying the obligation through the existing outgoing-payment flow updates the supplier balance and invoice payment status. Reversing the payment restores both.
5. Cancelling a validated invoice is refused once any posted allocation or active instrument exists.
6. When invoiced qty > received qty or price deviates beyond tolerance, the invoice shows a discrepancy and the discrepancy is audit-logged at validation.
7. PO lines created from the dialog persist a non-null unit price when a catalog price exists. Existing POs with NULL prices still render ("prix non renseigné").
8. Supplier ICE must be exactly 15 digits when provided. Existing suppliers without ICE still work.
9. All new tables have `(organization_id, id)` unique keys and composite tenant FKs with RESTRICT. All queries filter by organization. Store access is enforced where `store_id` is present.
10. No business date uses `now()->toDateString()`. A payment recorded at 00:30 Africa/Casablanca is dated "today" local and is not treated as backdated.

### Tests to add

- `tests/Feature/Procurement/SupplierInvoiceLifecycleTest` — create, validate, cancel, duplicate number, attachment storage on a fake disk.
- `SupplierInvoiceMatchingTest` — over-invoiced qty, price drift within and beyond tolerance, partial receipt.
- `SupplierInvoiceObligationTest` — exactly-once obligation creation (repeated call + simulated unique-violation path), installment-plan total mismatch, cancel blocked by payment or instrument.
- `SupplierBalanceStatementTest` — balance, aging buckets at an as-of date, reversed payment excluded, pagination.
- `SupplierInvoiceTenancyTest` — cross-org supplier/PO/receipt ids rejected (404), store scope.
- `PurchaseOrderPricingTest` — catalog prefill, override, totals, legacy NULL price.
- `BusinessDateTimezoneTest` — payment backdate check, refund date and instrument clearing around local midnight (frozen clock).
- Run the existing suites again, plus `phpunit.mysql.xml` for the new composite FKs and VARCHAR lengths (cf. the 2026_11_17 status-width incident).

### Dependencies

- Permission provisioning migration (`supplier_invoices.view|manage`, `supplier_balances.view`), following `PermissionProvisioner` conventions.
- Storage disk configuration for attachments (SCALABILITY_ARCHITECTURE rule 2).
- Product decision: matching tolerance defaults; whether validation is blocked or only warned on discrepancy (v1 proposal: warn).
- Accountant input (non-blocking for the sprint): supplier invoice mandatory fields and VAT-deductibility data needed later for the VAT summary.

### Approximate complexity

| Story | Size | Rough effort |
|---|---|---|
| S1 | S | 1 day |
| S2 | M | 2 days |
| S3 | L | 3–4 days |
| S4 | M | 2 days |
| S5 | M | 2 days |
| S6 | M | 2 days |
| S7 | S | 0.5–1 day |
| **Total** | | **≈ 12–14 dev-days** (fits a 2-week sprint for 1.5 FTE including tests and review) |

---

## Appendix — test evidence

Command: `php artisan test tests/Feature/Finance tests/Feature/Procurement tests/Feature/Payments tests/Feature/Documents/InvoiceLifecycleTest.php` (SQLite in-memory, per `phpunit.xml`).

Result: **372 tests passed, 2,837 assertions, 0 failures** (~97 s).

Interpretation: the existing behavior is internally consistent and well tested. That includes the "invoice only after full payment" rule (D-3), which the tests assert deliberately. The gaps in this report are **missing scope and design choices**, not failing code. The MySQL suite (`phpunit.mysql.xml`) was not run in this audit.
