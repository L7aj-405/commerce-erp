# 10xScale ERP — UI/UX Audit against Design System V1.0

Date: 2026-11-13 · Scope: every Inertia page of the ERP · Reference: approved Liquid Glass Design System V1.0
(`docs/design/approved-liquid-glass-dashboard.png`, `docs/design/VISUAL_SPEC.md`).

## 1. Inventory and method

| Item | Count | How it was obtained |
| --- | ---: | --- |
| Registered routes (all verbs) | 487 | `php artisan route:list --json` |
| GET routes | 195 | same |
| GET routes whose controller action calls `Inertia::render` directly | 110 | static scan of each action body |
| Page files in `resources/js/pages` | 130 | file system |
| Page components actually rendered by a controller | 108 | static scan of `Inertia::render('…')` |
| Sub-components living under `pages/` (not routes) | 22 | e.g. `Pos/*Panel`, `Quotations/LineGrid`, `Sales/Orders/OrderLineGrid`, `Settings/UsersAccess/RoleEditor`, `Dev/UiGallery`, `Home` |

Some routes render through helpers or redirects (exports, PDFs, JSON lookups), so the 110 figure is a floor for page routes, not every reachable screen.

**Two kinds of evidence are kept separate in the tables:**

- **Browser** — opened in Chrome on the local dev install (`127.0.0.1:8000`, seeded org 2, user `owner@erp.local`). The QA column says which conditions were checked: FR/AR (RTL), Light/Dark, and widths.
- **Source** — inspected in code only. Counted markers per file: `AdvancedFilterBar`, `TableShell`, legacy `<Pagination>`, legacy `SearchInput`/`FilterSelect`, native `<select>`, native `date`/`month` inputs, `uppercase` micro-labels, legacy card markup, physical `left/right` classes, hard-coded palette classes (`slate-*`, `bg-white`, `red-*`, …), and `window.confirm`. A source finding is a strong signal, not a visual verdict.

Pages that only received source inspection are **not** claimed as visually checked.

### Priority scale

- **P0** – blocking interaction or broken functionality.
- **P1** – serious usability or accessibility defect (illegible control, native blocking dialog, missing focus/keyboard path, RTL misplacement that changes meaning).
- **P2** – design inconsistency or polish (legacy filter bar, legacy pagination, native-looking control, uppercase labels).

### Migration status legend

- **Migrated** – uses V1.0 tokens, shared filter bar (where it is an index), `TableShell`/`erp-table`, shared controls.
- **Partial** – token sweep done (cards, labels, logical alignment) but filters, tables or controls are still legacy.
- **Legacy** – pre-V1.0 markup.
- **N/A** – not an ERP workspace screen (auth, legal), out of scope for the workspace system.

## 2. Executive summary

> **Pass 2 (2026-11-13):** the P1 items X1 and X2 are resolved, X6 is implemented, and every native date input now uses the shared DatePicker (X4). Execution status per page lives in `ERP_UI_MIGRATION_TRACKER.md`. The bullets below describe the state at the first audit.

> **Batch 2 (2026-11-13):** the Sales/Documents pages are migrated (tracker §7). New P2 finding: out-of-scope records show Laravel's unstyled default 404 page.

> **Batch 3 (2026-11-13):** Catalogue and Inventory are migrated (tracker §8). This closes audit X7 for NonStockItems; OrganizationBackups and Sandbox are still open. The ProductCosts legacy filter bar and pagination are replaced.

> **Batch 4 (2026-11-13):** Procurement is migrated (tracker §9). The Articles hors stock dark-mode P1 is browser-verified fixed, and every hard-coded palette class in Procurement is tokenised (99 removed, 0 remaining).

> **Batch 5 (2026-11-13):** Finance and Payments are migrated (tracker §10).
> - **Rebuilt:** six report lists, the overview and Payments now use the standard filter bar and table shell; the month-end situation got an incremental presentation pass.
> - **RTL fixes:** the misaligned amount headers on the month-end tables and the overview reconciliation amount are fixed.
> - **Overflow fix:** CommissionRules no longer overflows at 390px.
> - **Backend item:** Finance pagination links dropped `month`/`store_id`. Fixed 2026-11-14 with the user's approval (`withQueryString()` on 5 paginators).

> **Batch 6 (2026-11-13):** Contacts, Stores and Organization backups are migrated (tracker §11).
> - **X7:** closed for OrganizationBackups, which now uses `ApplicationShell` in reading width. Sandbox is still open.
> - **Fixed:** the Contacts list showed the raw value "transport" for that contact type.
> - **Fixed:** in Arabic, the backups row actions sat on the wrong edge.

- **Migration coverage:** 12 of 108 rendered pages use `AdvancedFilterBar` and 13 use `TableShell`. These are the Sales, Catalogue and Inventory index pages plus My Commissions. About 30 more carry the V1.0 token sweep (Partial).
- **No P0 defect was found** on any page opened in the browser. Every page loads, and its main actions are reachable.
- **P1, dark-mode illegible controls (5 files, browser-confirmed on 2 of them).** `bg-ink … text-white` and `bg-slate-900 text-white` invert in dark mode: `ink`/`slate-900` become near-white, so the label turns white on white.
  - Browser-confirmed: the "Filtrer" button on Activity, and the active segmented tab on WooCommerce Stock.
  - Source-confirmed: Payments index, Procurement › Articles hors stock (2), and `components/pos/primitives.tsx`.
- **P1, native `window.confirm` for destructive or important actions (6 calls).**
  - Product archive, Contact archive, POS hold sale, POS delete held sale, cancel invitation, remove member.
  - They block the page and ignore the design system. `Dialog` already exists and is used elsewhere (Invoice issue, Delivery note issue/cancel, Return receive/cancel).
- **P1, fixed during this pass: RTL popover offset.** `useFloatingPanel` placed every portalled menu, filter picker and the new MonthPicker 15px too far toward the end in Arabic. Cause: Chrome puts the classic scrollbar on the left in RTL documents, and `position: fixed` offsets start after it. Fixed in the shared hook and browser-verified at 390, 768, 1024 and 1440px.
- **P2, the biggest remaining migration debt.**
  - **Lists:** Procurement, Finance, Payments, Contacts, Work, Activity and Settings still use the legacy list pattern (separate search, native selects, an explicit "Filtrer" submit, the big teal page-number button) instead of `AdvancedFilterBar` + `TableShell` + `TablePager`.
  - **Hard-coded palettes:** Procurement › Articles hors stock (56 classes), WooCommerce Stock (33), Suppliers (24), Payments › Show (18), Procurement index (14), Security (10). They survive in dark mode only through the legacy slate remap in `app.css`, which is what causes the inverted buttons above.

## 3. Navigation shell (Tasks A–C, this pass)

| Area | Change | Evidence |
| --- | --- | --- |
| Dashboard period | Native `<input type="month">` replaced by `components/ui/MonthPicker.tsx`. Glass pill with prev/next month steppers; floating month grid with year navigation; "Mois en cours" shortcut; future months disabled. Same `YYYY-MM` value and `router.get('/platform', { month })` call as before. | **Browser.** FR/AR, Light/Dark, 390/768/1024/1440. Keyboard: Enter opens with focus on the selected month, arrows (mirrored in RTL), PageUp/PageDown, Enter selects, Escape closes and restores focus. Popover stays inside the viewport at every width. |
| Sidebar hover | Per-element enter/leave handlers on three overlapping layers replaced by one document-level pointer tracker:<br>• 18px edge zone below the header row<br>• drawer + 32px corridor<br>• 180ms open / 380ms close<br>• the edge must be left once after a close (no flicker loop)<br>• no invisible strip or transparent backdrop in hover mode<br>• pinned state ignores hover<br>• touch and non-mouse pointers ignored. Rail moved 20→24px inward. | **Browser.** 8 scripted scenarios at 1920px, all passing: dwell opens; corridor keeps open; leaving closes; returning reopens; Escape while parked on the edge stays closed; re-arm after leaving; rapid moves inside the drawer keep it open; quick pass over the edge does not open; rail icon hover does not open. |
| Menu button | Persistent toggle before the logo at every breakpoint. "Ouvrir le menu" / "Fermer le menu", `aria-expanded`, active state. Opens the drawer pinned; the drawer's leading toggle closes it; Escape restores focus to the button. | **Browser.** FR/AR (mirrored to the right edge), Light/Dark, all four widths. |

## 4. Page-by-page audit

Columns: **Page** (component · route) · **Module** · **Current state** · **Issues** · **Priority** · **Suggested fix** · **Migration status** · **QA**.

### 4.1 Dashboard and work

| Page | Module | Current state | Issues | Priority | Suggested fix | Migration status | QA |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Platform/Index · `/platform` | Dashboard | V1.0 reference implementation; MonthPicker | Backend attention labels: only 3 known labels are localised client-side | P2 | Return translation keys from `ExecutiveDashboardService` (needs backend approval) | Migrated | Browser · FR/AR · Light/Dark · 390–1440 |
| Work/Index · `/work` | Work | Legacy list; uses Dialog | Legacy pagination; 1 native select | P2 | AdvancedFilterBar + TablePager | Legacy | Source |
| Work/Attention, Recurring, Team, TeamMember, Templates | Work | Legacy lists | Legacy pagination; 1–4 native selects each; 2 native dates on TeamMember | P2 | Same as Work/Index, one batch | Legacy | Source |
| Notifications/Index · `/notifications` | Notifications | Small page | 2 native selects; legacy cards | P2 | ChoiceFilter + surface-card | Legacy | Source |

### 4.2 Sales

| Page | Module | Current state | Issues | Priority | Suggested fix | Migration status | QA |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Sales/Orders/Index · `/sales/orders` | Sales | AdvancedFilterBar, TableShell, mobile cards | — | — | — | Migrated | Browser (earlier sessions; AR this session) |
| Sales/Orders/Show · `/sales/orders/{id}` | Sales | Two-column detail, dialogs | 1617 lines; 5 native selects in fulfilment/payment forms; 12 legacy card markups in sub-panels; 6 hard-coded colours | P2 | Split into panels; shared Select; surface-card | Partial | Browser (earlier session) |
| Sales/Orders/Edit · `/sales/orders/{id}/edit` | Sales | Token sweep; RTL header alignment fixed | Native customer/salesperson selects; native date | P2 | Shared Select / DateField | Partial | Browser · FR/AR |
| Sales/Orders/Complete · `/sales/orders/{id}/completion` | Sales | Token sweep | 1 legacy card | P2 | — | Partial | Source |
| Quotations/Index · `/quotations` | Sales | AdvancedFilterBar, TableShell | — | — | — | Migrated | Browser (earlier session) |
| Quotations/Create · `/quotations/create` | Sales | Reading-width form, surface-card | 2 native date inputs | P2 | DateField component (next shared control) | Partial | Browser · AR |
| Quotations/Show · `/quotations/{id}` | Sales | Document preview + actions | 1503 lines; 8 legacy cards; paper uses physical alignment (intentional for print) | P2 | Panelise the action side; leave the paper | Partial | Browser (earlier session) |
| Documents/Invoices/Index · `/invoices` | Sales | AdvancedFilterBar, TableShell | — | — | — | Migrated | Browser (earlier session) |
| Documents/Invoices/Show · `/invoices/{id}` | Sales | Issue confirm Dialog; doc paper | 9 legacy cards; 8 physical classes inside the paper (print layout) | P2 | Panelise side; keep the paper LTR-print | Partial | Browser (earlier session) |
| Documents/DeliveryNotes/Index · `/delivery-notes` | Sales | AdvancedFilterBar, TableShell | — | — | — | Migrated | Browser (earlier session) |
| Documents/DeliveryNotes/Show | Sales | Rewritten; issue/cancel dialogs | 1 native date | P2 | DateField | Migrated | Browser (earlier session) |
| Sales/Returns/Index · `/sales/returns` | Sales | TableShell + filter bar | — | — | — | Migrated | Browser (earlier session) |
| Sales/Returns/Create · `/sales/orders/{id}/returns/create` | Sales | Token sweep | 1 native select | P2 | Shared Select | Partial | Source |
| Sales/Returns/Show | Sales | Receive/cancel dialogs | 4 legacy cards in sub-sections; 1 native select | P2 | surface-card | Partial | Browser (earlier session) |
| Documents/CreditNotes/Show | Sales (returns) | Header refined | 1 legacy card | P2 | — | Partial | Browser (earlier session) |
| Sales/Exchanges/Create · `/sales/orders/{id}/exchanges/create` | Sales | Token sweep | 1 native select | P2 | — | Partial | Source |
| Sales/Exchanges/Show · `/sales/exchanges/{id}` | Sales | Token sweep | 3 native selects (payment/refund forms) | P2 | Shared Select | Partial | Source |
| Sales/Customers/Index · `/sales/customers` | Sales | AdvancedFilterBar, TableShell | — | — | — | Migrated | Browser · FR/AR |
| Sales/Customers/Form | Sales | Token sweep | 2 native selects | P2 | — | Partial | Source |
| Sales/Customers/Show | Sales | Large detail (662 lines), 5 raw tables | Raw tables not on `erp-table` | P2 | erp-table + TableShell sections | Partial | Source |
| Commissions/MyCommissions · `/my-commissions` | Sales | TableShell; shared CommissionFilters/Metric restyled | Commission filters still a legacy form with an "Appliquer" button | P2 | Port CommissionFilters to AdvancedFilterBar + DateRangeFilter | Partial | Browser · FR/AR |
| Pos/Index · `/pos` | POS | Dedicated full-screen workflow (PosLayout) | **2 × `window.confirm`** (hold sale, delete held sale) | **P1** | Replace with shared `Dialog` confirm | Partial | Source (POS sub-components not re-checked) |

### 4.3 Catalogue and products

| Page | Module | Current state | Issues | Priority | Suggested fix | Migration status | QA |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Catalog/Products/Index · `/catalog/products` | Catalogue | AdvancedFilterBar (status/category/brand), TableShell | — | — | — | Migrated | Browser · FR/AR |
| Catalog/Products/Show · `/catalog/products/{id}` | Catalogue | Token sweep, RTL fixes | **`window.confirm` for archive** | **P1** | Dialog confirm | Partial | Browser · FR/AR |
| Catalog/Products/Form · create/edit | Catalogue | Hard-coded colours replaced by tokens | 5 native selects | P2 | Shared Select | Partial | Source |
| Catalog/ReferenceData · `/catalog/categories`, `/catalog/brands` | Catalogue | Side form + table; header alignment fixed | 3 native selects; raw table | P2 | erp-table; shared Select | Partial | Browser · FR (Light) |
| Catalog/ProductCosts/Index · `/catalog/purchase-prices` | Catalogue | Legacy filter bar (search + 4 selects), KPI tiles | Legacy SearchInput/FilterSelect; legacy pagination | P2 | AdvancedFilterBar (backend params unchanged) + TablePager | Partial | Browser · FR (Light) |
| Catalog/ProductCosts/ImportPreview | Catalogue | Token sweep | Legacy pagination | P2 | TablePager | Partial | Source |
| Catalog/Imports/Wizard · `/catalog/products/import` | Catalogue | Hard-coded colours → tokens | 3 native selects; 3 raw tables | P2 | Shared Select; erp-table | Partial | Browser · FR (step 1 only) |
| Catalog/NonStockItems/Index · `/catalog/non-stock-items` | Catalogue | AdvancedFilterBar, TableShell, inline edit with shared controls | Rendered inside SalesLayout although listed under Catalogue nav | P2 | Use CatalogLayout (layout-only change) | Migrated | Browser · FR (Light) |

### 4.4 Stock and inventory

| Page | Module | Current state | Issues | Priority | Suggested fix | Migration status | QA |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Inventory/Stock/Index · `/inventory/stock` | Inventory | AdvancedFilterBar (warehouse/availability/category/brand), TableShell | KPI tiles "Disponible/Réservé" sum the current page only (existing behaviour, labelled "visibles") | P2 | Clarify the label or ask the backend for totals (needs approval) | Migrated | Browser · FR/AR |
| Inventory/Movements/Index · `/inventory/movements` | Inventory | AdvancedFilterBar with backend-supported search; localised movement types | — | — | — | Migrated | Browser · FR/AR |
| Inventory/Transfers/Index · `/inventory/transfers` | Inventory | AdvancedFilterBar, TableShell, RTL arrow fixed | — | — | — | Migrated | Browser · FR |
| Inventory/Transfers/Create | Inventory | Token sweep | 2 native selects | P2 | Shared Select | Partial | Source |
| Inventory/Transfers/Show | Inventory | Token sweep | Raw table | P2 | erp-table | Partial | Source |
| Inventory/TransferRequests/Index · `/inventory/transfer-requests` | Inventory | AdvancedFilterBar + quick-filter tiles, TableShell, RTL priority bar | — | — | — | Migrated | Browser · FR/AR |
| Inventory/TransferRequests/Form | Inventory | Token sweep | 2 native selects | P2 | Shared Select | Partial | Source |
| Inventory/TransferRequests/Show | Inventory | Token sweep (860 lines) | Raw table; 1 native select | P2 | erp-table | Partial | Source |
| Inventory/Warehouses/Index · `/inventory/warehouses` | Inventory | Card grid | 2 native selects; uses `<details>` for edit | P2 | Dialog/Sheet edit | Partial | Browser · AR |

### 4.5 Procurement — next priority

| Page | Module | Current state | Issues | Priority | Suggested fix | Migration status | QA |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Procurement/Index · `/procurement` | Procurement | Legacy search + full-width native status select | Legacy pagination (big teal "1"); 14 hard-coded colours; legacy cards | P2 | AdvancedFilterBar + TableShell + TablePager | Legacy | Browser · FR (Light) |
| Procurement/OutOfStockArticles/Index · `/procurement/out-of-stock-articles` | Procurement | Segmented tabs + search | **`bg-slate-900 text-white` active state inverts in dark mode (2)**; 56 hard-coded colour classes, no `dark:` variants | **P1** | Tokenise (`bg-primary text-primary-fg` for active); AdvancedFilterBar | Legacy | Browser · FR (Light, empty state only) |
| Procurement/PurchaseOrders/Index · `/procurement/purchase-orders` | Procurement | Legacy filters | Legacy pagination; native select | P2 | AdvancedFilterBar + TableShell | Legacy | Source |
| Procurement/PurchaseOrders/Show | Procurement | Legacy detail | Native select + native date; 3 legacy cards | P2 | Panels + Dialog actions | Legacy | Source |
| Procurement/SupplierRequests/Index · `/procurement/supplier-requests` | Procurement | Large list with Dialog (604 lines) | 2 native dates; 4 uppercase labels; legacy pagination | P2 | AdvancedFilterBar + DateRangeFilter | Legacy | Source |
| Procurement/Suppliers/Index · `/procurement/suppliers` | Procurement | Search + table | 24 hard-coded colours (rendered acceptably in dark through the slate remap); text-link actions "Modifier"; legacy pagination | P2 | Tokenise; TableShell; Button actions | Legacy | Browser · FR · Light/Dark |
| Procurement/Suppliers/Show | Procurement | Legacy detail | 3 uppercase labels; legacy pagination | P2 | — | Legacy | Source |

### 4.6 Finance and payments

| Page | Module | Current state | Issues | Priority | Suggested fix | Migration status | QA |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Finance/Situation · `/finance` | Finance | Overview with charts | 2 legacy cards | P2 | surface-card | Partial | Source |
| Finance/Ventes · `/finance/ventes` | Finance | Native month input + store select; table | Native `type="month"` (same defect Task A fixed on the dashboard); legacy pagination | P2 | Reuse `MonthPicker`; TablePager | Legacy | Browser · FR (Light) |
| Finance/CaEncaisse, Creances, Encaissements, Facturation, Journal | Finance | Same legacy report-list pattern | Legacy pagination; 1–3 uppercase labels; legacy cards | P2 | One batch: MonthPicker + TableShell + TablePager | Legacy | Source |
| Finance/MonthlySituation · `/finance/situation-fin-de-mois` | Finance | 2640-line workspace | 5 native date inputs; 13 physical classes (RTL risk); 4 native selects | P1 (RTL) / P2 | Logical classes first; split file | Legacy | Browser · FR · Dark (top section only) |
| Finance/RecurringObligations, SupplierInstallments, SupplierInstallmentShow | Finance | Legacy forms/lists | Native dates/selects; legacy pagination | P2 | DateField; AdvancedFilterBar | Legacy | Source |
| Finance/CommissionDashboard · `/finance/commissions` | Finance | 1039 lines | 25 physical classes (RTL misalignment likely); 6 uppercase labels; 5 raw tables | **P1** (RTL) | Logical classes; erp-table | Legacy | Source |
| Finance/CommissionLedger, CommissionRules, CommissionSalesperson, CommissionEntryShow | Finance | Legacy | Native dates (Rules: 3, Ledger: 2); legacy pagination; 4 physical classes on Salesperson | P2 | CommissionFilters → AdvancedFilterBar | Legacy | Source |
| Payments/Index · `/payments` | Payments | Search + 4 selects + date + explicit "Filtrer" submit | **`bg-ink text-white` submit inverts in dark mode** (source); native date; legacy pagination | **P1** | Button primary; AdvancedFilterBar (live filters) | Legacy | Browser · FR (Light) |
| Payments/Show · `/payments/{id}` | Payments | Detail | 18 hard-coded colours, no `dark:` variants | P2 | Tokenise | Legacy | Source |
| Payments/FinancialAccounts/Index · `/financial-accounts` | Payments | 672-line page | Native select/date; 3 uppercase labels | P2 | — | Legacy | Source |

### 4.7 Contacts

| Page | Module | Current state | Issues | Priority | Suggested fix | Migration status | QA |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Contacts/Index · `/contacts` | Contacts | Legacy search + 2 selects + "Rechercher" button | Legacy pagination; uppercase label | P2 | Same pattern as Customers/Index | Legacy | Browser · FR · Dark (empty state) |
| Contacts/Show | Contacts | Small detail | **`window.confirm` for archive** | **P1** | Dialog confirm | Legacy | Source |
| Contacts/Form | Contacts | Legacy form | 3 native selects; uppercase label | P2 | — | Legacy | Source |

### 4.8 Organisation, store, users and settings

| Page | Module | Current state | Issues | Priority | Suggested fix | Migration status | QA |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Settings/UsersAccess/Index · `/organizations/{id}/users-access` | Admin | Invite form, members table, roles | **2 × `window.confirm`** (cancel invitation, remove member); native selects inside table rows | **P1** | Dialog confirm; Menu for row role change | Partial | Browser · FR · Dark |
| Settings/UsersAccess/RoleEditor (sub-component) | Admin | Permission matrix | Not opened | — | Audit with the module batch | Legacy | Not checked |
| Settings/Stores/Index · `/settings/stores` | Settings | Card per store with inline form | 1 native select; uppercase label | P2 | — | Partial | Browser · FR · Dark |
| Documents/Profile · `/document-profile` | Settings | Large form | 3 legacy cards | P2 | — | Legacy | Source |
| Documents/TemplateStudio | Settings | 3029-line editor | 7 uppercase labels; 3 hard-coded colours; 2 physical classes | P2 | Split before restyling | Legacy | Source |
| Settings/DocumentStamp, EmailConfiguration, QuotationSettings, ReturnPolicy, NotificationPreferences | Settings | Legacy forms | Legacy cards; a few native selects | P2 | Reading-width + surface-card sweep | Legacy | Source |
| Settings/Integrations/WooCommerce · `/integrations/woocommerce` | Integrations | Legacy | 4 native selects | P2 | — | Legacy | Source |
| Settings/Integrations/WooCommerceStockTasks · `/integrations/woocommerce/stock-tasks` | Integrations | Segmented tabs + table | **Active tab illegible in dark mode** (white label on white); 33 hard-coded colours; legacy pagination | **P1** | Tokenise; AdvancedFilterBar | Legacy | Browser · FR · Dark (confirmed) |
| Settings/OrganizationBackups | Admin | 571 lines | Uses SalesLayout (wrong navigation context); 3 physical classes; 6 legacy cards | P2 | ApplicationShell `reading`; logical classes | Legacy | Source |
| Settings/DataReset, AiMcp | Admin | Dialog-based | Native date on AiMcp | P2 | — | Partial | Source |
| Settings/Profile, Security, Sessions (AccountLayout) | Account | Reading-width account pages | Security: 10 hard-coded colour classes (rendered acceptably in dark through the slate remap) | P2 | Tokenise | Partial | Browser · FR · Dark (Security) |
| Activity/Index · `/activity` | Admin | Filter form + table | **"Filtrer" button illegible in dark mode** (`bg-ink text-white`, confirmed); backend action labels in English ("login succeeded"); legacy pagination; 2 native dates | **P1** | Button primary; AdvancedFilterBar + DateRangeFilter; label map | Legacy | Browser · FR · Dark (confirmed) |
| SystemHealth/Index · `/system-health` | Admin | Small page | 3 legacy cards | P2 | — | Legacy | Source |
| Sandbox/Index · `/sandbox` | Admin | Standalone page (no layout) | 6 hard-coded colours | P2 | ApplicationShell | Legacy | Source |

### 4.9 Out of workspace scope

| Page | Module | Current state | Issues | Priority | Suggested fix | Migration status | QA |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Auth/* (9 pages), Settings/AiMcpAuthorize | Auth | AuthLayout | Login/Register use an uppercase label | P2 | Brand pass on AuthLayout only | N/A | Source |
| Legal/Privacy, Legal/Terms | Public | LegalLayout | — | — | — | N/A | Source |

## 5. Cross-cutting findings

| # | Finding | Scope | Priority | Fix |
| --- | --- | --- | --- | --- |
| X1 | `bg-ink`/`bg-slate-900` + `text-white` buttons invert in dark mode | Activity, Payments, Procurement › Articles hors stock (2), WooCommerce Stock, `components/pos/primitives.tsx` | ~~P1~~ **Resolved (pass 2)** | All six now use `bg-primary text-primary-fg`. Browser-verified on Activity (dark). |
| X2 | Native `window.confirm` | 8 calls: Products/Show, Contacts/Show, Pos/Index (2), UsersAccess (2), plus FulfillmentCard (remove proof) and RoleEditor (delete role), which the first pass missed | ~~P1~~ **Resolved (pass 2)** | `useConfirm()` (`components/ui/ConfirmDialog.tsx`). Browser-verified on Products/Show. |
| X3 | Legacy `<Pagination>` (big teal page-number buttons) | 30 pages | P2 | `TablePager` (paginator already exposes from/to/total) |
| X4 | Native `date`/`month` inputs | 32 occurrences on 18 pages (the full count was ~40 `date` + 1 `month`) | **Resolved (pass 2)** | Shared `DatePicker`/`DateField`, plus `MonthPicker` for Finance. `time` and `datetime-local` stay native as documented exceptions (see the tracker). |
| X5 | Hard-coded palettes relying on the dark-mode slate remap | ~10 pages | P2 (P1 where X1 applies) | Token sweep per module (same script as Catalogue/Inventory) |
| X6 | Physical `left/right` classes | CommissionDashboard (25), MonthlySituation (13), Invoices/Quotations paper (intentional), others | ~~P1~~ **Implemented (pass 2)** | Converted to `text-start`/`text-end`/`ms-*` on those two files, CommissionSalesperson, RecurringObligations, UsersAccess and SystemHealth. The table rows still need a browser check with commission data. |
| X7 | Wrong layout context | NonStockItems (SalesLayout under Catalogue), OrganizationBackups (SalesLayout), Sandbox (none) | P2 | Correct layout component; no route change |
| X8 | Fixed this pass | `th` default centring (base rule); RTL `.num` alignment; RTL popover offset in `useFloatingPanel` | — | Done |

## 6. Recommended next batch

**Batch 1 — cross-cutting P1 fixes (small, high value):**
- X1: five inverted buttons/tabs.
- X2: six native confirms → shared Dialog.
- X6 for CommissionDashboard and MonthlySituation: logical classes only, no restructuring.

**Batch 2 — Procurement module:**
- Index, Articles hors stock, Purchase orders (index + show), Supplier requests, Suppliers (index + show).
- Same recipe as Inventory: AdvancedFilterBar with exactly the params each controller validates, TableShell/TablePager, token sweep, and RTL/dark QA.

**Batch 3 — Finance report lists:**
- Ventes, CA encaissé, Créances, Encaissements, Facturation, Journal.
- Use `MonthPicker` for their `month` param, plus TableShell/TablePager.

Remaining families after that: Payments, Contacts, Work, Settings/Integrations, Activity, then the large single files (Orders/Show, Quotations/Show, TemplateStudio, MonthlySituation), which should be split before any restyling.

## 7. Limitations

- Browser QA covered the pages marked **Browser** above, with the seeded local data. Many lists were empty locally (Contacts, Payments, Articles hors stock, WooCommerce tasks), so row-level rendering of those pages was **not** observed.
- Mobile (390px) and Arabic were checked on the migrated Sales/Catalogue/Inventory pages and the shell. Legacy families were checked at 1920px in French only, unless the QA column says otherwise.
- Screen-reader behaviour was checked through accessible names and roles in the DOM, not with an actual screen reader.
- POS sub-components and the RoleEditor were not opened.

> **Batch 7 (2026-11-14):** Users, Settings and Integrations are migrated (tracker §12).
> - **X2 (Users & access remove-member confirm):** browser-verified.
> - **X1 (WooCommerce stock tasks dark tab):** browser-verified.
> - **Fixed:** an undefined token in the Studio, Document profile and Document stamp overflow at 390px, and unnamed role selects.

> **Batch 8 (2026-11-14):** the remaining pages are migrated (tracker §13).
> - **Fixed:** /notifications rendered a blank page (header bell crash), the unstyled Laravel error pages are replaced by branded FR/AR views, and the Sandbox page now uses tokens, `Button` and fr/ar copy.
> - **X7 (Sandbox):** its standalone layout is kept on purpose.
