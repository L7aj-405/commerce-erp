# 10xScale ERP — V1.0 Release Readiness & Staging Validation

Date: 2026-11-14 · Scope: the Design System V1.0 UI migration (commits `4920b1f`…`fa40d43`, plus the uncommitted final-QA fixes).
Inputs: `ERP_UI_FINAL_QA.md`, `ERP_UI_AUDIT.md`, `ERP_UI_MIGRATION_TRACKER.md`, `DEPLOYMENT_CHECKLIST.md`.
Local environment: Laravel `127.0.0.1:8000`, Laragon MySQL 8.4, Vite dev, Chrome (Claude-in-Chrome).

## 1. Recommendation

| Target | Decision | Why |
| --- | --- | --- |
| **Deploy to staging for validation** | **GO** | No P0 or P1 is known. The full backend suite, tsc and the production build pass. Every locally available page and interaction passed. |
| **Production release** | **NO-GO (for now) → CONDITIONAL GO once §7 is complete** | 11 detail pages and the main money/document workflows have never been exercised with real records. `npm audit` reports a **high** advisory, which fails the checklist's own pre-deploy gate. Composer reports Symfony advisories, one of them on the trusted-proxies shortcut that the checklist relies on. |

This report does **not** claim production readiness.

## 2. Final working tree (§1 of the brief)

**Uncommitted changes:** 9 TSX files and 2 docs modified; 2 new doc paths.

| File | Change | Verdict |
| --- | --- | --- |
| `pages/Settings/Integrations/WooCommerce.tsx` | Price-mode select gets `id` + `htmlFor` | Legitimate (P1 a11y) |
| `pages/Sales/Orders/OrderLineGrid.tsx` | `aria-label` on desktop grid controls; tax select → `control-select control-compact`; mobile "PU HT" → `t('orders.show.unitPriceExclTax')` | Legitimate |
| `pages/Quotations/LineGrid.tsx` | `aria-label` on desktop grid controls; mobile "Prix unitaire" → `t('quotations.unitPrice')` | Legitimate |
| `pages/Sales/Returns/Create.tsx` | `aria-label` on the quantity input | Legitimate |
| `pages/Finance/RecurringObligations.tsx`, `SupplierInstallments.tsx`, `CommissionRules.tsx` | `aria-label` on filter selects and tier inputs | Legitimate |
| `pages/Documents/TemplateStudio.tsx` | Hex colour input: `aria-label` + `dir="ltr"` | Legitimate |
| `layouts/ApplicationShell.tsx` | Profile-menu "Mode test" → `t('sandboxPage.title')` (Arabic existed but was unused) | Legitimate |
| `docs/design/*` (audit, tracker, final QA, `final-qa/` screenshots, this file) | Documentation | Legitimate |

None of these touch backend code, routes, payloads or business rules.

**The full migration diff** (`1ed5e14..HEAD` plus the working tree) was checked for the items in the brief:

| Check | Result |
| --- | --- |
| Debug code (`console.log`, `debugger`, `dd(`, `dump(`, `var_dump`) | **None** in `resources/js` or in the added PHP. The only regex hits were `Array.isArray(` false positives. |
| Temporary fixtures | None added. `LocalVerificationSeeder` (seeded users with the password `password`) predates the migration and **refuses** to run unless `APP_ENV` is local/testing **and** the DB host is local. |
| Test-only routes | `/dev/ui` (Design-system gallery) is registered **only when `app()->isLocal()`**. Its page component still ships in the bundle (harmless without the route). `/test-mode` is the product's sandbox feature, gated by `sandbox.access` / `.manage` / `.switch`. |
| Hard-coded local URLs or credentials | None in application code. `public/hot` (Vite dev URL) and `public/build` are git-ignored. |
| Unused or new dependencies | **No change** to `package.json`, `package-lock.json`, `composer.json` or `composer.lock` during the migration. |
| Missing translations | Key parity is compiler-enforced: `ar` is typed `Record<TranslationKey, string>`, and tsc passes. 3 hard-coded French strings were fixed with existing keys. **Still hard-coded (P2):** the `SandboxBanner` body text and "Quitter le mode test" button, `SandboxStockBadge` "Stock test", MonthlySituation "Mode de paiement", the Quotations sandbox banners. These need new Arabic copy, so they were not invented here. |
| Backend modifications | 5 Finance paginators (`withQueryString()`, approved earlier). `ExecutiveDashboardService` excludes sandbox orders and credit notes (`sandbox_id IS NULL`) from the dashboard chart, covered by `SandboxReportingIsolationTest`. Branded `resources/views/errors/*`. Theme-before-paint script and `@fonts` in `app.blade.php`. The `/dev/ui` local route. **No other backend changes.** |
| **Accidental files (needs action)** | `routes/README.md` and `routes/docs/design/{VISUAL_SPEC.md, liquid-glass-dashboard.html, approved-liquid-glass-dashboard.png (1.7 MB)}` + `routes/docs/tasks/CLAUDE_CODE_TASK.md`. A design-handoff ZIP was extracted into `routes/` in commit `4920b1f`. They are **byte-identical** to the copies in `docs/`. They are not executed, but they don't belong in `routes/`. **`routes/docs/**` removed** and `routes/README.md` (the handoff's own readme) moved to `docs/README.md` on branch `ui/v1-final-qa`. |
| Fonts | `vite.config.ts` now self-hosts Plus Jakarta Sans + IBM Plex Sans Arabic via Bunny at build time. The build needs outbound network, as `DEPLOYMENT_CHECKLIST.md §4` already notes. |

## 3. Verified coverage (local)

| Area | Status |
| --- | --- |
| Page components | 110 rendered by controllers: **89 VERIFIED**, 7 IMPLEMENTED — NOT FULLY VERIFIED (token- or state-gated auth), **11 BLOCKED** (no local record), 3 APPROVED EXCEPTION (POS, Home, Dev gallery). Details in `ERP_UI_FINAL_QA.md §2`. |
| Responsive | All 89 verified pages probed in earlier passes at 390–1920. This pass re-probed 18 representative pages (including every file edited since) at 390 AR dark, and 8 pages at 390 FR light, 768 FR light, 1024 AR light, 1440 FR dark and 1920 FR light. **58 page loads, 0 issues** (no overflow, no un-migrated fields or raw tables, theme and direction applied). |
| Accessible names | 78-page sweep: 0 unnamed controls after the fixes. |
| Backend | Full suite green (§6). |

## 4. Browser verification matrix (this pass, real keyboard unless noted)

| Item | Width / mode | Result | Evidence |
| --- | --- | --- | --- |
| Layout at mobile | 390 AR dark (18 pages), 390 FR light (8) | **PASS** | 0 overflow / un-migrated fields / raw tables |
| Layout at tablet | 768 FR light, 1024 AR light (8 each) | **PASS** | same |
| Layout at desktop | 1440 FR dark, 1920 FR light (8 each) | **PASS** | same |
| Light and Dark themes | All widths above | **PASS** | `data-theme` applied before paint |
| Arabic RTL | 390, 1024 | **PASS** | `dir=rtl`; popovers start-aligned inside the viewport |
| Keyboard: skip link and focus ring | 1920 FR light | **PASS** | First Tab → "Aller au contenu" (`#erp-main`), `:focus-visible` |
| Keyboard: Command Palette | 1920 | **PASS** | Ctrl+K opens with focus in the field; typing filters; ↓/↑ move; Enter navigates to Commandes fournisseur |
| Keyboard: search | 1920 | **PASS** | Typing "SO-6" debounces to `?search=SO-6`; Tab reaches "Effacer la recherche" |
| Date picker | 1920 FR (keyboard); 390 AR and 768 FR (placement) | **PASS** | Enter opens on today; ←, ↓ and PageUp move by day, week and month; Enter commits `?sale_date=…` with a chip and returns focus; Escape closes and returns focus. The panel sits inside the viewport at 390 RTL (23–335 of 390) and at 768. |
| Dropdown (ChoiceFilter listbox) | 1920 (keyboard); 390 AR and 768 FR (placement) | **PASS** | Enter opens a listbox; ↓ + Enter applies `?payment_status=partially_paid`; filtered empty state "Aucun résultat avec ces filtres". In-viewport at both narrow widths. |
| Header "Plus" overflow menu | 768 | **BLOCKED (not re-tested)** | The probe could not open it at 768 in this pass. It was verified in the shell tasks (FR/AR, 390–1440). |
| Advanced filters on mobile | 390 AR | **PASS** | The "Filtres" disclosure reveals the status, preparation, payment and date filters |
| Tables | 1440 | **PASS** | Populated lists render (Final QA §4) |
| Pagination: legacy pager (Activity, 127 rows) | 1920 | **PASS** | Focus "2" + Enter → `?page=2`, new rows, `aria-current` moves; filters are kept in the links |
| Pagination: `TablePager` across multiple pages | — | **BLOCKED** | No local list using `TablePager` has more than one page of data |
| Column sorting | — | **N/A** | No ERP list offers sorting (existing behaviour) |
| Screen reader output | — | **BLOCKED** | Checked through accessible names in the DOM only; no real screen reader |

Note: in the previous session the extension's keyboard input did not reach the page. In this session it worked, so the keyboard rows above are real key presses. One early date-picker attempt looked like a failure because keys were sent faster than the panel re-rendered. Repeated with a 1s gap, it passed.

## 5. Remaining unverified workflows

These must be run on staging (§7):
- POS cart, checkout, payment, receipt.
- A real org/store switch (the local owner has one of each).
- Order confirm/cancel/fulfil.
- Invoice, delivery-note and credit-note issue/cancel/email/PDF.
- Return receive/refund and exchange processing.
- Payment reversal.
- PO receive/cancel.
- Stock adjustment and transfer submit.
- Month-end close/reopen and obligation payments.
- Every Finance export (xlsx/pdf/zip).
- Product cost import confirm.
- Settings saves.
- Invitations.
- Password reset, email verification, 2FA enrolment and challenge.
- The sandbox active state.

## 6. Test results (after the final code changes)

| Check | Command | Result |
| --- | --- | --- |
| Full backend suite | `php artisan test` (serial; `--parallel` needs ParaTest, which is not installed) | **2150 passed, 16175 assertions, 0 failed**, 559 s |
| TypeScript | `npx tsc --noEmit -p .` | **Pass** |
| Production build | `npm run build` | **Pass**, 1019 modules; the existing chunk-size warning remains |
| Frontend interaction tests | — | **None available.** The repo has no Vitest/Jest/Playwright/Cypress. Frontend confidence rests on the browser QA in §4 and the Final QA. |
| `composer audit` | — | **8 advisories:** `laravel/framework` (low, Concurrency task output injection); `symfony/http-foundation` ×4, incl. **"private_ranges" trusted proxies trusting IPv4-mapped addresses** and **"generated URLs can point to a client-chosen host"**; `symfony/http-kernel` (forwarded headers in inline fragments); `symfony/mime` (medium, email header injection); `symfony/routing` (host placeholders). |
| `npm audit --omit=dev` | — | **2:** `follow-redirects` **high** (transitive); `qs` moderate (transitive). Fails the checklist gate "no high/critical". |

The backend suite ran on the final backend code. The 3 translation-key edits came after it started, but they are TSX-only and were covered by the final tsc and build.

## 7. Staging requirements

### 7.1 Environment and configuration (must hold before validation)

1. `APP_ENV=staging` (never `local`, or `/dev/ui` is exposed) and `APP_DEBUG=false`.
2. A separate staging database and storage, never pointed at production. **Do not seed** with `DatabaseSeeder` / `LocalVerificationSeeder`; the latter refuses non-local hosts anyway.
3. Staging-only SMTP (e.g. a mail catcher) for verification, reset and invitation mail. Each staging organization uses its own catcher SMTP for document email.
4. One queue worker and one scheduler, as in the checklist §5.
5. `TRUSTED_PROXIES` set explicitly to the staging proxy, **not** left on the private-ranges default, until the Symfony advisories are patched.
6. The build can reach Bunny Fonts (or fonts are pre-fetched).
7. Patch or formally accept `follow-redirects` (high) and the Symfony advisories before production. That is a dependency update, outside this UI scope, and needs approval.
8. Done: the `routes/docs/**` duplicates are removed and the handoff readme is now `docs/README.md`.

### 7.2 Staging fixtures (create through the UI on staging, never copied from production)

- One test organization with **two stores** and two warehouses, and **three users**:
  - Owner (154 permissions)
  - Admin (144)
  - Sales Employee (65)
- Synthetic customers (`…@example.test`), one supplier, 3–4 products including one with variants, and opening stock.
- **Financial sandbox isolation stays on.** Real-ledger documents are created only in the dedicated staging org. Sandbox (`/test-mode`) checks use separate sandbox documents and must not appear in Production reports.

### 7.3 The 11 blocked detail pages — staging checklist

For every page, run it at **1440 FR light** and **390 AR dark**. Expect no horizontal overflow, actions reachable by keyboard, a branded 403 for a role without the permission, and a 404 for another org's record.

| # | Page · route | Role to use (permission) | Record to create on staging | Expected visible actions | Key validation points |
| --- | --- | --- | --- | --- | --- |
| 1 | Invoice · `/invoices/{invoice}` | Owner/Admin (`invoices.view`, `.issue`, `.email`); also Sales Employee (has `invoices.issue`, **no** `invoices.backdate`) | Draft invoice from a confirmed order, then an issued one | Draft: edit, issue (confirm dialog). Issued: download/PDF/print, email, stamp, corrections, cancel | Paper stays LTR and print-correct; side-panel totals end-aligned in AR; issue confirm focuses Cancel; the date field is disabled without backdate permission; a sandbox invoice must 404 in Production context |
| 2 | Delivery note · `/delivery-notes/{deliveryNote}` | Admin (`delivery_notes.*` incl. backdate); Sales Employee (no backdate) | Delivery note from a confirmed order (draft → issued → cancelled) | Update draft, issue, cancel, email, PDF/print/download | Issue/cancel dialogs; DatePicker disabled without backdate; status badges |
| 3 | Credit note · `/credit-notes/{creditNote}` | Owner/Admin/Sales Employee (`credit_notes.view`) | Issued invoice → return refunded as a credit note | View, PDF, print | Amounts and signs, the link back to the invoice and return, AR alignment |
| 4 | Return · `/sales/returns/{customerReturn}` | Admin (`sales_returns.receive`, `.cancel`, `.override_policy`); Sales Employee (receive, no cancel) | Return on a confirmed, fulfilled order | Receive, refund, cancel (dialogs) | Disposition select names, refund payment-method select, policy deadline, the Sales Employee does **not** see Cancel |
| 5 | Exchange · `/sales/exchanges/{exchange}` | Admin/Sales Employee (`sales_exchanges.process`) | Exchange on a fulfilled order (price difference both ways) | Receive, fulfil, payment, refund, cancel | The 3 payment/refund selects; the "Dérogation responsable" checkbox (still native, P2); totals |
| 6 | Transfer · `/inventory/transfers/{transfer}` | Admin (`inventory.transfer`); Sales Employee has **no** `inventory.transfer` → check the expected 403/absence | A completed transfer between the two warehouses | View only | Line table (`erp-table`), source→destination arrow mirrored in AR, movement links |
| 7 | Payment · `/payments/{payment}` | Admin/Owner (`payments.reverse`); Sales Employee (`payments.view`, **no** reverse) | A posted payment on a staging order | Reverse (confirm), links to order/invoice | Reversed state muted; tokenised colours in dark; the Sales Employee does not see Reverse |
| 8 | Contact · `/contacts/{contact}` | Any role with `contacts.view`; archive needs `contacts.archive` | A synthetic contact of each type, incl. "transport" | Edit, archive (useConfirm, danger) | Email/phone in LTR `bdi`; long values wrap at 390; archive confirm focuses Cancel and Escape cancels |
| 9 | Purchase-price import preview · `/catalog/purchase-prices/imports/{id}` | Owner/Admin (`product_cost.import`) | Upload a small synthetic CSV/XLSX of purchase prices | Confirm import, back | `TablePager` with "x–y sur n" across pages; error rows highlighted; **confirm only on staging data** |
| 10 | Supplier installment plan · `/finance/situation-fin-de-mois/echeanciers-fournisseurs/{plan}` | Owner/Admin (`finance.monthly_situation.manage`) | Installment plan for the staging supplier | Update plan (PATCH), per-row due dates | DatePicker per row; legacy pager (P2); amounts end-aligned in AR |
| 11 | Commission entry · `/finance/commissions/ledger/{entry}` | Owner/Admin (`commissions.ledger.view`, `.approve`, `.mark_paid`) | Active commission rule + a confirmed sale by the Sales Employee | View; approve / mark-paid from the ledger | RTL table cells (audit X6, never seen with data); dates and amounts; the Sales Employee sees only `/my-commissions` |

### 7.4 Critical workflow test plan (staging)

Each row is run as the role shown, then repeated as the Sales Employee to confirm what is denied.

| Workflow | Steps | Pass criteria |
| --- | --- | --- |
| **POS cart → checkout → payment → receipt** | Search a product; add 2 lines (one variant); change quantity; select a customer (2-char search) and a walk-in sale; hold and resume (confirm dialog); pay cash and card; print the receipt | Cart totals correct; checkout disabled when empty; the hold confirm focuses Cancel; the receipt shows the store and lines; stock decremented once; the sale appears in Orders and Créances. Repeat in sandbox mode: **no** Production report changes. |
| **Organization / store switching** | User with 2 stores (and 2 orgs if available): switch the store in the header, then the org | Header label updates; lists and Finance KPIs re-scope; no data leaks between orgs (other-org IDs → 404) |
| **Order lifecycle** | Manual order → edit lines (grid: quantity/price/discount/tax by keyboard) → confirm → fulfil (proof upload, remove-proof confirm) → completion → invoice | Grid controls announce "column · product"; statuses and badges update; cancellation respects permissions |
| **Procurement receive / cancel** | PO from a supplier request → receive part (`max=today` date) → receive the rest → cancel a separate PO | Partial status badges; stock movements created; the cancel dialog; receive date cannot be in the future |
| **Inventory movement** | Opening stock, adjustment in/out (Stock page form), transfer create → complete | Movement list shows signed quantities and types; product availability updates; adjustment validation (required reason) |
| **Finance exports** | CA encaissé export menu: Excel, PDF, ZIP; Monthly situation export | Files download; the ZIP error toast on failure; exports respect `month` + `store_id`; **sandbox data excluded** |
| **Invoice and credit-note details** | §7.3 #1 and #3 | As in the table |
| **Form submissions and validation** | Submit each main form empty, then valid: product, customer, contact, supplier (Sheet), warehouse (Sheet), store, quotation, recurring obligation, commission rule, WooCommerce connection | Errors linked to fields and readable in AR/dark; success closes Sheets; nothing double-submits |
| **Role-based permissions** | Log in as each of the 3 roles; walk the rail, Command Palette and quick actions; open denied URLs directly | Only permitted modules and actions show; the rail entry resolves to a permitted child (e.g. Finance → Payments for the Sales Employee); denied URLs → branded 403 |
| **Authentication and recovery** | Register (if enabled), email verification, login, forgot/reset password, 2FA enrolment (mandatory for Owner/Admin), 2FA challenge, password confirmation, invitation accept, session revoke | All screens render in FR/AR at 390 and 1440; mail reaches the staging catcher; the 419/429 branded pages appear on an expired token or rate limit |

## 8. Open P2 items (no redesign)

From `ERP_UI_FINAL_QA.md §7`, still open:
- Legacy numbered pager on 11 pages.
- Legacy `SearchInput`/`FilterSelect` in 2 components.
- Hard-coded palettes: POS primitives, StepIndicator, Sandbox badges, ProductImage, SendDocumentEmailModal.
- 10px micro-labels.
- Untranslated backend strings: RoleEditor permissions, Activity actions, French `periodLabel` in Arabic, French Legal content.
- The remaining hard-coded French sandbox and payment-method strings (§2).
- Placeholder-only names on search fields.
- Unnamed native file inputs (Document profile, Stamp).
- Empty `store_id=` in the Finance URL.
- "Ajuster le stock" doesn't preselect the variant.
- POS uppercase table head.

## 9. Security and configuration concerns (summary)

1. **`npm audit` high** (`follow-redirects`): blocks the checklist's own gate.
2. **Symfony advisories:** trusted proxies `private_ranges`, client-chosen host in generated URLs, forwarded headers, mail header injection. They are relevant because the checklist relies on the private-range trusted-proxies default. Update the framework packages or pin `TRUSTED_PROXIES`.
3. `.env.example` defaults to `APP_ENV=local` / `APP_DEBUG=true`. Staging and production must override both (otherwise `/dev/ui` and debug pages are exposed).
4. Accidental `routes/docs/**` duplicates (1.7 MB PNG + docs): removed. The handoff readme moved to `docs/README.md`.
5. No secrets, local URLs or debug code were found in the migration diff.

## 10. What was not done (by instruction)

Nothing was committed, pushed, merged or deployed. The database was not reset, and no production data or record was created or modified. Dependencies were not updated (needs your approval). The `routes/docs` duplicates were later removed at your request and everything was committed on branch `ui/v1-final-qa` (not pushed). Locally, MySQL (Laragon), `php artisan serve` and the Vite dev server were started for QA and are still running.
