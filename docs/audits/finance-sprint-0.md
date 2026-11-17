# Sprint 0 — Financial Integrity & Safety

- **Date:** 2026-11-04 · **Baseline:** `main` @ `24c58d9` (uncommitted working tree)
- **Input:** `docs/audits/finance-procurement-gap-analysis.md` (risks D-4, D-5, D-6, D-7, D-8)
- **Scope:** harden the existing finance modules; no workflow redesign.

---

## WS1 — Fiscal document protection

### Behavior

| | Before | After |
|---|---|---|
| Selective reset of issued invoices, issued credit notes, payments (posted or reversed), refunds, outgoing payments | Allowed (owner + 2FA + phrase) | **Refused** in a protected organization, with blocker `fiscal_protected` (table, count, sample numbers) |
| Full-module reset touching those tables | Allowed | **Refused**: blocker `fiscal.protected_documents.<table>`, no scope can lift it |
| Draft invoices / draft credit notes | Deletable | Still deletable (they never had an official number) |
| Organization declared as **test data** | — | Everything stays cleanable (current behavior) |
| Number sequences | Never reset | Unchanged: never reset, so numbers are never reused (test covers it) |

Both engines re-plan **inside** the execution transaction, so the check also runs at execution time. The selective executor has an extra postcondition: protected fiscal counts must be identical after deletion.

### Model: `organizations.fiscal_data_mode` + `fiscal_protection_started_at`

- `protected` (database default) — every existing and every new organization. A NULL start date means everything is protected.
- `test` — declared test or demonstration data.
- **Test → protected** ("Passer en production"): owner or `system.data_reset`, with a mandatory reason. Protection starts now. Documents created during testing stay cleanable.
- **Protected → test**: **owner only**, fresh 2FA, mandatory reason, and **only while no protected fiscal document exists**. The check runs under the organization row lock.
- **Platform escape hatch:** `php artisan organization:fiscal-data-mode {id} test --reason=… --force`, for an existing genuine demo organization that already holds documents. It is never exposed in the tenant UI.
- Every change is audit-logged (`organization.fiscal_data_mode_changed`, old and new values, reason, `platform_override`).

UI: a "Protection des documents fiscaux" panel on *Paramètres → Réinitialisation des données*, with French explanations for every blocker (`dataReset.blocker.fiscal.*`, `dataReset.sel.bFiscal`).

### Not decided here (needs an accountant)

- **Legal retention period.** None is encoded. The UI says so explicitly.
- **Delivery notes and quotations** are not treated as fiscal documents.
- **Customer-exchange settlement payments** (`customer_exchange_payments`) are not covered by the protection.
- **Numbering continuity at go-live.** Test invoices created before go-live consume `N/YYYY` numbers. After they are cleaned, the real numbering continues after a gap. Restarting a sequence would mean reusing numbers, which is forbidden here. Whether a gap is acceptable, or a new series or year must be used, needs accountant input.

## WS2 — Closed financial periods

`FinanceClosedPeriodGuard::assertOpen()` checks the operation's **business date** (a `Y-m-d` calendar value) against `finance_month_reviews.status = closed`. The period row is read with a shared lock inside the caller's transaction, so the operation serializes with a concurrent close.

| Operation | Date checked |
|---|---|
| Customer payment (web, POS, MCP) | `payment_date` |
| Customer payment reversal | original `payment_date` (a reversal has no date of its own) |
| Refund (order cancellation, customer return) | `refund_date` (business today) |
| Outgoing payment / its reversal | `payment_date` / original `payment_date` |
| Obligation create / update / cancel | `due_date`; on update, both the old and the new date |
| Supplier installment plan create / restructure | every new and every current due date |
| Payment instrument create / cancel | `maturity_date` |
| Instrument clearing | the clearing value date (the outgoing payment's `payment_date`) |
| Recurring obligation generation | skips a closed month (`skipped`), never fails or back-fills |

**Correction path:** reopen the month (existing action, mandatory reason, fresh auth, audited), correct, then close again (new snapshot). There is no silent override. The French error message explains this path.

**Still detected only after the fact** (stale-close warning, not blocked):
- opening positions;
- expected collections and their matches;
- invoices and credit notes dated in a closed month.

These are listed as decisions below.

## WS3 — Moroccan business dates

"Today" now comes from `BusinessTime` (the organization's IANA timezone):

- **Payment backdate check:** `RecordPaymentAction`. It previously used UTC, so between 00:00 and 01:00 in Casablanca a same-day payment got a 403.
- **POS:** payment and sale dates in `CreatePosSaleAction` and `PosController`.
- **MCP payment default date and preview message:** `McpPaymentService`.
- **Refund dates:** `RefundPaymentAction` and `RefundCustomerReturnAction`.
- **Cheque/traite clearing:** defaults to business today. It also accepts a user-entered **value date** (`cleared_on`): never in the future, never before the issue date. The UI has a date field next to "Marquer comme encaissé".
- **Month review:** future-month and "month ended" checks (action and page state). For example, at 31 Oct 23:30 UTC (1 Nov 00:30 local), October can be closed.
- **Finance "today":** the default month (`FinancePeriod::current($organization)`), obligation overdue status, and the treasury forecast.
- **Frontend:** a shared Inertia prop `tenant.business_today` plus `useBusinessToday()`. It is the default date on the sales-order payment form, exchanges, and every Situation-fin-de-mois form. These forms previously used UTC via `toISOString()`.

Tests cover UTC+1, the Ramadan UTC+0 period (2026-02-15 → 2026-03-22 per the bundled tzdata) and the month boundary.

**Not changed (out of scope, same UTC-date pattern):**
- quotation dates;
- invoice and delivery-note default dates plus the invoice backdate check (`AuthorizesDocumentAction`);
- credit-note dates;
- sale date on quotation conversion;
- commission filters.

They are consistent among themselves today, but wrong around midnight. Recommended for a follow-up.

## WS4 — Receivables consistency

Two concepts, documented in `FinanceReceivablesService`:

1. **Order exposure.** It shows on the Créances page, the executive and finance dashboards, and the expected-collection picker.
   - Formula: `max(order TTC − received returns, 0) − (collections − refunds)`, counted only when payable at the currency precision (0.0049 MAD is settled).
   - As of a date: payments use `payment_date`, refunds use `refund_date`, returns use `received_at` before the end of that **local** day, and orders use `sale_date`.
   - Per-order rule: `SalesOrderPaymentCalculator::summaryFromAmounts()`.
2. **Issued-invoice debt** (`totalAsOf()` / `breakdownAsOf()`, used for the "Situation" opening and closing receivables).
   - Formula: issued invoice − issued credit notes − net paid, floored per invoice. Any excess is a separate refund obligation.
   - **Unchanged.**

**Changes:**
- **Removed the third path.** It was an inline query in `FinanceMonthlySituationController` that **ignored returns** and the currency precision. The picker now uses the canonical exposure query.
- **Order exposure is now set-based.** `orderExposureQuery()` does SQL aggregation with database pagination. `orderExposureSummaryAsOf()` is a single aggregate query for dashboards. Before, every confirmed order was loaded into PHP before paginating, including twice per dashboard render with `perPage = 1`. `Money::payableThresholdSql()` gives the SQL threshold rule matching `Money::outstanding()`.
- **Return cutoff fixed.** The as-of cutoff for returns now uses the organization's local day in both the read model and `SalesOrderPaymentCalculator::summaries()`. It was a UTC `DATE()` comparison.

**Known limitations (not changed):**
- Invoice debt as of a past date uses current invoice statuses. An invoice superseded after that date drops out of the history.
- Order exposure uses current order status: an order cancelled later disappears from past dates.
- Fixing either needs issued, superseded and cancelled timestamps in the queries.

---

## Remaining product / accounting decisions

1. **Legal retention period** of fiscal documents and payment history (accountant).
2. **Numbering gap at go-live:** test invoices consumed `N/YYYY` numbers (accountant).
3. **Should reopening a closed month require a different permission** (e.g. owner or a dedicated `finance.periods.reopen`) than closing it (segregation of duties)? Today both need `finance.monthly_situation.manage`.
4. **Extend the period guard** to invoices and credit notes dated in a closed month, opening positions and expected collections, or keep them as stale warnings.
5. **Are delivery notes and quotations fiscal documents** for retention purposes?
6. **Default mode for brand-new organizations:** `protected` was chosen as the safe default. An owner can declare test mode until the first fiscal document exists. Should onboarding ask the question explicitly?
7. **A late cheque clearing in a closed month** must currently be booked in an open month (or after reopening). Confirm this is the desired accounting treatment.

## Migration

`2026_11_22_000001_add_fiscal_protection_to_organizations` (renamed from `2026_11_20_000001` so it sorts after the Test Mode migrations) is additive only. It adds two columns with a default, rewrites no data, and its `down()` drops the columns.

## Test results

**New tests (28):** `FiscalDataProtectionTest` (11), `FinanceClosedPeriodTest` (7), `BusinessDateTimezoneTest` (6), `ReceivablesConsistencyTest` (4).

**Existing tests updated (3):** each now explicitly reflects the new behavior.
- `OrganizationDataResetEngineTest` and `InvoiceAttentionTest`: the reset scenario is declared as test data.
- `FinanceMonthReviewExportTest`: posting into a closed month is now refused; stale detection is proven through an unguarded change.

| Run | Result |
|---|---|
| SQLite, full suite | 2,143 / 2,145 passed. One expected failure (`InvoiceAttentionTest`, fixed afterwards: 8/8). One failure **pre-existing on unmodified HEAD** (`CompletedTransfersVisibilityTest::test_historical_transfer_whose_record_was_removed_is_rebuilt_from_its_movements`, `'4.0000'` vs `'4'`), unrelated to this sprint. |
| MySQL 8.4: Finance | 222 / 222 |
| MySQL 8.4: Settings | 130 / 130 |
| MySQL 8.4: Payments | 47 / 47 |
| MySQL 8.4: Documents | 143 / 143 |
| MySQL 8.4: Security | 157 / 157 |
| MySQL 8.4: Attention | 47 / 47 |
| TypeScript `tsc --noEmit` | clean |
| `vite build` | success |

**Not run:**
- the MySQL suites for POS, Sales, Returns, Exchanges and MCP (these paths changed only their "today" source);
- a real parallel-session concurrency test for a posting racing a month close.


---

## Integration addendum (rebased on `main` @ `da8834d`, Test Mode merged)

See `docs/audits/finance-sprint-0-integration.md`. Changes of policy versus the text above:

- **`fiscal_data_mode = test` is legacy-only and platform-operated.** Test data now lives in Test Mode (`sandbox_id`). No tenant user, not even the Owner, can declare an organization "test" from the application any more (`SetOrganizationFiscalDataModeAction` refuses it; the page state reports `can_declare_test = false`). Only `php artisan organization:fiscal-data-mode` can, for legacy demonstration organizations. The Owner (or a reset operator) can still switch a legacy test organization to `protected`.
- **Test Mode rows are never "protected fiscal documents".** `FiscalDocumentProtection::protectedQuery()` ignores rows with `sandbox_id`. Production rows (`sandbox_id IS NULL`) stay protected.
- **`FinanceClosedPeriodGuard` is a no-op in a Test Mode request**: month reviews describe Production books only.
- **Reopening a closed month** now needs the Owner or the new permission `finance.periods.reopen`, plus a reason (audited). Closing still needs `finance.monthly_situation.manage`.
