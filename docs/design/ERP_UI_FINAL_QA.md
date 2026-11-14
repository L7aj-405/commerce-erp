# 10xScale ERP — Final application-wide UI/UX QA (Design System V1.0)

Date: 2026-11-14 · Environment: local dev (`127.0.0.1:8000`, Laragon MySQL, Vite dev server), Chrome via the Claude-in-Chrome extension, seeded org 2 (`owner@erp.local`, plus `vendeuse@erp.local` for the permission check).
Companion files: `ERP_UI_AUDIT.md` (findings) and `ERP_UI_MIGRATION_TRACKER.md` (execution, §14 for this pass).

## 1. Release-readiness recommendation

**Ready to close the UI migration, with conditions.**

- No P0 is known.
- Every P1 found in this pass is fixed and browser-verified (§6).
- What is still unverified comes from **missing local data**, not from known defects: 11 detail pages have no local record (§2), and several populated row states could not be rendered (§4).
- Before a production release, open those 11 detail pages once on a staging copy that has real invoices, delivery notes, credit notes, returns, exchanges, transfers, payments and contacts. Keep the P2 list (§7) as a normal backlog.

## 2. Route / page coverage

| Item | Count | Source |
| --- | ---: | --- |
| GET routes (all) | 195 | `php artisan route:list` |
| Page components rendered by a controller (`Inertia::render`) | 110 | static scan of `app/` and `routes/` |
| Files in `resources/js/pages` | 130 | file system |
| Sub-components under `pages/` (not routes) | 20 | 12 `Pos/*` panels, `Quotations/LineGrid`, `Quotations/Sourcing`, `Quotations/SupplierRecommendations`, `Sales/Orders/FulfillmentCard`, `OrderLineGrid`, `ProcurementReadinessCard`, `Settings/UsersAccess/RoleEditor`, `Legal/LegalLayout` |
| Rendered components without a page file | 0 | — |

**Status of the 110 page components:**

| Status | Count | Pages |
| --- | ---: | --- |
| VERIFIED (browser, page level) | 89 | All index pages, forms and detail pages with local data in Dashboard, Work (6), Notifications, Activity, Sales (16), Catalogue (7), Inventory (8), Procurement (7), Finance (14), Payments (2), Contacts (2), Settings/Integrations (15), Admin (4), Auth Login/Register/ForgotPassword, Legal (2). |
| IMPLEMENTED — NOT FULLY VERIFIED | 7 | State-gated auth screens: ResetPassword, VerifyEmail, ConfirmPassword, TwoFactorChallenge, AcceptInvitation, SecurityConfirmation, Settings/AiMcpAuthorize (needs a token or an OAuth request). |
| BLOCKED (no local record; creating one would mean a financial document or an upload) | 11 | Invoices/Show (the only invoice is sandbox data), DeliveryNotes/Show, CreditNotes/Show, Returns/Show, Exchanges/Show, Transfers/Show, Payments/Show, Contacts/Show, ProductCosts/ImportPreview, Finance/SupplierInstallmentShow, Finance/CommissionEntryShow. |
| APPROVED EXCEPTION | 3 | `Pos/Index` (specialised full-screen workflow; only the P1 confirm was in scope), `Home` (guest marketing page; signed-in users are redirected to `/platform`), `Dev/UiGallery` (developer route). |

"VERIFIED" means the page was opened in Chrome and checked for overflow, un-migrated fields, raw tables, uppercase labels and theme/direction. It does **not** mean every row state or action was exercised (see §4).

**Gaps found in the tracker:**
- Routes that were not tracked: `catalog/tax-rates` and `catalog/units` (both render `Catalog/ReferenceData`), `finance/commissions/salespeople/{id}` and `work/team/{member}`. All four were probed in this pass and pass.
- Components that were not tracked: `Quotations/Sourcing`, `Quotations/SupplierRecommendations` (both still use 10px micro-labels, P2) and `Legal/LegalLayout`.
- Several rows in tracker §5 were stale (they still showed Settings, Work and Admin as NOT STARTED or IN PROGRESS after batches 7–8). They are corrected.

## 3. Responsive QA (768 / 1024 / 1920)

The method is a same-origin iframe probe at a fixed width. It loads **one page at a time** and removes each iframe after reading it. For every page it checks:
- document horizontal overflow, and visible elements outside the viewport (not counting scroll containers or fixed elements);
- fields not on the shared controls, raw tables, `text-transform: uppercase` labels, text under 11px, clipped buttons and runtime errors.

**Pages probed in this pass:**

| Set | Pages | Widths and conditions | Result |
| --- | ---: | --- | --- |
| Batch 8 (Work ×5, Notifications, Activity, System health, Data reset, AI & MCP, `/test-mode`) | 11 | 768 AR dark · 1024 FR dark · 1920 FR light | 0 issues |
| Sales (dashboard, orders index/show/edit/completion, return and exchange create, quotations index/show/create, invoices, delivery notes, returns, customers index/show/edit, my commissions) | 17 | 768 AR dark · 1024 FR dark · 1920 FR light | 0 issues |
| Catalogue + Inventory (products index/show/edit/create, categories, brands, tax rates, units, purchase prices, import, non-stock items, stock, movements, transfers + create, transfer requests index/show/create, warehouses) | 19 | same three | 0 issues |
| Procurement (index, POs + BCF-000001, supplier requests, out-of-stock articles, suppliers + 2 supplier pages) + POS | 9 | same three | 0 issues, except POS: 2–4 bespoke fields (approved exception) |
| Auth (login, register, forgot password) | 3 | 390 AR dark · 1440 FR light | 0 issues |
| Salesperson commissions, Team member | 2 | 1440 FR light · 390 AR dark | 0 issues |
| Pages edited in this pass | 4 | 390 and 1024 AR dark | 0 overflow, 0 legacy selects |

Finance, Payments, Contacts, Stores, Backups and Settings were already probed at 390/768/1024/1440/1920 in batches 5–7 and were not re-run.

**Probe false positive, corrected.** In RTL, Chrome draws the vertical scrollbar on the left, so content starts 15px in. The first version of the probe counted that offset as overflow on `/work/team` and `/activity`. With the offset accounted for, both pages are clean.

## 4. List states with local data

No data was created for QA. These are the lists that have local rows (checked at 1440 FR light):

| List | Rows | Checked |
| --- | ---: | --- |
| Sales orders | 10 | Status filter "Confirmée" → `?status=confirmed`, 4 rows, chip; removing the chip restores the full list. Status/payment badges, "Ouvrir"/"Facturer" row actions. |
| Quotations | 1 | "Transformé" badge, row link. |
| Customers | 5 | Badges, row links. |
| Products | 6 | Stock/active badges, row links. |
| Purchase prices (`stock=all&cost=all`) | 8 | Rows and the inline "Enregistrer" action render. |
| Stock | 8 | Variant/stock badges; "Transférer"/"Produit" actions. |
| Movements | 13 | Signed quantities and movement-type labels. |
| Transfer requests | 3 | Badges, "Préparer" action, link to SO-000006. |
| Procurement / Purchase orders | 1 / 1 | "Partiellement réceptionné(e)" badges. |
| Suppliers | 2 | "Modifier" action. |
| Supplier requests (`status=all`) | 1 | "Détails", "Plus d'actions" menu. |
| Créances / Ventes | 4 / 4 | "Non payée" badges, amounts; Créances screenshot in AR dark. |
| Activity | 25 per page (127 total) | Legacy numbered pager (P2). Page links keep active filters (`date_from` checked). |

**Sorting:** no ERP list exposes column sorting (no `aria-sort` and no sortable headers). This is existing product behaviour, not a migration regression, so none was added.

**Not rendered (no local data):** rows for Invoices, Delivery notes, Returns, Payments, Contacts, Notifications, Work items, Encaissements, Journal, CA encaissé and Facturation, WooCommerce stock tasks, and Articles hors stock. Their empty and filtered-empty states were verified in batches 4–8.

## 5. Critical workflows

Only safe, reversible operations were used. Nothing was saved, issued, paid or deleted.

| Workflow | Tested | Result |
| --- | --- | --- |
| Command Palette | Ctrl+K (keydown), search "stock", ArrowDown, Enter; Escape | Opens with focus in the search field. Results are grouped (pages + "« stock » dans …" record searches). Enter navigated to Articles hors stock. Escape closes and returns focus to the trigger. |
| Organization/store switcher | Opened | Shared `control-select` pair. The owner has **one org and one store**, so a real switch could not be exercised. |
| POS customer selection | Dialog opened, search typed, Cancel | Hint "Saisissez au moins 2 caractères", recent customers and search results listed. Cancel leaves "Vente au comptoir". |
| POS checkout validation | Empty cart | "Passer au paiement" is disabled. A cart with lines was **not** exercised (adding a line writes to the draft order). |
| Sales document navigation | Orders list → SO-000006 → TRQ-000003 link → back → breadcrumb | All transitions work. The order page links to completion, returns and the transfer request. |
| Product / variant | Product show (identity, availability per variant, variants, suppliers) → "Ajuster le stock" | That link opens Stock filtered to the SKU. The adjustment form (warehouse, variant, in/out, quantity, reason, reference) renders with shared controls. **Not submitted.** |
| Stock movement UI | Movements list (13 rows) and filters | Renders. No movement was created. |
| Procurement forms | Supplier Sheet and PO detail (batch 4); procurement pages re-probed at 3 widths | No form submitted. |
| Finance period | Ventes "Mois précédent" | `?month=2026-10`, month chip, empty state. The URL also carries an empty `store_id=` (cosmetic, P2). |
| User permissions | Signed in as the Sales Employee test account | `/finance`, `/organizations/2/users-access` and `/settings/data-reset` return 403 with the branded page. The rail still shows Finance, but it resolves to the permitted child `/payments`. Quick actions show only permitted entries. Owner session restored afterwards. |
| Users & access / RoleEditor | Opened a system role | Inline editor; system role locked (154/154 permission checkboxes disabled) with an explanation. |
| Settings & Integrations | WooCommerce, Settings pages (probe) | Render with shared controls. No settings saved, no connection tested. |

**Not exercised:** a real org/store switch, the POS cart and payment, any submit (stock adjustment, PO receive, payments, month close, settings saves, invitations), downloads/exports, 2FA, and the sandbox active state.

**Environment limitation:** the extension's synthetic keyboard input did not reach the page in this session. Ctrl+K and typed text were dropped, while clicks worked. Keyboard paths were therefore driven with DOM `keydown`/`input` events. This proves the handlers work, but it is **not** a real-keyboard test. At one point the tab renderer stopped responding during a long probe. A fresh tab was opened and the probe continued in smaller chunks.

## 6. Defects fixed in this pass (P1 — missing accessible names)

An accessible-name sweep covered 78 pages at 1440 (visible controls with no label, `aria-label`, `aria-labelledby` or title, and icon-only buttons). It found unnamed form controls, which the audit's scale treats as P1 (the same class as the role selects fixed in batch 7):

| Page | Defect | Fix |
| --- | --- | --- |
| Integrations › WooCommerce | "Prix WooCommerce saisis en" select: sibling `<label>` without `htmlFor` | `id` + `htmlFor` |
| Sales › Orders/Edit (`OrderLineGrid`, desktop table) | Quantity, unit price, HT/TTC, discount, %/DH and tax controls had no names (the mobile cards were already labelled) | `aria-label` = column + product name. The tax select also moved from ad-hoc classes to `control-select control-compact` (desktop and mobile), keeping the warning border for unresolved tax. |
| Quotations › LineGrid (desktop) | Same five controls | Same pattern |
| Sales › Returns/Create | Quantity-to-return input | `aria-label` = column + product |
| Finance › Recurring obligations | 4 filter selects named only by their "Tous…" option | `aria-label` status/type/store/supplier |
| Finance › Supplier installments | Supplier filter select | `aria-label` |
| Finance › Commission rules | Tier inputs (min/max named only by "−∞"/"+∞" placeholders; rate unnamed) | `aria-label` = column + tier number |
| Documents › PDF Studio | `ColorField` hex input: second input inside one `<label>`, so unnamed | `aria-label` "… (hex)" + `dir="ltr"` |

All fixes were re-checked in the browser: 0 unnamed controls on those pages, and 0 overflow at 390/1024 in AR dark. No behaviour, payload or route changed.

## 7. Remaining issues

**P0:** none known.

**P1:** none known.

**P2 (backlog, no redesign):**

| # | Issue | Where |
| --- | --- | --- |
| 1 | Legacy numbered `<Pagination>` still used on 11 pages | Activity, Work (Attention, Recurring, Team, TeamMember, Templates), Finance (CommissionLedger, CommissionSalesperson, RecurringObligations, SupplierInstallments, SupplierInstallmentShow) |
| 2 | Legacy `SearchInput`/`FilterSelect` | `components/data-reset/SelectiveReset`, `components/inventory/TransferLinePicker` |
| 3 | Hard-coded palettes | `components/pos/primitives.tsx` (POS exception), `StepIndicator` (`slate-950`/`slate-200`, relies on the dark remap), the Sandbox* badges/banner (amber, light-only colours, still legible in dark), `ProductImage` placeholder, `SendDocumentEmailModal` note |
| 4 | 10px micro-labels | Sandbox badges, `Quotations/Sourcing`, `Quotations/SupplierRecommendations`, `TransferLinePicker`, `NotificationBell`, one in CommissionDashboard |
| 5 | Untranslated backend strings | RoleEditor permission labels and group keys in English (e.g. "audit", "View brands"); Activity action labels ("login succeeded"); server `periodLabel` in French inside Arabic Finance headings; Legal page content in French under AR |
| 6 | Placeholder-only names (the browser falls back to the placeholder) | Search fields on most filter bars; 6 inputs on the Stock page (search plus the opening-stock/adjustment forms); POS search |
| 7 | Native file inputs without an explicit name | Document profile logo, Document stamp image |
| 8 | Empty `store_id=` in the Finance URL after a month change | FinanceFilters (cosmetic) |
| 9 | "Ajuster le stock" on a product opens the Stock page without preselecting the variant or scrolling to the adjustment form | Products/Show → Stock |
| 10 | Uppercase in the POS catalogue table head and display label | POS (exception) |
| 11 | Quotations sandbox banners: French-only copy (carried over) | Quotations/Show |

## 8. Automated validation

| Check | Command | Result |
| --- | --- | --- |
| TypeScript | `npx tsc --noEmit -p .` | Pass (before and after the fixes) |
| Production build | `npm run build` | Pass (before and after the fixes; existing chunk-size warning only) |
| Full backend suite | `php artisan test` (serial; `--parallel` is unavailable because ParaTest is not installed) | **2150 passed, 16175 assertions, 0 failed** (~29 min, in-memory SQLite) |

The backend suite ran **before** the accessibility fixes. Those fixes are frontend-only (attributes and classes in 8 TSX files) and do not affect backend tests. There is no frontend interaction test runner in the repo.

No backend business rule was modified. Nothing was committed or pushed.

## 9. Screenshots (`docs/design/final-qa/`)

| File | Shows |
| --- | --- |
| `01-command-palette-fr-light.jpg` | Command Palette open over the dashboard |
| `02-sales-orders-list-fr-light.jpg` | Populated orders list, KPI tiles, filter bar, badges, row actions |
| `03-sales-order-show-fr-light.jpg` | Order detail SO-000006 |
| `04-activity-legacy-pagination.jpg` | Activity list with the remaining legacy pager (P2 #1) |
| `05-catalog-product-show.jpg` | Product detail with per-variant availability |
| `06-role-editor-readonly.jpg` | RoleEditor on a locked system role (English permission labels, P2 #5) |
| `07-error-403-branded.jpg` | Branded 403 page for the Sales Employee |
| `08-woocommerce-settings.jpg` | WooCommerce settings |
| `09-inventory-stock-ar-dark.jpg` | Stock list, Arabic RTL, dark |
| `10-finance-creances-ar-dark.jpg` | Créances report, Arabic RTL, dark (French period label, P2 #5) |
