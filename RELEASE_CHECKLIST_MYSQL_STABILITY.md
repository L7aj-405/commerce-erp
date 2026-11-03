# Release checklist — MySQL compatibility & concurrency fixes

Release-specific steps. The general procedure (build, deploy order, backups,
rollback) stays in `DEPLOYMENT_CHECKLIST.md`; § numbers refer to it. Local
verification commands: `LOCAL_MYSQL_TESTING.md`.

## What this release changes

| Area | Change | Schema impact |
|---|---|---|
| Migration `2026_11_17_000001_widen_purchase_orders_status_column` | `purchase_orders.status` VARCHAR(16) → VARCHAR(24) so `partially_received` (18 chars) fits | In-place widen, no data change |
| `App\Support\LockedRow` + 15 generators / `InventoryBalanceLocker` / transfer planning | Create-and-lock counter / balance rows with one exclusive lock (`INSERT … ON DUPLICATE KEY UPDATE`) — removes MySQL deadlocks on concurrent document creation and stock operations | None (relies on existing PK/unique keys — preflight [9]) |
| `SalesMarginReportService` | `ROUND(qty × cost, 4)` per return line | None |
| Tests / docs / dev tooling | MySQL test compatibility, `phpunit.mysql.xml`, concurrency scripts, local-only seeder (refuses non-local) | None |

Only `2026_11_17` is new. Every other migration is unchanged from what is
already committed; which of them are **pending on the server** is unknown
until preflight [4] is run.

## 1. Read-only preflight (server)

Run `PREFLIGHT_MYSQL_STABILITY.sql` (SELECT/SHOW only, validated locally) with
a read-only account, e.g. `mysql --init-command="SET SESSION TRANSACTION READ ONLY" -t <db> < PREFLIGHT_MYSQL_STABILITY.sql > preflight.txt`.

| Check | Expected (GO) | NO-GO / action |
|---|---|---|
| [1] version / isolation / sql_mode | MySQL ≥ 8.0, `REPEATABLE-READ`, utf8mb4 | `READ-COMMITTED` → re-run both `tests/Concurrency/*` locally with that isolation first. MariaDB/5.7 → stop. |
| [2] deadlock counters | any value — record it as the baseline | — |
| [4] pending migrations | `PENDING` rows form a contiguous tail ending with `2026_11_17_000001…` | A `PENDING` row *before* an applied one (gap) → review that migration before deploying. |
| [5] unknown server migrations | 0 rows | Any row → a renamed/removed migration; investigate, do not deploy. |
| [6] column definitions | `purchase_orders.status varchar(16) NOT NULL` (or 24 if already widened) | Anything else → review. |
| [7] stored PO statuses | only `draft, ordered, partially_received, received, cancelled` | Truncated value (e.g. `partially_recei`) → data written in non-strict mode: fix data first. (The app forces strict mode per connection, so this is not expected.) |
| [8] duplicates for `proc_src_request_uq` | 0 rows — needed only if `2026_11_12_000001` is PENDING | Rows → resolve duplicates first or that migration fails. |
| [9] unique keys used by `LockedRow` | 19 rows: 18 `*_sequences` PRIMARY + `inv_bal_identity_unique` (fewer finance rows if their migrations are pending) | A missing key → **NO-GO** (the upsert could duplicate counter rows). |

Pending-migration risk notes (only those [4] marks PENDING apply):

- `2026_11_12_000001` — new UNIQUE on an existing column (check [8]) and two
  FK columns made nullable via `change()` (table rebuild; small table).
- `2026_11_07_000001` — backfills `received_quantity` for received procurements (idempotent).
- `2026_11_14/15/16` — new nullable columns, FKs / unique on those new columns only: safe on existing data.
- `2026_11_17_000001` — in-place widen; `down()` refuses (strict mode) once a PO is `partially_received` — by design.

## 2. Gate (all must be true)

- [ ] Preflight table above all GO.
- [ ] `DEPLOYMENT_CHECKLIST.md` §2 gate satisfied.
- [ ] Local MySQL suite 2,082/2,082, concurrency scripts PASSED, `tsc` + build OK (done 2026-11-03).
- [ ] Release committed and tagged; the deployed commit = the tested tree.

## 3. Deployment sequence

1. **Commit** the 36 reviewed paths (28 modified + 8 new) on a release branch / tag; deploy that exact commit.
   `PREFLIGHT_MYSQL_STABILITY.sql` is git-ignored by the repo's `*.sql`
   dump-protection rule: keep it with the release notes, or `git add -f` it deliberately.
2. **Maintenance window**: announce; optionally `php artisan down` during migrate.
3. **Backup** (§9): manual MySQL snapshot immediately before migrating; confirm it
   completed and is downloadable; note name + time + preflight [3] `latest`.
4. **Preflight** re-run of [3]/[4] right before migrating (nothing changed since).
5. **Deploy code** (§5): build, `composer install --no-dev`, caches.
6. **Pretend**: `php artisan migrate --pretend --force` — must list exactly the
   [4] PENDING migrations, ending with
   `alter table purchase_orders modify status varchar(24) not null`.
7. **Migrate**: `php artisan migrate --force`. Never `migrate:fresh`, `db:seed`, `--seed`.
8. **Restart** queue workers / scheduler (§5) so they load the new code; `php artisan up`.
9. **Verify**: re-run preflight [4] (0 PENDING) and [6] (`varchar(24)`).

## 4. Smoke tests (throwaway organization, test mailer, §11)

- [ ] Purchase order: receive **part** of a line → `Partiellement réceptionnée` (was a 500).
- [ ] Two sales orders / POS sales created at the same moment from two browsers → both succeed, distinct numbers.
- [ ] Issue an invoice, record a payment, receive a supplier delivery → numbers allocated, stock updated.
- [ ] Showroom replenishment sweep + POS pickup needing depot stock → one customer-linked request (customer + replenishment), no duplicate.
- [ ] Sales margin report over a period with a received return → loads.
- [ ] System Health green; preflight [2] deadlock counter not climbing; no `Deadlock found` in logs during the first hours.

## 5. Rollback

- **Code problem, schema fine:** redeploy the previous commit (§14). Safe — the
  previous code works with `varchar(24)`.
- **Migration failure / abandon release:** restore the step-3 snapshot (§9/§14),
  then redeploy the previous commit.
- **Do not** `migrate:rollback` 2026_11_17 once any PO is `partially_received`
  (it refuses by design); leave the column wide.
