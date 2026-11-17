# POS draft lifecycle, worker / scheduler and release visibility

## 1. POS draft lifecycle (decisions)

| Topic | Behaviour |
|---|---|
| Origin marker | `sales_orders.source = 'pos'` (already existing). Only `source=pos`, `status=draft`, `pos_converted_at IS NULL` rows are ever considered. Quotation / B2B / manual drafts are never touched. |
| Opening the POS | Creates nothing. A draft is created lazily on the first add (or customer pick) and is serialized per Store (row lock), so double clicks / retries / two tabs collapse into ONE draft. |
| Add / remove cycles | The same draft is reused. Removing the last line keeps that single empty cart (no SO number burned per cycle). Empty POS carts are **hidden** from `/sales/orders` and its counters. |
| Activity | `sales_orders.pos_last_activity_at` (additive, nullable). Stamped on line add/update/remove, header edits, resume, creation. Page loads never stamp it. Legacy rows (NULL) fall back to `updated_at`. |
| Expiry | Cart with lines: `POS_DRAFT_INACTIVITY_HOURS` (default 24h) without activity. Empty cart: `POS_EMPTY_DRAFT_GRACE_MINUTES` (default 60 min). |
| Cleanup | `php artisan pos:cleanup-drafts` = **dry run** (read-only report). `--execute` applies. Scheduled daily at `POS_DRAFT_CLEANUP_TIME` (default 03:50, `Africa/Casablanca`) with `--execute`, `withoutOverlapping`. Batch size `POS_DRAFT_CLEANUP_BATCH_SIZE` (default 200, max 1000) or `--batch=`. `--organization=ID` scopes one tenant. Switch off with `POS_DRAFT_CLEANUP_ENABLED=false`. |
| Environment | Production only. The CLI has no Test Mode selection, so the sandbox scope resolves to Production; the command refuses to run in a Test context. Test Mode drafts are removed by the Test Mode reset. |
| Safety | Row-locked re-check of status / inactivity / history. Anything with payments, refunds, invoices, delivery notes, fulfillments, returns, exchanges, transfers, procurements, revisions, addenda, commissions or finance expectations is **blocked** and reported (never deleted). The scheduler also never releases reservations itself (blocked). |
| Removal | Lines, allocations and unsent supplier-availability evidence are deleted, one audit event `sales_order.pos_draft_discarded` (snapshot of lines/totals/reason/trigger) is written, then the draft row is deleted. FK-restricted children make a partial delete impossible. |
| Manual delete | `DELETE /pos/drafts/{order}`: needs `sales_orders.cancel` (existing; this is "which roles may clean up"). Empty draft: immediate. Draft with lines: body `confirm=true` required (422 otherwise). A draft that has commercial history falls back to the pre-existing cancellation workflow. |
| Order numbers | Draft numbers come from the SO sequence. Deleting a draft leaves a gap among *draft* numbers (never confirmed orders / invoices). This is why empty carts are reused rather than recreated. |

### Inventory model (verified in code)
* **Draft** Sales Orders hold line allocations **without** reservations. Stock is reserved at confirmation (`ConfirmSalesOrderAction`) and consumed at fulfillment.
* So discarding a draft **never** changes `on_hand` or `reserved`. The only reservations a draft could own are explicitly linked allocations (edge case); an authenticated manual discard releases exactly those through `InventoryReservationManager::release` (never another order's, never twice because only `Active` ones are selected under lock).
* No stock movement history is deleted.

## 2. Worker / scheduler / health

Finding: the "worker probe" is a job **dispatched by the scheduler every minute**. If the scheduler is not running there is no probe, so "no scheduler heartbeat" + "no worker probe" + "0 pending / 0 failed" means **monitoring is absent, not that the worker is proven stopped**. The health page now reports this as *unknown* ("worker non vérifiable") instead of a failure. A worker that is stopped while jobs are waiting is still *critical*.

### Architecture (verified in code)
* `routes/console.php` registers `system-health:scheduler-heartbeat` (every minute, written by the **scheduler** process) and dispatches `RecordQueueWorkerHeartbeatJob` every minute onto the queue (written by the **worker** process, together with its own `APP_COMMIT_SHA` / `APP_BUILD_AT`).
* So the scheduler is the root of both signals: scheduler dead => no scheduler heartbeat AND no worker probe, even if the worker is healthy. Queue health: *unknown* when both are silent and nothing waits, *critical* when jobs wait and no probe is fresh, *operational* when the probe is fresh.
* The POS cleanup (`pos:cleanup-drafts --execute`) is registered once, daily, `->timezone('Africa/Casablanca')` (default `POS_DRAFT_CLEANUP_TIMEZONE`), `withoutOverlapping`. The application timezone stays UTC; other existing schedules still run in UTC (e.g. 00:10 UTC finance obligations) — unchanged on purpose.
* Inactivity expiry is measured in absolute time (24h / 60 min), so timezone and DST never shift it.

### Exact Coolify configuration for STAGING (apply by hand, in the Staging project only)
Follows `DEPLOYMENT_CHECKLIST.md` §processes (retry_after 3900 > job timeout 3600). Three resources from the **same Git branch `staging`, same image, same environment variables**. Never attach the Production database, Production environment group, or Production domain.

| # | Resource | Type | Command | Count |
|---|---|---|---|---|
| 1 | ERP Staging – web | Application (branch `staging`) | default web start | 1 |
| 2 | ERP Staging – worker | Same application, **no domain / no health-check port**, restart `unless-stopped` | `php artisan queue:work --tries=3 --timeout=3600 --max-time=3600 --sleep=3` | 1 |
| 3 | ERP Staging – scheduler | Coolify **Scheduled Task** on the web app (preferred) | cron `* * * * *`, command `php artisan schedule:run`, timeout 55s | **exactly 1** |

Rules: do not run `schedule:work` in addition to the cron task. `--max-time=3600` lets the worker exit cleanly and be restarted by Coolify, which also picks up new releases. After each deploy run once: `php artisan migrate --force` (web only), then `php artisan queue:restart`.

Environment (Staging group only):
```
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://<staging-domain>
DB_CONNECTION=mysql            # the STAGING database service only
DB_HOST=<staging-mysql-service> DB_DATABASE=<staging-db> DB_USERNAME=... DB_PASSWORD=...   # secrets stay in Coolify
CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_DRIVER=database
APP_COMMIT_SHA=<full 40-char git sha>       # Coolify: $SOURCE_COMMIT as build arg + runtime var
APP_BUILD_AT=<ISO-8601 build time>
POS_DRAFT_INACTIVITY_HOURS=24
POS_EMPTY_DRAFT_GRACE_MINUTES=60
POS_DRAFT_CLEANUP_BATCH_SIZE=200
POS_DRAFT_CLEANUP_TIME=03:50
POS_DRAFT_CLEANUP_TIMEZONE=Africa/Casablanca
POS_DRAFT_CLEANUP_ENABLED=true
```
Verification checklist (read-only, in the UI / Coolify logs): System Health shows a scheduler heartbeat < 3 min old, a worker probe < 3 min old, `worker_commit == commit`; first run of `php artisan pos:cleanup-drafts` (dry run, no flags) from the web container prints the report; only then rely on the scheduled `--execute`.

* Redis / Valkey: `QUEUE_CONNECTION=redis` processes jobs, but the health page only computes pending/failed counts for the `database` driver (reports *unknown* otherwise). Keep `database` on Staging.
* Duplicate schedulers: row locks and `withoutOverlapping()` keep data safe, but heartbeats and load double. Keep exactly one.
* Mismatch worker/web release shows "NO — redeploy worker".

## 3. Release visibility (System Health → Application card)
Environment, `commit` (APP_COMMIT_SHA), `build_at` (APP_BUILD_AT, else Vite manifest mtime), and worker `worker_commit` / `worker_build_at` / `worker_matches_app` from the worker's own heartbeat. Anything unreported or stale shows **Unverified**. No secrets are exposed.

## 4. Month closing vs verification (Finance)
"Vérifié" is a tick (audited, locks nothing). **Clôturer** locks the month (`FinanceClosedPeriodGuard`) and is only offered once the business month has ended (a running month, e.g. November before 1 December, cannot be closed — the page now says so). **Réouvrir** needs the Owner or `finance.periods.reopen`, fresh authentication, and a mandatory reason; it is audited (`finance_month.reopened`). One closing system only (`ManageFinanceMonthReviewAction`); no historical transaction is rewritten. Test Mode neither reads nor is blocked by Production closings.

## 5. Round 3 — Worker recovery and release identity

### 5.1 Worker: confirmed root cause (established outside the repo, from Coolify)
* The Staging worker was started as `php artisan queue:work redis --sleep=3 --tries=3 --timeout=90` while the application uses `QUEUE_CONNECTION=database`. It therefore listened on a Redis queue nobody writes to, never processed the database queue and never recorded a heartbeat.
* The 271 jobs waiting were inspected before the correction: **all were `RecordQueueWorkerHeartbeatJob`** (scheduler probes), not business jobs.
* Corrected Staging command: `php artisan queue:work database --queue=default --sleep=3 --tries=3 --timeout=90 --max-time=3600`.
* Code compatibility (verified in the repo): probes and every `ShouldQueue` job use the `database` connection and the `default` queue (`DB_QUEUE`); no `onQueue` is used. Per-job `$timeout` values (120–3600 s) take precedence over the worker `--timeout=90`, and `retry_after=3900` stays above them.
* Code change made in Round 3: the scheduler no longer dispatches a new probe while one is already waiting (`RecordQueueWorkerHeartbeatJob::alreadyPending`), so a stopped/misconfigured worker can no longer pile up hundreds of identical probes. System Health > Queue also lists the pending job types (read-only).
* General runbook for a silent worker: compare the worker's start command connection/queue with `QUEUE_CONNECTION`/`DB_QUEUE`; compare `DB_*` and `APP_KEY` with web; read the worker logs; inspect pending job types (System Health) BEFORE starting a worker, because starting it runs everything waiting. Never flush or clear the queue to "fix" this.

### 5.2 Release identity
* The app shows only what its own process was given: `APP_COMMIT_SHA`, else `SOURCE_COMMIT` (`config/system-health.php`), validated as 7–40 hex characters by `App\Support\ReleaseIdentity`. Missing or malformed values show "Unverified". The card shows which variable supplied the commit and whether the build time is `APP_BUILD_AT` or only the build-manifest file time. No SHA is hardcoded anywhere.
* Observed Staging state: commit and worker commit were "Unverified" because no commit variable was present in the PHP process when `config:cache` ran during the build.
* **To be verified in Coolify/Railpack before changing any setting** (nothing here is assumed to exist): (a) whether Coolify exposes `SOURCE_COMMIT` to the BUILD step of this resource (it is a build-time requirement because `config:cache` runs in the build); (b) whether Railpack forwards that variable into the build environment (check the build log), or whether it must be declared as an explicit build variable; (c) whether `$SOURCE_COMMIT` substitution is supported in the variable value field for this resource type; (d) how to produce a build timestamp for `APP_BUILD_AT`. Until verified, the page correctly stays "Unverified".
* Worker comparison: each probe reports the worker's own cached config, so a worker not restarted after a deploy reports the old SHA and the page shows "NO — redeploy worker". Short and full SHAs of the same commit compare equal.
