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
| 5 | Finance and Payments lists (AdvancedFilterBar/TablePager) | NOT STARTED | — |
| 6 | Contacts and Organization | NOT STARTED | — |
| 7 | Users, Settings, Integrations | NOT STARTED | — |
| 8 | Remaining pages (Work, Notifications, Activity list, Admin, Auth brand pass) | NOT STARTED | — |

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
| Payments | Payments/Index | date filter (classic form, `name="date"`) | IMPLEMENTED | tsc/build |
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
| Finance | Situation, Ventes, CaEncaisse, Creances, Encaissements, Facturation, Journal | FinanceFilters → MonthPicker | Legacy lists | Legacy pagination, cards | P2 | IN PROGRESS (period control done) | Ventes this session | tsc/build |
| Finance | MonthlySituation | DatePicker | Legacy | 2640 lines; native selects | P2 | IN PROGRESS (dates, X6 done) | Earlier (top section) | tsc/build |
| Finance | Commission Dashboard/Ledger/Rules/Salesperson/EntryShow | DatePicker | Legacy | Raw tables, uppercase labels | P2 | IN PROGRESS (dates, X6 done) | Dashboard/Ledger this session | tsc/build |
| Finance | RecurringObligations, SupplierInstallments, SupplierInstallmentShow | DatePicker, Sheet | Legacy | Native selects, legacy pagination | P2 | IN PROGRESS (dates done) | RecurringObligations this session | tsc/build |
| Payments | Payments/Index, Payments/Show, FinancialAccounts/Index | DatePicker | Legacy | Legacy filter form; Show has 18 hard-coded colours | P2 | IN PROGRESS (P1 X1 + dates done) | Not re-checked | tsc/build |
| Contacts | Contacts/Index, Show, Form | useConfirm (Show) | Legacy | Legacy list/form | P2 | IN PROGRESS (P1 fixed) | Not re-checked | tsc/build |
| Admin | Settings/UsersAccess/Index, RoleEditor | useConfirm | Partial | Native selects in rows; RoleEditor never opened | P2 | IN PROGRESS (P1 fixed) | Not re-checked | tsc/build |
| Admin | Activity/Index | DatePicker | Legacy | Legacy list; English backend action labels | P2 | IN PROGRESS (P1 fixed) | This session (dark) | tsc/build |
| Settings | Stores, DocumentProfile, TemplateStudio, DocumentStamp, EmailConfiguration, QuotationSettings, ReturnPolicy, NotificationPreferences, Profile, Security, Sessions | — | Legacy / Partial | Legacy cards, native selects | P2 | NOT STARTED | Partial (earlier) | — |
| Integrations | WooCommerce, WooCommerceStockTasks | — | Legacy | 33 hard-coded colours on StockTasks | P2 | IN PROGRESS (P1 X1 fixed on StockTasks) | Not re-checked | tsc/build |
| Admin | OrganizationBackups, DataReset, AiMcp, SystemHealth, Sandbox | DatePicker (DataReset) | Legacy | Wrong layouts (Backups, Sandbox) | P2 | NOT STARTED | Source only | — |
| Work | Work/Index, Attention, Recurring, Team, TeamMember, Templates; Notifications | DatePicker | Legacy | Legacy pagination, native selects | P2 | IN PROGRESS (dates done) | Not checked | tsc/build |
| Auth / Legal | Auth/*, Legal/* | AuthLayout | N/A | Uppercase labels | P2 | NOT STARTED (approved out-of-workspace) | Source | — |

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
