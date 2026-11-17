# Sprint 0 — Staging release preparation & UAT plan

- **Branch:** `feature/sprint-0-financial-hardening` (base `main` @ `da8834d`)
- **Scope:** Staging only. Production is **not** approved here. Do not merge to `main`, do not open a PR before UAT passes, do not delete `stash@{0}`.
- The exact commit SHA to deploy is the one recorded in the release note / PR, not a branch name. Deploy **by SHA**.
- Access to Coolify was **not available** to the assistant: everything in §A and §B is a manual checklist. No Staging or Production system was inspected or modified.

---

## A. Staging configuration checklist (Coolify, manual)

Never paste secret values into tickets or chat. Compare by presence / fingerprint only (e.g. first 6 characters of a SHA-256).

| # | Check | How to verify | Pass |
|---|---|---|---|
| A1 | Web and Worker deploy the **same commit SHA** | Coolify deployment details for both resources show the same SHA; `APP_COMMIT_SHA` env set to it on both. System Health page shows it | ☐ |
| A2 | `APP_ENV=staging`, `APP_DEBUG=false` | Resource env list. Debug must be false: an exception page must not show stack traces | ☐ |
| A3 | Staging has its **own** `APP_KEY` (not the Production one) | Compare fingerprints of the two keys: they must differ. If staging ever received a Production DB copy, sessions/encrypted columns from Production must not be readable | ☐ |
| A4 | Web and Worker share the same `APP_KEY` | Fingerprints equal across the two Staging resources (encrypted jobs and cookies break otherwise) | ☐ |
| A5 | `ORG_BACKUP_SIGNING_KEY`, `ORG_BACKUP_ENCRYPTION_KEY` are **Staging-only, different from `APP_KEY`** and from Production | Fingerprint compare. Never reuse Production backup keys in Staging | ☐ |
| A6 | Staging MySQL is a separate service and DB | Hostname of `DB_HOST` is the Staging MySQL service, not Production's. Run `SELECT @@hostname, DATABASE()` from Staging web console (§B3 step 1) | ☐ |
| A7 | Staging MySQL user cannot reach the Production DB | Different credentials; Production MySQL is not on a network shared with Staging, or its user has no grants for the staging host | ☐ |
| A8 | Cache/queue/session point at Staging | See A9. `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER` identical on Web and Worker | ☐ |
| A9 | **Redis/Valkey:** the application is validated on **`database` drivers** (`SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`; see `DEPLOYMENT_CHECKLIST.md` §3). Redis config exists in `config/*.php` but is not part of the validated setup, and `phpredis` availability on Railpack is unverified | Recommended: keep `database` drivers for this UAT so Staging matches what was tested. If Redis is wired, `REDIS_HOST` must be the Staging Valkey, not Production's, and `phpredis` must be installed | ☐ |
| A10 | Sessions and storage do not leak | Staging uses its own cookie domain (`APP_URL` = staging host, `SESSION_DOMAIN` unset or staging-only). `storage/app` is a Staging-only volume | ☐ |
| A11 | **No external integration can reach Production** | Staging env must not contain Production values for: `MAIL_*` (use Mailpit/log or a sandbox SMTP; default `MAIL_MAILER=log`), `GOOGLE_AUTH_*`, `GOOGLE_DRIVE_*` (leave empty), `SLACK_*`, `LOG_SLACK_WEBHOOK_URL`, `RESEND_API_KEY`, `POSTMARK_API_KEY`, `AWS_*`, `ORG_BACKUP_S3_*`. WooCommerce credentials and per-organization SMTP live **in the database**, so a fresh Staging DB has none: do **not** restore a Production dump into Staging | ☐ |
| A12 | MCP cannot be used against Staging by accident | `MCP_ALLOWED_HOSTS` / `MCP_ALLOWED_ORIGINS` list only the Staging host; no MCP connection in Claude is pointed at Staging unless intended. `MCP_OAUTH_ENABLED` only if tested | ☐ |
| A13 | **Scheduler disabled** | The app's scheduler (`schedule:run`) must be **off** in Staging until reviewed. Confirm no Coolify scheduled task / cron runs `schedule:run`. The schedule includes backups, follow-ups, attention scan, recurring finance obligations (00:10) and e-mail-capable notifiers. Run individual commands manually during UAT if needed | ☐ |
| A14 | Worker command | `php artisan queue:work --tries=3 --timeout=3600` (`retry_after` = `DB_QUEUE_RETRY_AFTER=3900`) as a **separate** Coolify resource, same image/SHA | ☐ |
| A15 | Railpack build/start for Laravel | Build: `composer install --no-dev --prefer-dist --optimize-autoloader`, `npm ci`, `npm run build` (needs network for fonts). **Verify that the Railpack Laravel start does not auto-run `php artisan migrate`**: set an explicit start/pre-deploy command and run migrations manually (§B3). Required PHP 8.4, `bcmath`, `pdo_mysql`, `pcntl`, `gd` | ☐ |
| A16 | Persistent storage | Volume on `/app/storage/app` for Staging only | ☐ |
| A17 | Migrations cannot hit Production | The only place `migrate` runs is the Staging web console after A6 passes. No auto-migrate hook; Production resources are not selected in the deployment | ☐ |
| A18 | `/up` health check | Coolify health check on `GET /up` | ☐ |
| A19 | Staging is not publicly indexable | Basic auth or IP allow-list recommended; `PUBLIC_REGISTRATION_ENABLED=false` | ☐ |

## B. Staging deployment plan

Preconditions: feature branch published; release SHA recorded; §A1–A19 done; nobody deploys from a branch name.

1. **Pin the source.** In both Staging resources set the branch to `feature/sprint-0-financial-hardening` (or deploy the exact SHA). Do **not** change the `staging` branch and do **not** touch the Production resources. (Remote `staging` currently equals `main` @ `da8834d`.)
2. **Application Staging:** deploy, wait for the build to finish and `/up` to be healthy. **Do not run migrations yet.**
3. **Database identity check, then migrate.** In the Staging **web** console:
   1. `php artisan tinker --execute="dump(DB::select('select @@hostname h, database() d, @@version v')[0], config('app.env'), parse_url(config('app.url'),PHP_URL_HOST));"` → confirm host and database are the **Staging** ones and `app.env = staging`.
   2. `php artisan migrate:status | tail -20` → on a **fresh** Staging DB everything is pending; on a Staging DB cloned from anything, stop and ask.
   3. Take a Staging DB snapshot (cheap, repeatable).
   4. `php artisan migrate --force`. Expected last migrations: Test Mode `2026_11_21_00000x`, then `2026_11_22_000001_add_fiscal_protection_to_organizations`.
   5. Never run `db:seed`, `migrate:fresh` or any reset on a database whose identity you have not confirmed.
4. `php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan storage:link`.
5. **Worker Staging:** deploy the **same SHA**, start `queue:work`, then in the web console `php artisan queue:restart`. Confirm on **Santé du système** that the queue worker heartbeat is healthy and the scheduler heartbeat is **absent by design** (A13).
6. Create fake data only: a Staging organization, a Staging owner account, two other users (a finance manager with `finance.monthly_situation.manage`, a user with `finance.periods.reopen`), products, customers, warehouse, stock and financial accounts. **No Production data, no Production dump.**
7. Rollback plan for Staging: restore the snapshot from step 3.3 and redeploy the previous SHA. Production is unaffected either way.

## C. UAT checklist (fake data, Staging only)

Record for each line: tester, date, SHA, result, evidence (screenshot or document number).

### C1 — Fiscal documents (`/settings/data-reset`)
- [ ] Create, confirm and pay an order; issue an invoice (note its number).
- [ ] Selective reset with the `invoices` category: **refused**, blocker shows count and sample number, nothing deleted.
- [ ] Selective reset with `payments`: refused. Posted **and reversed** payments stay listed.
- [ ] Full-module reset that touches invoices/payments/refunds/outgoing payments: refused with the fiscal blocker.
- [ ] A draft invoice can still be deleted; the issued one cannot.
- [ ] Issue another invoice after the refused reset: the number continues the sequence (no reuse, no renumbering).
- [ ] The "Protection des documents fiscaux" panel shows *protected*, with no way to declare the organization as test (button absent, API returns 422).

### C2 — Closed financial periods (`/finance/situation-fin-de-mois`)
- [ ] Close the previous month: works, snapshot saved.
- [ ] Payment dated in the closed month: refused (web, POS and, if used, MCP). Refund, outgoing payment, obligation, installment plan and cheque/traite dated there: refused.
- [ ] Payment dated in an open month: accepted.
- [ ] As the finance manager **without** `finance.periods.reopen`: reopening is refused (403).
- [ ] As the Owner or the reopen-permission holder: reopening **requires a reason** (empty reason refused).
- [ ] After reopening, an `audit_logs` event `finance_month.reopened` exists with the reason and the actor (Settings → audit center).
- [ ] The late payment is now accepted; close the month again (new snapshot).

### C3 — Morocco business dates
- [ ] Organization timezone is `Africa/Casablanca`. Record a payment at the local day boundary (00:00–01:00 local, or with the server clock shifted for the test): it is **not** treated as backdated.
- [ ] Ramadan period (UTC+0): payments, refunds and month-end close use the local date (`BusinessDateTimezoneTest` already covers 2026-02-15 → 2026-03-22; confirm visually on one document).
- [ ] The default date in payment forms and Situation forms equals the business "today".
- [ ] Cheque/traite clearing accepts a value date; a future date and a date before the issue date are refused.
- [ ] Document dates (invoice/BL/credit-note defaults) still use UTC — known, out of scope; note any midnight anomaly but do not fail Sprint 0 for it.

### C4 — Receivables (`/finance/creances`)
- [ ] Unpaid, partially paid and fully paid orders show the right outstanding amounts.
- [ ] A refunded payment and a received return reduce exposure as expected (`max(total − returns, 0) − (payments − refunds)`).
- [ ] The Créances page, the Finance dashboard, the executive dashboard and the expected-collection picker show the **same** figure for the same orders.
- [ ] Month-end "Situation" opening/closing receivables are consistent with the invoice view.

### C5 — Sandbox / Test Mode (`/test-mode`)
- [ ] Enter Test Mode; create a POS sale and an order; the documents are `TEST-` numbered, payments `TEST-PAY-`.
- [ ] With the **current Production month closed**, a Test payment/POS sale still works.
- [ ] Production reports (Créances, finance dashboard, month situation) contain **no** Test order or payment.
- [ ] Production invoice numbering and payment numbering are unchanged after Test activity.
- [ ] Sandbox reset (`RESET TEST`): only Test rows are removed; Production invoices, payments, stock and numbering are unchanged (compare counts before/after).
- [ ] With Test data present, the Production organization reset is blocked with the Test Mode message; after the Sandbox reset the fiscal blockers still apply.
- [ ] Leaving Test Mode returns Production data exactly as before.

### C6 — Regression
- [ ] Sales orders: create, confirm, edit, cancel, correction.
- [ ] Invoice issuance (requires full payment — unchanged), PDF, credit note from a return.
- [ ] Payments: record, reverse, refund; allocation to the order.
- [ ] Inventory: reservation on confirmation, release on cancel, stock movement, transfer.
- [ ] Fulfillment: preparation → hand-over (stock out once) → delivered.
- [ ] Procurement: sourcing, purchase order, goods receipt.
- [ ] Worker: queue a job (e.g. a manual backup or MCP product operation), see it consumed; `queue:failed` empty.
- [ ] No unexpected e-mail leaves Staging (check the mail sink).

### C7 — Exit criteria
All boxes ticked, no failed jobs, no error log entries of level error in `storage/logs`, SHA identical on web and worker. Only then: open the PR, review, and plan Production separately (backup, read-only `migrate:status`, maintenance window).
