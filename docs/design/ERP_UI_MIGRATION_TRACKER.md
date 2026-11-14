# 10xScale ERP — UI Migration Tracker (Design System V1.0)

Started 2026-11-13. Companion to `ERP_UI_AUDIT.md` (findings and priorities). This file tracks **execution**.

## Status vocabulary

| State | Meaning |
| --- | --- |
| NOT STARTED | No V1.0 work on this page family yet. |
| IN PROGRESS | Some V1.0 work landed (tokens, a shared control) but the family is not finished. |
| IMPLEMENTED | Migration code is done. **Not** yet inspected in the browser for this change. |
| VERIFIED | Inspected in the browser (conditions listed in the QA column). |
| BLOCKED | Cannot proceed without a decision, data, or backend change (reason given). |

A family counts as VERIFIED only after a browser check. Global CSS reaching a page does not count.

Test column: `tsc` = `npx tsc --noEmit`, `build` = `npm run build`. The repo has **no frontend interaction test runner** (no Vitest/Jest), so interaction evidence comes from the browser only.

## Batch log

| # | Batch | Status | Evidence |
| --- | --- | --- | --- |
| 0 | Shared date-control family (`DatePicker`, `DateField`, `DateFilter`/`DateRangeFilter` rewired; `MonthPicker` reused) | VERIFIED | Browser: see §2 |
| 0 | Finance date controls (all native date/month inputs in Finance + Payments) | VERIFIED (partial pages) / IMPLEMENTED (rest) | §3 |
| 0 | All other native `type="date"` inputs in the ERP (22) | IMPLEMENTED; Orders filter, Quotations/Create and Activity VERIFIED | §3 |
| 1 | P1: native `window.confirm` (8 calls) → shared `ConfirmProvider`/`useConfirm` | IMPLEMENTED; Products/Show VERIFIED | §4 |
| 1 | P1: dark-mode inverted buttons/tabs (X1, 6 places) | IMPLEMENTED; Activity VERIFIED (dark) | §4 |
| 1 | P1: physical alignment classes (X6) on CommissionDashboard, MonthlySituation + 4 smaller files | IMPLEMENTED; CommissionDashboard page shell VERIFIED (AR/dark); its tables were not rendered (no local commission rules) | §4 |
| 2 | Remaining Sales/Documents pages | VERIFIED for pages with local live data; IMPLEMENTED for sandbox-only/empty records | §7 |
| 3 | Catalogue and Inventory remainder | VERIFIED (15 pages, FR light 1440 + AR dark 390); Transfers/Show IMPLEMENTED | §8 |
| 4 | Procurement | VERIFIED (8 pages, FR light 1440 + AR dark 390); row-level states partly IMPLEMENTED | §9 |
| 5 | Finance and Payments | VERIFIED (page level: 13 pages, 390–1920, FR light + AR dark); row-level states partly NOT VERIFIED (no local payments) | §10 |
| 6 | Contacts and Organization | VERIFIED (page level: 5 pages, 390–1920, FR light + AR dark); contact rows/show NOT VERIFIED (no local contacts) | §11 |
| 7 | Users, Settings, Integrations | VERIFIED (page level: 13 pages, 390–1920, FR light + AR dark) | §12 |
| 8 | Remaining pages (Work, Notifications, Activity, Admin, Sandbox, error pages, Auth/Legal) | VERIFIED (page level: 12 routes at 1440 FR light + 390 AR dark; 768/1024/1920 added in the final QA) | §13 |
| QA | Final application-wide QA (2026-11-14) | 89 VERIFIED · 7 IMPLEMENTED — NOT FULLY VERIFIED · 11 BLOCKED (no local record) · 3 APPROVED EXCEPTION, out of 110 page components. 8 P1 accessible-name defects fixed and verified. | §14, `ERP_UI_FINAL_QA.md` |
| V1 | Release readiness and staging plan (2026-11-14) | Staging: GO. Production: NO-GO until the staging checklist and the dependency advisories are cleared. 3 hard-coded labels moved to existing translation keys. | `ERP_V1_RELEASE_READINESS.md` |

## 1. Shared controls

| Component | File | Status | Notes |
| --- | --- | --- | --- |
| DatePicker | `components/ui/DatePicker.tsx` | VERIFIED | `YYYY-MM-DD` in and out, same as a native input. No time-zone conversion: day maths uses `Date.UTC`, and "today" is the viewer's local day, as with the native control. Accepts `min`/`max` (no default restriction), `required` (hides clear), `disabled`, `invalid`, `name` (hidden input for classic forms), and controlled or uncontrolled use. Liquid Glass popover, Monday-first grid, month/year view, Today, Clear. Keyboard: arrows (mirrored in RTL), PageUp/PageDown (Shift = year), Home/End, Enter, Escape. Escape/Tab are captured so an enclosing Dialog/Sheet neither closes nor steals focus. |
| DateField | `components/ui/form.tsx` | IMPLEMENTED | Labelled wrapper with error/hint wiring (`aria-labelledby` label + value, `aria-describedby`). Available for new forms. No page uses it yet. |
| DateFilter / DateRangeFilter | `components/ui/AdvancedFilterBar.tsx` | VERIFIED (DateFilter) / IMPLEMENTED (DateRangeFilter) | Both now use the DatePicker `bare` variant. The unused `type="month"` option was removed from DateRangeFilter: no caller used it. `MonthPicker` is the month control. |
| MonthPicker | `components/ui/MonthPicker.tsx` | VERIFIED | Now also used by `FinanceFilters` (every Finance report page). No `max` passed, so no future-month restriction was added. |
| ConfirmProvider / useConfirm | `components/ui/ConfirmDialog.tsx` (mounted in `app.tsx`) | VERIFIED | Promise-based replacement for `window.confirm`, built on `Dialog`. Focus starts on Cancel. Destructive calls use the `danger` tone and a solid danger confirm. Escape and the backdrop cancel. |

### Approved exceptions (kept native, on purpose)

| Control | Where | Reason |
| --- | --- | --- |
| `type="time"` | Follow-up due time, recurring follow-up time, work templates, backup schedule | Not a calendar date. The native time field is already styled with `control-input` and gives the best mobile entry. |
| `type="datetime-local"` | FulfillmentCard (delivered at, with `toIso()` conversion), Settings/AiMcp expiry | These carry a time and time-zone conversion. Swapping them risks changing the stored instant. Revisit with a DateTime control in a later batch. |
| Physical alignment inside document paper | Invoices/Show, Quotations/Show | The printed document is LTR on purpose (audit X6). |

## 2. Date-control browser QA (2026-11-13, Chrome, local seeded org)

| Check | Result |
| --- | --- |
| Commission ledger, FR light 1920 | Calendar opens under the field. Keyboard Jan 1 → → ↓ PageDown = Mon 9 Feb 2026. Enter commits and returns focus to the field, whose accessible name reads "Du : Lundi 9 février 2026". |
| Commission ledger, AR dark 1920 | Popover is start-aligned (right edge) and the grid is mirrored. Arabic weekday/month names. |
| Commission ledger, AR dark at 390px (same-origin 390px iframe; the Chrome window could not be resized below maximised) | Panel at 41–353px of a 371px client width, no horizontal overflow. |
| Finance › Ventes, MonthPicker | "Mois précédent" moves to October 2026 via the existing `month` query parameter. |
| Sales › Orders, DateFilter | Picking 12 Nov sets `?sale_date=2026-11-12`, shows the active chip, and filters the table. |
| Quotations › Create | Both fields render at full width with their labels. Selection updates the value and focus returns. |
| Finance › Recurring obligations (DatePicker inside a modal Sheet) | Calendar layers above the Sheet and flips upward. Tab cycles inside the calendar. Escape closes only the calendar (the Sheet stays open) and focus returns to the field. |
| Activity (classic `<form>` + FormData) | Hidden input submits `date_from=2026-11-12` with the same query shape as before. |

Not yet checked in the browser: 768/1024/2560 widths, the DateRangeFilter (used only by `Dev/UiGallery`), and real screen-reader output (accessible names were checked through the DOM).

## 3. Date-control migration — per page

| Module | Page / component | Controls | Status | QA |
| --- | --- | --- | --- | --- |
| Finance | `components/finance/FinanceFilters` (Ventes, CA encaissé, Créances, Encaissements, Facturation, Journal, Situation) | month → MonthPicker | VERIFIED (Ventes) | Browser FR light |
| Finance | `components/finance/CommissionFilters` (Commission dashboard, Salesperson, My commissions) | from/to → DatePicker (disabled unless period = custom; from ≤ to) | VERIFIED (dashboard, AR dark, disabled state) | Browser |
| Finance | CommissionLedger | from/to | VERIFIED | Browser FR/AR, light/dark, 390 |
| Finance | CommissionRules | effective_from, simulation from/to | IMPLEMENTED | tsc/build |
| Finance | MonthlySituation (5 date fields via its `Field` helper) | payment, issue, maturity, expected, due | IMPLEMENTED | tsc/build |
| Finance | RecurringObligations | start, optional end | VERIFIED (start, in Sheet) | Browser FR light |
| Finance | SupplierInstallments | per-row due date | IMPLEMENTED | tsc/build |
| Payments | Payments/Index | date filter → `DateFilter` in AdvancedFilterBar (same `date` param) | VERIFIED | Browser AR dark |
| Payments | FinancialAccounts/Index | opening reference date (required) | IMPLEMENTED | tsc/build |
| Sales | Orders/Index, Quotations/Index, Invoices/Index, DeliveryNotes/Index | DateFilter | VERIFIED (Orders) / IMPLEMENTED (others, same component) | Browser FR light |
| Sales | Quotations/Create | quotation date, valid until | VERIFIED | Browser FR light |
| Sales | Quotations/Show, Orders/Edit, Orders/Show (payment date, expected receipt), DeliveryNotes/Show, Invoices/Show, ProcurementReadinessCard | dates; `readOnly` when backdating is not permitted → `disabled` | IMPLEMENTED | tsc/build |
| Procurement | PurchaseOrders/Show (received on, `max=today`, required) | kept `max` | IMPLEMENTED | tsc/build |
| Procurement | SupplierRequests/Index | from/to filters | IMPLEMENTED | tsc/build |
| Work | TeamMember filters, FollowUpFormDialog, FollowUpList, RecurringFormDialog, AttentionList snooze (`min=today`) | dates | IMPLEMENTED | tsc/build |
| Admin | Activity/Index (classic form) | from/to | VERIFIED | Browser FR dark |
| Admin | DataReset SelectiveReset | from/to | IMPLEMENTED | tsc/build |

Remaining native `type="date"` inputs: **0** (`grep -rn 'type="date"' resources/js` matches only the two `Field` helpers, which route `type="date"` to DatePicker).

## 4. P1 fixes

| ID | Fix | Files | Status | QA |
| --- | --- | --- | --- | --- |
| X2 | `window.confirm` → `useConfirm` | Catalog/Products/Show (archive), Contacts/Show (archive), Pos/Index (hold sale, delete held sale), Sales/Orders/FulfillmentCard (remove proof, **not in the audit**), Settings/UsersAccess/Index (cancel invitation, remove member), Settings/UsersAccess/RoleEditor (delete role, **not in the audit**) | VERIFIED on Products/Show (danger tone, focus on Cancel, Escape cancels without a request). Others IMPLEMENTED. | Browser FR light |
| X1 | `bg-ink`/`bg-slate-900` + `text-white` → `bg-primary text-primary-fg` | Activity, Payments/Index, Procurement/OutOfStockArticles (2), WooCommerceStockTasks, `components/pos/primitives.tsx` | VERIFIED on Activity (dark: teal button, near-black label). Others IMPLEMENTED. | Browser FR dark |
| X6 | `text-left/right`, `ml-*` → logical | CommissionDashboard (25), MonthlySituation (13), CommissionSalesperson, RecurringObligations, UsersAccess/Index, SystemHealth | IMPLEMENTED. The CommissionDashboard page renders correctly in AR. Its numeric tables need commission data that the local seed does not have. | Browser AR dark (shell only) |

## 5. Page-family tracker

Columns: Module · Route/page · Components used · Design status · Issues · Priority · Migration · Browser QA · Tests. P1 issues from the audit that are still open are in **bold**.

| Module | Route/page | Components | Design status | Open issues | Pri | Migration | Browser QA | Tests |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Dashboard | Platform/Index | MonthPicker, MetricCard | Migrated | Backend attention labels (needs backend approval) | P2 | VERIFIED | Earlier session: FR/AR, light/dark, 390–1440 | build |
| Sales | Orders/Index, Quotations/Index, Invoices/Index, DeliveryNotes/Index, Returns/Index, Customers/Index | AdvancedFilterBar, TableShell, DateFilter | Migrated | — | — | VERIFIED (Orders this session; others earlier) | FR (AR for Customers) | build |
| Sales | Orders/Show, Orders/Edit (+ OrderLineGrid), Orders/Complete | surface-card, control-select/-input, control-compact grid, DatePicker, erp-table | Migrated | File split of Orders/Show deferred (see §7) | — | VERIFIED | This session: FR light 1920; AR dark at 390/768/1024/1440 | tsc/build |
| Sales | Quotations/Create, Quotations/Show (+ LineGrid) | surface-card, shared controls, DatePicker; paper untouched | Migrated | Sandbox banners have hard-coded French copy | P2 | VERIFIED (Show, Create); LineGrid IMPLEMENTED (only an issued quotation exists locally) | FR light; AR dark 390/768 | tsc/build |
| Sales | Invoices/Show, DeliveryNotes/Show, CreditNotes/Show | surface-card, shared controls, DatePicker | Migrated | No live record locally (the only invoice is a sandbox record, which 404s in the live workspace; no delivery notes or credit notes) | — | IMPLEMENTED | Not checked this pass | tsc/build |
| Sales | Returns/Create, Exchanges/Create, Customers/Form, Customers/Show | shared controls, erp-table, surface-card | Migrated | Exchanges: the "Dérogation responsable" checkbox is still a bare native checkbox | P2 | VERIFIED | FR light | tsc/build |
| Sales | Returns/Show, Exchanges/Show | shared controls, surface-card | Migrated | No returns or exchanges exist locally | — | IMPLEMENTED | Not checked | tsc/build |
| Sales | Commissions/MyCommissions | TableShell, CommissionFilters on AdvancedFilterBar | Migrated | — | — | VERIFIED | FR light: period → custom range → end date → reset | tsc/build |
| POS | Pos/Index | PosLayout, useConfirm | Specialised | POS sub-components not restyled (out of scope for this batch) | P2 | IN PROGRESS (P1 hold-confirm VERIFIED) | Hold confirm: neutral tone, focus on Cancel, Escape keeps the sale | tsc/build |
| Catalogue | Products/Index, NonStockItems/Index | AdvancedFilterBar, TableShell; NonStockItems now in CatalogLayout | Migrated | — | — | VERIFIED | FR/AR; NonStockItems shows Catalogue nav | build |
| Catalogue | Products/Show | useConfirm, erp-table | Migrated | — | — | VERIFIED | FR light 1440; AR dark 390 | tsc/build |
| Catalogue | Products/Form, ReferenceData (categories, brands), ProductCosts/Index, Imports/Wizard | AdvancedFilterBar + TablePager (ProductCosts), shared controls, erp-table | Migrated | — | — | VERIFIED | FR light 1440; AR dark 390 | tsc/build |
| Catalogue | ProductCosts/ImportPreview, Imports/Wizard later steps | TablePager, erp-table | Migrated | Needs an uploaded file to reach | — | IMPLEMENTED | Step 1 only | tsc/build |
| Inventory | Stock, Movements, Transfers, TransferRequests (index) | AdvancedFilterBar, TableShell | Migrated | KPI "visibles" label | P2 | VERIFIED (earlier) | FR/AR | build |
| Inventory | Transfers/Create, TransferRequests/Form/Show, Warehouses (edit in Sheet), Stock/Index | shared controls, erp-table, Sheet | Migrated | — | — | VERIFIED | FR light 1440; AR dark 390; warehouse sheet open/Escape | tsc/build |
| Inventory | Transfers/Show | erp-table | Migrated | No live transfer exists locally | — | IMPLEMENTED | Not checked | tsc/build |
| Procurement | Index, PurchaseOrders (Index, Show), SupplierRequests, Suppliers (Index, Show) | AdvancedFilterBar, TableShell/TablePager, Sheet (supplier form), shared Dropdown menu, Buttons | Migrated | — | — | VERIFIED | FR light 1440; AR dark 390; filters, sheet, menu | tsc/build |
| Procurement | OutOfStockArticles/Index | AdvancedFilterBar + accessible segmented status, TableShell/TablePager, compact resolve form | Migrated | No local articles (rows/resolve form not rendered) | — | VERIFIED (page, tabs, dark contrast) | AR dark: active tab near-black on teal | tsc/build |
| Finance | Situation, Ventes, CaEncaisse, Creances, Encaissements, Facturation, Journal | FinanceFilters on AdvancedFilterBar (MonthPicker + store ChoiceFilter), TableShell/TablePager, EmptyState, shared Dropdown export menu | Migrated | — (pagination query fix applied 2026-11-14, see §10) | P2 | VERIFIED (page level) | 390/768/1024/1440/1920, FR light + AR dark; store filter, chip, reset; RTL amounts | tsc/build; 274 Finance/Payments/Sandbox tests |
| Finance | MonthlySituation | AdvancedFilterBar (live search, type/status/supplier), TablePager, Badge, numeric columns | Migrated (presentation pass) | No local obligations: table rows, sheets, payment/instrument flows not exercised | P2 | VERIFIED (page, filters) | 5 widths; AR dark status filter, filtered empty + reset, live search | tsc/build |
| Finance | Commission Dashboard/Ledger/Rules/Salesperson/EntryShow | DatePicker, control/table sweep | Swept (not rebuilt) | Rules/Ledger keep their own list layouts | P2 | VERIFIED (probe: Rules, Ledger) / IMPLEMENTED (rest) | Rules 390 AR overflow fixed | tsc/build |
| Finance | RecurringObligations, SupplierInstallments, SupplierInstallmentShow | DatePicker, Sheet, shared controls | Swept (not rebuilt) | Legacy pagination remains | P2 | VERIFIED (probe: first two) / IMPLEMENTED (Show) | 1440 FR + 390 AR | tsc/build |
| Payments | Payments/Index (rebuilt), Payments/Show (tokenised), FinancialAccounts/Index (swept) | AdvancedFilterBar, TableShell/TablePager, Badge | Migrated / tokenised | No local payments: rows, Show page, reverse flow not rendered | P2 | VERIFIED (Index page + filters, FinancialAccounts probe); Show NOT VERIFIED | AR dark chips/remove | tsc/build |
| Contacts | Contacts/Index, Show, Form | AdvancedFilterBar, TableShell/TablePager, form.tsx fields, Badge, Button/ButtonLink, useConfirm | Migrated | No local contacts: rows, Show page and the archive confirm not rendered | P2 | VERIFIED (Index + Form) / IMPLEMENTED (Show) | 5 widths, FR light + AR dark; type filter, chip, filtered empty | tsc/build |
| Admin | Settings/UsersAccess/Index, RoleEditor | useConfirm, control sweep, named role selects | Migrated (batch 7) | RoleEditor permission labels are English backend strings | P2 | VERIFIED (Index); RoleEditor opened read-only in final QA | 5 widths (batch 7); RoleEditor FR light 1440 | tsc/build |
| Admin | Activity/Index | DatePicker, control sweep | Migrated (batch 8) except pager | Legacy numbered pager; English backend action labels | P2 | VERIFIED | 390/768/1024/1440/1920; pager keeps filters | tsc/build |
| Settings | Stores, DocumentProfile, TemplateStudio, DocumentStamp, EmailConfiguration, QuotationSettings, ReturnPolicy, NotificationPreferences, Profile, Security, Sessions | shared controls, surface-card | Migrated (batches 6–7) | Native file inputs unnamed (Profile, Stamp) | P2 | VERIFIED (page level) | 390–1920 FR/AR (batch 7); a11y sweep in final QA | tsc/build |
| Integrations | WooCommerce, WooCommerceStockTasks | AdvancedFilterBar, TableShell/TablePager (StockTasks) | Migrated (batch 7) | Price-mode select was unnamed — fixed in final QA | — | VERIFIED (page level); task rows not rendered (no data) | 390–1920 | tsc/build |
| Admin | OrganizationBackups, DataReset, AiMcp, SystemHealth, Sandbox | ApplicationShell reading (Backups), Button/tokens (Sandbox), control sweep | Migrated (batches 6, 8); Sandbox standalone by design | SelectiveReset keeps legacy SearchInput/FilterSelect | P2 | VERIFIED (page level) | 390/768/1024/1440/1920 | tsc/build |
| Work | Work/Index, Attention, Recurring, Team, TeamMember, Templates; Notifications | AdvancedFilterBar (Index, Team, Notifications), DatePicker | Migrated (batch 8) | Legacy numbered pager on 5 Work pages | P2 | VERIFIED (page level); rows not rendered (no data) | 390/768/1024/1440/1920; TeamMember 1440/390 | tsc/build |
| Auth / Legal | Auth/*, Legal/* | AuthLayout, LegalLayout | Brand pass (batch 8) | Legal content French-only under AR | P2 | VERIFIED: Login, Register, ForgotPassword, Privacy, Terms. IMPLEMENTED — NOT FULLY VERIFIED: token/state-gated screens | 390 AR dark + 1440 FR light | — |

## 6. Open P0/P1 after this pass

- **P0:** none known.
- **P1:** none known in source, but several fixes still lack a browser check: the confirms on POS, Contacts, UsersAccess, RoleEditor and Fulfilment; the dark tabs on WooCommerce Stock and Articles hors stock; the RTL table cells on CommissionDashboard. They stay IMPLEMENTED until checked.

## 7. Batch 2 — Sales/Documents (2026-11-13)

**Recipe.** Applied by a reviewed script, with no hand rewrites of logic:
- `<select>` → `control-select`; ad-hoc bordered `<input>`/`<textarea>` → `control-input`. Layout utilities (margins, widths, `text-end`, `tabular-nums`) are kept.
- `rounded-card border border-line bg-surface` → `surface-card`, except on `.doc-paper`.
- Raw data tables → `erp-table`.

The printed document paper (Invoices/Quotations `.doc-paper`) is untouched on purpose.

**New shared modifier.** `.control-compact` (`resources/css/app.css`): a 36px, 13px variant of `control-input`/`control-select` for dense editing grids. RTL chevron placement is included. Used by `Sales/Orders/OrderLineGrid` and `Quotations/LineGrid`. Grid rows stay compact instead of growing to 40px.

**Select policy.** A styled native `control-select` is the shared control for short fixed lists: payment method, account, disposition, tax rate, `%`/`DH`, customer type. It has the best touch behaviour and keeps form submission unchanged. `SearchableSelect` is reserved for long or searchable lists. None of the Sales selects needed it: the longest is the salesperson list, which is a short staff list.

**Commission filters.** `components/finance/CommissionFilters.tsx` now runs on `AdvancedFilterBar` and applies changes live; the "Appliquer" button is gone. It uses the same query parameters (`period`, `from`, `to`, `store_id`, `salesperson`, `status`, `entry_type`, plus preserved `extra`). `this_month` is the server default, so it is the unset state and the reset target. Chips only appear for filters the page exposes. This fixes a regression caught in QA: My commissions showed a removable "Commercial" chip for the server-pinned salesperson. The same component also serves the Finance commission dashboard and salesperson pages.

**Other fixes.**
- Orders/Edit: the untranslated "TOTAL TTC" now uses `orders.show.totalInclTax`, and the totals block uses `ms-auto` instead of `ml-auto`, so it sits at the end edge in Arabic.
- Invoices/Show and Quotations/Show side panels: `text-right` → `text-end` (outside the paper only).
- Orders/Show: the procurement and out-of-stock status chips now use semantic tokens.
- Quotations/Show: the sandbox banners now use warning tokens.

**Browser QA.** Chrome, local org 2.

| Page | Result |
| --- | --- |
| Orders/Show SO-000006 | FR light 1920: 11 solid cards, no legacy fields or raw tables, no overflow. AR dark: correctly mirrored, no overflow at 390/1440. |
| Orders/Show SO-000001 (payment form) | Method/account selects, amount, date (DatePicker) and reference are all 40px shared controls. |
| Orders/Edit SO-000009 | Compact grid controls at 36px, compact select chevron on the left in RTL. No overflow at 390/768/1024 in AR dark. |
| Quotations/Show DEV-1/2026 | Clean; no overflow at 390/768 in AR dark. |
| Returns/Create, Exchanges/Create (SO-000006), Order completion | Clean, no overflow. |
| Customers/Show, Customers/Edit | Clean. The Show tables only render with data; this customer has none. |
| My commissions | Period → Personnalisé reveals the range (`period=custom&from&to`). Changing "Au" re-queries (applied period updates to 15/11). Reset returns to `period=this_month` with no chips. |
| POS hold confirmation | Added one line to the local cart, triggered "Nouvelle", cancelled with Escape (sale kept, nothing held, focus back on "Nouvelle"), then removed the line. The POS keeps its usual empty draft SO-000010. |

**Not verified / deferred.**
- Invoices/Show, DeliveryNotes/Show, CreditNotes/Show, Returns/Show, Exchanges/Show: there is no live local record (the only invoice is sandbox data). These are IMPLEMENTED, not VERIFIED.
- Orders/Show (1600 lines) and Quotations/Show (1480 lines) were restyled in place, **not split** into panels. Splitting is a refactor with no visual payoff, so it is deferred and needs approval.
- **New finding (P2):** Laravel's default unstyled 404 page is shown for out-of-scope records, e.g. sandbox documents. Error pages need a branded error view. This belongs to the "remaining pages" batch.
- Hard-coded French copy in the Quotations sandbox banners (no Arabic). It needs translation keys.

## 8. Batch 3 — Catalogue and Inventory (2026-11-13)

**Changes.**
- **Script sweep** (same recipe as §7) on Products/Form, ReferenceData, ProductCosts (Index and ImportPreview), Imports/Wizard, Products/Show, NonStockItems, Stock/Index, TransferRequests (Form and Show), Transfers (Create and Show) and Warehouses:
  - 13 selects → `control-select`.
  - 8 raw tables → `erp-table`.
  - Ad-hoc fields → `control-input`.
- **Shared class variables** (`fieldClass`/`inputClass`) in ReferenceData, TransferRequests/Form, TransferRequests/Show and Transfers/Create now point at `control-input`. A matching `selectClass` (`control-select`) is used on their selects.
- **Purchase prices index (`/catalog/purchase-prices`):**
  - The legacy `SearchInput` + 4 `FilterSelect` + big page-number `Pagination` became `AdvancedFilterBar` (debounced FilterSearch + 4 ChoiceFilters) + `TablePager` with "x–y sur n".
  - Query parameters are unchanged (`search`, `stock`, `cost`, `brand`, `category`, `page`). Reset still goes to the page's working view, `stock=in_stock&cost=missing`. Chips only show departures from that view.
  - The three hard-coded French filter labels ("Stock", "Prix d'achat", "Marque") now use existing translation keys.
- **ProductCosts/ImportPreview:** `Pagination` → `TablePager`.
- **NonStockItems:** rendered inside `CatalogLayout` instead of `SalesLayout`. Only the navigation context changes; the route is untouched (audit X7).
- **Warehouses:** the inline `<details>` edit form now opens in a `Sheet`:
  - It uses the same `useForm` and the same `PATCH /inventory/warehouses/{id}`.
  - Save stays disabled until something changes.
  - Cancel and Escape reset the form; a successful save closes the sheet.

**Filter-state fix (also applied to `CommissionFilters`).** When a page default is not "all", every state is now an explicit option, e.g. "Tous les stocks / En stock / Rupture" and all six commission periods. "Effacer" returns to the server default. Before this, "all" was reachable only through "Effacer", which on Purchase prices meant "all" rather than the default.

**Browser QA.** Chrome, local org 2, probed in same-origin iframes. Each page was checked for un-migrated fields, raw tables and horizontal overflow.

| Check | Result |
| --- | --- |
| FR light at 1440 | All 0 on 15 pages: products create/edit/show, categories, brands, purchase prices, product import, non-stock items, stock, transfer create, transfer-request create/show (TRQ-000003), warehouses. |
| AR dark at 390 | All 0 on the same pages. |
| Purchase prices | Stock → "Tous les stocks" applies with a chip. Reset returns to `stock=in_stock&cost=missing` with no chips. |
| Warehouses | "Modifier" opens the prefilled sheet with focus on Name and Save disabled. Escape closes it and returns focus to "Modifier". Nothing was saved. |
| Non-stock items | Header navigation is now the Catalogue set (Produits / Catégories / Marques). |
| Product edit, AR dark | Sections, selects and RTL chevrons are correct. |

**Not verified.**
- Transfers/Show: no live transfer exists locally.
- ProductCosts/ImportPreview and the later Imports/Wizard steps: both need an uploaded file.
- The purchase-price table rows: every local variant already has a cost, so the default "missing price" view is empty, and I did not open a view with rows. The table markup (`erp-table`) and the cost editor are unverified in the browser.

## 9. Batch 4 — Procurement (2026-11-13)

**Filter parameters.** Each list sends exactly what its controller validates:

| Page | Controller | Parameters |
| --- | --- | --- |
| Procurement index | `ProcurementController` | `search`, `status` |
| Purchase orders | `PurchaseOrderController` | `search`, `status` |
| Suppliers | `SupplierController@index` | `search` only (the page's former `active` prop was never validated, so it is not offered) |
| Supplier detail | `SupplierController@show` | `search`, `status` = `active` \| `inactive` |
| Articles hors stock | `OutOfStockArticleController` | `search`, `status` = `unresolved` \| `resolved` \| `all` (default `unresolved`) |
| Supplier requests | `QuotationSupplierRequestController` | `search`, `status` (default `open`), `source`, `supplier_id`, `from`, `to` |

**Changes.**
- **Five lists rebuilt on the standard index pattern:** Procurement index, Purchase orders, Suppliers, Articles hors stock and Supplier requests now use `useQueryFilters` + `AdvancedFilterBar` + `TableShell` + `TablePager`.
  - Each has filtered-vs-empty states with a reset action, and mobile cards with the pager.
  - The legacy `SearchInput`, `FilterSelect` and big-number `Pagination` are gone from Procurement.
- **Supplier detail** catalogue filters are on the same bar.
- **Defaults are explicit.** Where the server default is not "all" (Articles hors stock `unresolved`, Supplier requests `open`), the default is shown as a selectable state and "Réinitialiser" returns to it. Only departures from the default produce chips.
- **Articles hors stock** (the old P1 dark-mode tab):
  - The segmented status control is tokenised and accessible: `aria-pressed`, focus ring, 40px.
  - The resolve form uses `control-compact` fields and shared `Button`s.
  - Status pills use `StatusBadge`.
- **Suppliers:**
  - The inline create/edit form moved into a `Sheet`. It uses the same POST/PATCH, with `dir="ltr"` on phone and email.
  - "Modifier" text links became buttons.
  - Uppercase labels removed.
- **Supplier requests:**
  - The one-off `OverflowMenu` now wraps the shared `Dropdown`/`MenuButton`. That gives portal placement, RTL alignment, viewport flipping and keyboard support, replacing a fixed `right:` offset.
  - From/to are now a `DateRangeFilter`, and the 10px uppercase micro-labels became 11px sentence case.
- **Purchase order detail:** hand-rolled buttons → `Button` (primary / secondary / danger / danger-solid, with loading state). The uppercase section heading was removed.
- **Palette:** every hard-coded palette class is gone: 99 occurrences removed in the diff, 0 remaining in Procurement. Most were mapped to tokens by a reviewed script (neutral slate → ink/line/raised, hues → success/warning/danger/info), the rest went in the page rewrites. The last `text-white` on `bg-danger` → `text-danger-fg`.
- **Shared components:** the procurement components (supplier relation dialog, table, variant-suppliers section) got the control/table sweep and lost their uppercase labels.
- **Shared tweak:** each `DateRangeFilter` end widened to 9.5rem, so "1 nov. 2026" no longer truncates.

**Browser QA.** Chrome, local org 2.

| Check | Result |
| --- | --- |
| Iframe probe, FR light 1440 and AR dark 390 | 0 overflow, 0 un-migrated fields, 0 raw tables and 0 uppercase-transformed elements on: procurement index, purchase orders, BCF-000001, supplier requests, articles hors stock, suppliers, both supplier detail pages. |
| Articles hors stock, AR dark | Active tab text rgb(6,20,19) on rgb(63,184,171) (was white on white). "تمت معالجتها" sets `?status=resolved`, adds a chip, and shows the filtered empty state with reset. |
| Suppliers | "Modifier" opens the prefilled sheet with focus on Name. Escape closes it and focus returns to "Modifier". Nothing was saved. |
| Supplier requests | Status → "Tous les statuts" + start date 01/11 gives `?from=2026-11-01&status=all` with two chips and one row. Reset returns to "À traiter (en attente, partiel)" with no chips. At 1280 the "Plus d'actions" menu opens 6px below its trigger, end-aligned. |

**Not verified.**
- Articles hors stock rows and the resolve form: there are no local articles.
- Purchase order receive/cancel forms: they open only on actionable lines; the page itself was checked.
- Procurement-index rows: the default list has data, but individual row states were not exercised.
- The supplier-requests overflow menu in RTL: it relies on the shared hook, which was RTL-verified in earlier passes, but it was not re-checked here.
- **Environment note:** the first direct load after edits sometimes stalled while Vite re-bundled. A second tab loaded normally. This is not an app defect.

## 10. Batch 5 — Finance and Payments (2026-11-13)

**Inventory and parameters.** Each page sends only what its controller validates:

| Page | Route | Parameters |
| --- | --- | --- |
| Finance overview | `/finance` | `month`, `store_id` |
| Ventes, Facturation, Encaissements, Créances, Journal, CA encaissé | `/finance/*` | `month` (Y-m, server default = current month), `store_id`; Encaissements also `refund_page` |
| Month-end situation | `/finance/situation-fin-de-mois` | `month`, `store_id`, `category`, `status` (upcoming\|partial\|paid\|overdue\|cancelled), `supplier_id`, `search` |
| Payments | `/payments` | `search`, `date` (single day), `method` (cash\|card\|bank_transfer\|cheque), `status` (posted\|reversed), `account` |

No range, sort or other new filter was added. Payments keeps a single-day `DateFilter`, and Finance keeps a month picker.

**Changes.**
- **Shared `FinanceFilters`:**
  - Moved onto `AdvancedFilterBar`: the MonthPicker plus a store `ChoiceFilter` (searchable above 8 stores).
  - Chips appear for a non-current month (localised label) and for a store. Reset returns to the current month and all stores.
  - A loading indicator shows during visits, and page-specific `extra` params pass through unchanged.
- **Six report lists and the overview:**
  - `TableShell` + `TablePager` ("x–y sur n") replace the legacy pagination and dashed empty boxes, with `EmptyState` on mobile.
  - Amount columns use `.num`: tabular, end-aligned and pinned in RTL.
  - Journal events and Encaissements refunds use their own `TableShell`. The CA encaissé total card uses tokens, and its status/nature pills are `Badge` with the same colour mapping.
  - CA encaissé export menu → shared `Dropdown` (portal, RTL placement, keyboard). Excel/PDF stay plain `<a>` downloads and the ZIP keeps its fetch-and-toast error flow, with the busy state now on the trigger.
- **Overview reconciliation:** the `<table>` became a label/amount `<dl>`. Before, the closing-position row was `text-end` without `dir="ltr"` while the other rows had it, so in Arabic one amount sat on the opposite edge.
- **Payments:**
  - The legacy GET form with its "Filtrer" button → live `AdvancedFilterBar`: debounced search, date, method, status and account, with chips and reset.
  - `TableShell` + `TablePager`, filtered vs empty states, and mobile cards with a pager.
  - The method pill became a neutral `Badge`, and reversed amounts are muted.
  - Payments/Show: 15 hard-coded palette classes tokenised (danger/warning/ink).
- **Month-end situation:** an incremental presentation pass only. No calculation, form or state-transition code was touched.
  - The obligation filter form → `AdvancedFilterBar`, with debounced search. Applying filters keeps `month` and `store_id`.
  - The filtered empty state offers a reset. Before, an empty search showed "add an obligation".
  - `Pagination` → `TablePager`.
  - **Header alignment fix:** the amount headers in all three tables were start-aligned while their cells were end-aligned. They now share `.num`.
  - Expected collections and future due dates became `erp-table`, and the dashed empty box became `EmptyState`.
  - `Metric` uses tokens with a sentence-case label. `Status` uses `Badge` with the same variant mapping.
- **Sweep of the remaining Finance pages:** RecurringObligations, SupplierInstallments(+Show), CommissionRules, CommissionLedger, CommissionDashboard/Salesperson/EntryShow and FinancialAccounts.
  - Class constants now point to `control-input` / `control-select` (a select variant was added where one constant served both).
  - Raw tables → `erp-table`, and uppercase labels removed.
  - CommissionRules overflowed by 203px at 390px (Arabic and French) because a grid item took the 520px width of its table. That was already the case before this batch. Fixed with `[&>*]:min-w-0` on the grid.

**Tests.**

| Check | Result |
| --- | --- |
| `npx tsc --noEmit -p .` | Pass |
| `npm run build` | Pass |
| `php artisan test tests/Feature/Finance tests/Feature/Payments tests/Feature/Sandbox` | 274 passed, 2944 assertions. Backend only: totals, permissions and sandbox exclusion. Not proof of UI behaviour. |

**Browser QA.** Chrome, local org 2.

| Check | Result |
| --- | --- |
| Iframe probe | 13 pages (overview, 6 reports, payments, month-end, recurring, installments, commission rules, ledger, financial accounts) at 390/768/1024/1440/1920, FR light and AR dark: 0 horizontal overflow (after the CommissionRules fix), 0 un-migrated fields, 0 raw tables, 0 uppercase-transformed elements. |
| Créances (4 rows) | Store filter → `?month=2026-11&store_id=2` with a chip. The total stays 21 096,00 DH. Reset clears `store_id`. |
| Ventes, AR | Every amount column's text starts at the same offset as its header (pinned to the left edge in RTL). |
| Month-end, AR dark | Status "متأخر" → `status=overdue` with `month` kept, a chip, and the filtered empty state with reset. Reset clears it. Typing "loyer" applies `search=loyer` live without losing focus. |
| Payments, AR dark | `?method=cash&status=posted` shows two chips and the filtered empty state. Removing the method chip leaves `?status=posted`. |
| CA encaissé export | The menu opens end-aligned under "Exporter" with the 5 entries. Escape closes it and returns focus. Export URLs are unchanged. |

**Not verified.**
- **No local data:** there are no local payments, collections, invoices or obligations, so these did not render:
  - Payments rows and Payments/Show (including reversal).
  - Encaissements, Journal, CA encaissé and Facturation rows.
  - Month-end obligation rows, the pay/instrument/expected-collection sheets, and the month close/reopen actions.
- **Downloads:** export downloads (xlsx/pdf/zip) were not triggered.
- **Sandbox exclusion:** relies on the 274 backend tests. It was not re-observed in the UI.
- **Commission pages:** Dashboard, Salesperson and EntryShow were swept but not re-probed in this batch.
- **No real financial transaction** was created or changed.

**Findings needing approval (backend).**
- **Pagination drops filters — FIXED 2026-11-14 (approved by the user).** `->withQueryString()` added to the 5 paginators: `ventesBreakdown` and the Encaissements payments paginator in `FinanceMonthlyReportService`, `FinanceInvoiceReadModel`, `FinanceJournalService` and `FinanceCaEncaisseService` (5 lines, no calculation change). 274 Finance/Payments/Sandbox tests pass. Checked through Inertia responses: with `?month=2026-10&store_id=2`, the page links on Ventes, Facturation, Encaissements, Journal, CA encaissé and Créances all carry `month=2026-10&store_id=2`.
- **French label in Arabic:** the server `periodLabel` is French-only ("Novembre 2026" in the Arabic month-end review heading). The filter chip now formats the month on the client instead.

## 11. Batch 6 — Contacts and Organization (2026-11-13)

**Scope.**
- **Contacts:** Index, Show and Form (`/contacts`).
- **Stores:** Settings › Stores (`/settings/stores`).
- **Organization backups:** `/organization-backups` (audit X7).
- **Excluded:** the organization dashboard (`Platform/Index`) is the approved dashboard and stays unchanged, and the remaining organization settings pages belong to batch 7.

**Contacts.**
- **Index** rebuilt on `useQueryFilters` + `AdvancedFilterBar` + `TableShell` + `TablePager`. The params are exactly what the controller validates: `search`, `contact_type`, `active` (1|0).
  - The "Rechercher" submit button is gone: search is debounced and the selects apply live.
  - Chips, filtered vs empty states, and mobile cards with a pager.
  - **Defect fixed:** the Index type labels had no `transport` entry, so transport contacts showed the raw value "transport". The type options now cover all 5 `OrganizationContact::TYPES`.
- **Form** rebuilt on the shared `TextField` / `SelectField` / `TextAreaField` / `Checkbox` / `FormBanner` and `Button`, with the same fields, payload and POST/PATCH.
  - Labels are now sentence case and linked to their fields, and errors are linked to their fields.
  - Email, phone and WhatsApp fields use `dir="ltr"` and `autocomplete`.
- **Show:**
  - Uppercase `dt` labels → sentence case. `DocBadge` → `Badge`.
  - Hand-rolled links/buttons → `ButtonLink`/`Button`, and archive → a danger `Button` that keeps the `useConfirm` flow.
  - Email/phone values isolated with `bdi dir=ltr`, and the grid column gets `minmax(0,1fr)` so long values wrap.
- **Layout:** all three pages use `ApplicationShell` directly instead of the `SalesLayout` pass-through. The navigation context comes from the route registry (Clients › Contacts), so nothing changes there.

**Stores.** The default-location select `control-input` → `control-select` (chevron), the uppercase store code → mono LTR, cards → `surface-card`, and an empty state is added when an organization has no store.

**Organization backups (closes audit X7 for this page).**
- **Layout:** `SalesLayout` + `mx-auto max-w-5xl` → `ApplicationShell width="reading"`. It now sits in the reading column under Settings.
- **Controls:**
  - Swept to `control-input` / `control-select`, with tokenised checkboxes.
  - The history table → `erp-table` with logical cell padding (3 physical `pr-4` removed), and uppercase labels/thead removed.
- **RTL defect fixed:** the row-actions cell used `justify-end rtl:justify-start`. Flex `justify-end` is already direction-aware, so in Arabic the actions were pushed to the start edge, away from the end-aligned header.

**Tests.** `npx tsc --noEmit -p .` and `npm run build` pass. No backend code changed, so no backend tests were run for this batch.

**Browser QA.** Chrome, local org 2.

| Check | Result |
| --- | --- |
| Iframe probe | 4 routes (`/contacts`, `/contacts/create`, `/settings/stores`, `/organization-backups`) at 390/768/1024/1440/1920, FR light/dark and AR dark (20 combinations): 0 overflow, 0 un-migrated fields, 0 raw tables, 0 uppercase-transformed elements. |
| Contacts filter | Type → "Transport" sets `?contact_type=transport`, adds a chip, and shows the filtered empty state with reset. |
| Contact form | Two-column layout, required marker on Nom complet, hint under the WhatsApp user field, shared checkbox. |
| Backups and Stores, AR dark | Rendered (screenshots …-43 and …-44). |

**Not verified.**
- **No local contacts:** nothing was created for QA, so the contact rows, Contacts/Show, the archive confirm and form submission or validation errors were not rendered.
- **Stores:** save and activate/deactivate were not clicked.
- **Backups:** no backup history exists, so the table rows and row actions were not rendered. Create/restore/Google Drive actions were not triggered.

## 12. Batch 7 — Users, Settings and Integrations (2026-11-13/14)

**Scope (13 pages).** Users & access (+ RoleEditor, swept), Profile, Security, Sessions, Notification preferences, Document profile, PDF Studio, Document stamp, Email configuration, Quotation settings, Return policy, WooCommerce and WooCommerce stock tasks. AiMcp and DataReset stay in batch 8 (Admin).

**Changes.**
- **Shared controls:** a control/table sweep on all 14 files, tokenised palettes (Security, Studio, StockTasks), and uppercase labels/theads removed.
  - Class constants in QuotationSettings and ReturnPolicy now point to `control-input` / `control-select`.
  - The Studio's number-with-unit fields became `control-input control-compact`.
- **Users & access:**
  - Role selects in member rows and cards had no accessible name. They now have `aria-label` "Rôle de {name}" (new fr/ar key `usersAccess.roleFor`) and use `control-compact`.
  - Role changes still go through `submitProtected` (fresh auth).
- **Defects fixed:**
  - **PDF Studio:** used the undefined token `text-ink-subtle` (3×), so those labels got no designed colour. Now `text-ink-faint`. Canvas backdrop `bg-slate-*` → `bg-line` tokens.
  - **Document profile:** overflowed at 390px (123px in French, 60px in Arabic). One cause was a `<fieldset>` with its default `min-width: min-content`, fixed with `min-w-0`. The other was native file inputs with intrinsic width, also on Document stamp (32px), fixed with full width plus a design-system file button.
  - **Sweep script bug:** it turned `bg-surface/95` into an invalid `surface-card/95` on Document profile's sticky save bar, so the bar lost its background. Fixed. A repo-wide search found no other instance, and the script now requires a class boundary.
- **WooCommerce stock tasks:**
  - Rebuilt on `useQueryFilters` + `AdvancedFilterBar`, with an accessible segmented status control (`aria-pressed`) and the same `search`/`status` params (default `pending`, cleared on reset).
  - Also `TableShell` + `TablePager`, `Badge`, `Button`/`ButtonLink`, and a numeric movement column.

**Tests.** `npx tsc --noEmit -p .` and `npm run build` pass. No backend code changed.

**Browser QA.** Chrome, local org 2.

| Check | Result |
| --- | --- |
| Iframe probe | 13 pages at 390 (FR light and AR dark), 768 AR dark, 1024 FR dark, 1440 and 1920 FR light: 0 overflow (after the fixes), 0 un-migrated fields, 0 raw tables, 0 uppercase. |
| Users & access | Every select has an accessible name. The "Retirer" confirm (audit X2) opens with focus on Annuler, and Escape cancels: dialog closed, focus back on Retirer, 3 members unchanged. |
| WooCommerce stock tasks, dark (before the rebuild) | Active tab rgb(6,20,19) on rgb(63,184,171): the X1 fix is confirmed. |
| PDF Studio | Renders, with the canvas in tokens. |

**Not verified.**
- **WooCommerce stock tasks rows:** there are no local tasks, so rows and "Marquer comme fait" were not rendered. The rebuilt page itself was re-checked on 2026-11-14: "Mis à jour" sets `?status=completed` with `aria-pressed` and a chip, and reset returns to `pending` with no query string. 0 overflow at 390 FR/AR and 768 AR.
- **No form submitted:** none on any settings page. Profile, security, 2FA, email test, stamp upload and Studio publish were not exercised.
- **RoleEditor:** not opened.

## 13. Batch 8 — Remaining pages (2026-11-14)

**Scope.**
- **Work:** Index, Attention, Recurring, Team, TeamMember, Templates.
- **Others:** Notifications, Activity, System health, Data reset, AI & MCP (+ Authorize), Sandbox (`/test-mode`).
- **New:** branded HTTP error pages.
- **Brand pass:** Auth/Legal.

**Defects fixed.**
- **/notifications was a blank page.** The page sends its paginated list as the `notifications` prop, which overwrites the shared `notifications` summary. The header `NotificationBell` then crashed on `initial.recent.filter` (`TypeError`), so the page never rendered. This was already broken before this batch; the feature test only checks props, not the UI.
  - **Fix:** `ApplicationShell` only passes a value with a `recent` array to the bell, and otherwise uses the empty default (the bell fills itself from `/notifications/feed`). No backend change; the page prop name the test asserts is unchanged.
- **Notifications page:**
  - **Filters:** status buttons → an accessible segmented control (`aria-pressed`). Category/priority → `ChoiceFilter`, with chips, reset and a filtered empty state.
  - **Translations:** priority values showed raw English ("critical"); fr/ar labels added (`notifications.severity.*`, `notifications.priority`, `notifications.category`).
  - **Badges:** severity → `Badge` variants.
  - **Screen readers:** the unread dot now has a screen-reader label.
- **Error pages:** 403/404/419/429/500/503 showed the unstyled Laravel default. New `resources/views/errors/*.blade.php` use a self-contained branded layout:
  - Design-token colours, the saved theme and language applied before paint, and FR/AR copy with RTL.
  - "Retour au tableau de bord" and "Page précédente" actions.
  - No exception-handling, routing or status-code change.
- **Sandbox:**
  - **Actions:** buttons were styled as text inputs (`control-input`) → `Button`.
  - **Colours:** hard-coded amber palette → warning tokens / `Badge`.
  - **Copy:** French-only → fr/ar keys (`sandboxPage.*`).
  - **Layout:** kept standalone on purpose (it runs inside the isolated test context), with the same POSTs.
- **Work Index and Team:** the "Rechercher" submit forms → `AdvancedFilterBar`, with debounced `query` and live assignee / store / scope / signal filters, chips, reset and a filtered empty state. Same params; `assigned_to` still defaults to `me` on the server.
  - The Work Index pager is now `TablePager`.
- **Shared controls:** the `fieldClass` constant on all six Work pages and `inputClass` on Activity now point to `control-input` / `control-select`, with a select variant.
- **Sweep:** control/table sweep and uppercase removal across Activity, System health, Data reset (+ SelectiveReset), AI & MCP, and the followups/attention components.
- **Auth/Legal:** the uppercase "ou" divider on Login/Register and the tracked uppercase eyebrow on Legal pages → sentence case.

**Tests.**
- `npx tsc --noEmit -p .` and `npm run build` pass.
- `php artisan test tests/Feature/Notifications tests/Feature/FollowUps tests/Feature/WorkCenter tests/Feature/Sandbox`: 92 passed, 1825 assertions.

**Browser QA.** Chrome, local org 2.

| Check | Result |
| --- | --- |
| Iframe probe | 12 routes (6 Work/Notifications, Activity, System health, Data reset, AI & MCP, `/test-mode`, a 404) at 1440 FR light and 390 AR dark: all render, 0 overflow, 0 un-migrated fields, 0 raw tables, 0 uppercase. |
| Notifications | Renders again, with the filter bar. |
| 404 | Branded page in FR light, and in AR dark with RTL; no overflow. |
| Sandbox (Production state) | Renders with the new layout. The policy button was not clicked (it changes an org setting). |
| Work | Typing "relance" applies `query=relance` live with the tab kept, adds a chip, and shows the filtered empty state. |

**Not verified.**
- **Widths:** 768/1024/1920 were not run for this batch. The browser renderer froze under memory pressure, so the probe was limited to 1440 and 390.
- **No local data:** no follow-ups, notifications, activity rows or attention items exist locally, so the row-level UI was not rendered.
- **Sandbox active state:** not entered.
- **Other error codes:** 403/419/429/500/503 views were not triggered (only the 404 was).
- **Auth pages:** not opened in the browser. The change is two class edits.
- **Data reset:** the selectable cards keep their state-coloured borders by design (not converted to `surface-card`).

## 14. Final application-wide QA (2026-11-14)

Full report: `ERP_UI_FINAL_QA.md`. Screenshots: `docs/design/final-qa/`.

**Coverage (110 rendered page components):**

| Status | Count |
| --- | ---: |
| VERIFIED | 89 |
| IMPLEMENTED — NOT FULLY VERIFIED | 7 (token- or state-gated auth screens, AiMcpAuthorize) |
| BLOCKED | 11 (no local record: Invoices/DeliveryNotes/CreditNotes/Returns/Exchanges/Transfers/Payments/Contacts Show, ProductCosts/ImportPreview, SupplierInstallmentShow, CommissionEntryShow) |
| APPROVED EXCEPTION | 3 (Pos/Index, Home, Dev/UiGallery) |

**Previously untracked, now probed:** `catalog/tax-rates`, `catalog/units`, `finance/commissions/salespeople/{id}`, `work/team/{member}`. **Untracked sub-components:** `Quotations/Sourcing`, `Quotations/SupplierRecommendations` (10px labels, P2) and `Legal/LegalLayout`.

**Responsive QA:** 56 pages at 768 AR dark, 1024 FR dark and 1920 FR light (batch 8, Sales, Catalogue/Inventory, Procurement + POS), plus Auth at 390/1440. The only findings are the bespoke POS fields (approved exception).

**Defects fixed (P1, accessible names; frontend only):**
- WooCommerce price-mode select.
- `OrderLineGrid` and `Quotations/LineGrid` desktop cell controls. The `OrderLineGrid` tax select also moved to `control-select control-compact`.
- Returns/Create quantity input.
- RecurringObligations filter selects (4) and the SupplierInstallments supplier filter.
- CommissionRules tier inputs.
- PDF Studio hex colour input.

All were re-checked in the browser.

**Tests:**
- `npx tsc --noEmit -p .` pass.
- `npm run build` pass.
- `php artisan test` (full, serial): 2150 passed, 16175 assertions. It ran before the frontend-only fixes.
