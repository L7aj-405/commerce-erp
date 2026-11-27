# Production runbook — production-consolidation-01

**Approval gate:** nothing below may be executed until the release is explicitly approved.
`$WWW` below means `setpriv --reuid=www-data --regid=www-data --init-groups` (Coolify terminals run as root; running
artisan as root leaves root-owned logs and cache files that Apache cannot write).
Steps marked **(verify)** depend on Coolify settings that are not readable from the repository.

## 0. Before the PR is merged — mandatory

1. **Disable auto-deploy** on the production Web and Worker resources. Railpack's Laravel start script runs
   `migrate --force` unless `RAILPACK_SKIP_MIGRATIONS=true`, so an automatic deploy of the merge could migrate
   production unattended, without a backup.
2. Set `RAILPACK_SKIP_MIGRATIONS=true` on the current Web and Worker (takes effect at the next container start).
3. **Rescue the Worker's files before anything restarts or redeploys it.** It has no volume, so anything it wrote
   (e.g. scheduled organization backups with the local driver) exists only inside its container:
   `docker cp <worker-container>:/app/storage/app ./worker-storage-$(date +%F)` — then list the content.
4. Record (this is the rollback data), for Web and Worker: image / deployment id, build pack, start command,
   health check, port, Scheduled Tasks, all environment variables (into the secret vault), and
   `docker inspect <container> --format '{{json .Mounts}}'` → **volume names** for `/app/storage/app` and
   `/app/storage/app/public`, and their `Mountpoint` (`docker volume inspect <name>`).
5. Confirm Web and Worker run on the **same server** (local volumes cannot be shared across servers: blocker).
6. Identify the one existing scheduler (Scheduled Task, cron or start command) **(verify)**.

## 1. Preflight (read only, production — needs approval to access production)

```bash
mysql --table --default-character-set=utf8mb4 -h HOST -u USER -p DATABASE < scripts/release/preflight.mysql > preflight.txt
```

Any `STOP` → do not continue. Nothing is auto-corrected: a violating row is reviewed and fixed through the audited
business workflow, as a separately approved action. Every `REVIEW` line is signed off by name (P05, P07 are expected).

## 2. Rehearsal on an isolated clone (required before the window)

1. Take a dump: `mysqldump --single-transaction --routines --triggers --hex-blob --default-character-set=utf8mb4 DB > prod.sql`.
2. Restore into a throw-away MySQL **8.4** (same major as production) on a host with no route to production.
   The dump contains personal and financial data: restrict access, delete it afterwards.
3. Run the release image against the clone only, as one-off commands. **No worker, no scheduler, no web exposure**, a
   dummy `APP_KEY`, `MAIL_MAILER=array`, no WooCommerce / Google / S3 credentials, egress blocked.
4. `preflight.mysql` → `integrity-snapshot.mysql > before.tsv` → `time php artisan migrate --force` →
   `integrity-snapshot.mysql > after.tsv` → `diff before.tsv after.tsv` → `postflight.mysql` →
   `php artisan finance:reconcile <org>` for every organization.
5. Pass criteria: no `STOP`; the diff shows only added `PERMISSION` / `GRANT` lines and the `MIGRATIONS` count
   (132 → 147); reconcile shows no unexplained variance; the duration sets the window length (add a wide margin).
6. Rehearse the restore of the dump too, and time it.

## 3. Preparatory change (a day before, optional but recommended)

Add to the **current** Web resource and redeploy the same main commit:
`APP_MAINTENANCE_DRIVER=cache`, `APP_MAINTENANCE_STORE=database` (and keep `RAILPACK_SKIP_MIGRATIONS=true`).
Maintenance mode then lives in the database, so the **new** container boots already in maintenance.
Verify both volumes still show the same names and files. Without this step, remove the public domain / block the
route at the proxy before step 5.6, otherwise users can reach the unmigrated new code.

## 4. Coolify configuration

### Environment (Web **and** Worker, identical unless stated)

| Variable | Value |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_KEY`, `APP_URL`, `DB_*`, `MAIL_*`, `ORG_BACKUP_*` | **unchanged** — never regenerate keys |
| `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER` | `database` (Redis/Valkey stays installed and unused; `CACHE_STORE` etc. must be set explicitly) |
| `DB_QUEUE_RETRY_AFTER` | `3900` (> worker `--timeout=3600`) |
| `APP_MAINTENANCE_DRIVER` / `APP_MAINTENANCE_STORE` | `cache` / `database` |
| `RUN_MIGRATIONS` | `false` (stays false) |
| `POS_DRAFT_CLEANUP_ENABLED` | `false` |
| `SUPPLIER_INVOICES_ENABLED` | `false` unless approved for this release |
| `APP_ROLE` | Web: unset (`web`). Worker: `worker` |
| `RUN_SCHEDULER` | Web: `false` during the window, `true` at step 5.10. Worker: unset |

### Resources

| Setting | Web | Worker |
|---|---|---|
| Build pack | Dockerfile (repository root) | Dockerfile (same commit) |
| Start command | **empty** | **empty** (entrypoint runs `queue:work --tries=3 --timeout=3600`) |
| Port | `80` **(verify** the old Railpack port and change it) | none |
| Health check | GET `/up`, port 80, 200 | **disabled** |
| Instances | exactly 1 | 1 |
| Scheduled Tasks | **delete** the old `schedule:run` | none |
| Stop grace period | default | raise if long jobs (backups 1800 s, WooCommerce 3600 s) may be running **(verify)** |

Remove Railpack-only variables from the new configuration only after the release is accepted (they are rollback data).
Proxy body-size and timeout limits must allow the PHP limits (upload 224 MB, 300 s).

### Storage: same physical volumes, new paths

| Existing volume (by its recorded name) | Old destination | New destination (Web and Worker) |
|---|---|---|
| volume A | `/app/storage/app` | `/var/www/html/storage/app` |
| volume B | `/app/storage/app/public` | `/var/www/html/storage/app/public` |

- Keep both volumes and the same nesting (B mounted inside A). Do not merge them, do not delete a storage entry,
  and do not create a new "volume mount" by name: Coolify prefixes names with the resource UUID, which yields an
  **empty** volume.
- Web: change only the destination path of the two existing entries **(verify** the UI keeps the name). If it cannot,
  stop and use the bind method below.
- Worker: add two **bind mounts** whose source path is each volume's `Mountpoint`
  (`/var/lib/docker/volumes/<name>/_data`) **(verify** Coolify accepts it).
- Never run `docker volume rm` / `prune` during the release. Preview deployments off.
- Worker files rescued in step 0.3: copy them into the shared volume **without overwriting**
  (`cp -an worker-storage-…/. <Mountpoint A>/`; files under `public/` go to volume B).
- The entrypoint changes ownership to `www-data` (uid 33) on start. The old Railpack image can still read them as root.

Rehearsed locally with disposable volumes: identical checksums after the path change, public files served, a file
written by the worker readable by the web, and still readable at the old paths. Coolify itself was not rehearsed.

## 5. Maintenance window

1. Announce. Confirm approvals, preflight and clone rehearsal. Keep the old images (no Docker prune).
2. Remove / disable the old scheduler. Wait until `jobs` is empty (P08), then **stop the Worker**.
3. Old Web: `php artisan down --retry=60 --secret=<long-random>`; verify HTTP 503 on `/login`.
4. **Backups**, all verified readable and copied off the server:
   - `mysqldump --single-transaction --routines --triggers --hex-blob …` (record size and checksum; test-restore into a scratch database);
   - archive of both volumes (`tar` of each `Mountpoint`) and of the rescued worker files.
5. `integrity-snapshot.mysql > before.tsv`. Re-run `preflight.mysql` (no `STOP`).
6. Merge the PR (if not yet merged), configure Web per §4 with `RUN_SCHEDULER=false`, deploy **Web only**.
7. If step 3 was skipped: keep the route blocked and run `$WWW php artisan down --secret=…` in the new container now.
   In the new Web container verify: `/up` 200, `/login` 503; mounts (`docker inspect`: same volume names, new paths);
   expected files present; `php scripts/check-timezone-runtime.php` OK; `$WWW php artisan config:show cache.default`
   (`database`, likewise `queue.default`, `session.driver`); `$WWW php artisan migrate:status` → exactly 15 pending.
8. Migrate, once: `$WWW php artisan migrate --force 2>&1 | tee storage/logs/release-migrate.log` (`set -o pipefail`).
9. Verify before reopening:
   - `postflight.mysql` → no `STOP`;
   - `integrity-snapshot.mysql > after.tsv`; `diff before.tsv after.tsv` → only added `PERMISSION`/`GRANT` lines and `MIGRATIONS 147`;
   - `$WWW php artisan finance:reconcile <org> --months=12` per organization, compared with the clone results;
   - through the secret URL, read-only: login, dashboard, an order, an invoice PDF with logo/stamp, a stored
     backup download, contacts directory, receivables for a known customer. Do not create data.
10. Deploy the Worker (§4) and verify the shared files and the timezone check. Set `RUN_SCHEDULER=true` on Web and
    redeploy. Confirm exactly one scheduler.
11. `$WWW php artisan up`. System Health: database OK, scheduler and queue heartbeats < 3 min, backup keys present.
12. Watch logs, `queue:failed`, 5xx and disk for at least one business day. Run one organization backup and download it.

## 6. Rollback and recovery

| Failure point | Action |
|---|---|
| Before step 8 (nothing migrated) | Redeploy the recorded Railpack deployments, restore mounts to `/app/storage/...`, old start command, port, scheduler; `php artisan up` |
| Migration fails, or any step 9 check fails | Stay in maintenance, writes closed. **Restore the dump into an empty database** (drop and recreate, or a new database + repoint `DB_DATABASE`) — restoring over a half-migrated schema leaves new tables behind. Verify: snapshot equals `before.tsv`, preflight P02/P03 `PASS`. Then roll the application back as above |
| Transient failure (lock timeout, disconnect) in an idempotent migration (1, 2, 5, 9, 14, 15) | Fix the cause and re-run `migrate --force` |
| After writes reopened | Prefer **fix forward**. A database restore discards every transaction since step 4 and needs a business decision |
| Volume / file problem | Stop containers, check mount names first (data is most likely in the original volume); restore from the step 4 archive only into the affected volume |

Code-only rollback (keeping the new schema) is possible — rehearsed: main code created a customer, supplier, order and
payment on the upgraded database — but it is a last resort: it brings back the old customer-balance calculation (F-01),
the outdated timezone database (dates shift by one hour) and removes the closed-period guards; new-feature data
(advances, allocation releases, supplier invoices, opening balances) is ignored or misread by old code. Keep
`RAILPACK_SKIP_MIGRATIONS=true`, never `migrate:rollback`, and when rolling forward again link new rows:
`php artisan tinker --execute='app(App\Services\Contacts\PartyBackfillService::class)->run();'` then `postflight.mysql` V03.

## 7. Sign-off record

Release SHA · image digest · approver · preflight file · clone duration · dump checksum · before/after diff ·
reconcile output · window start/end · decisions on P05, P07 and `SUPPLIER_INVOICES_ENABLED`.
