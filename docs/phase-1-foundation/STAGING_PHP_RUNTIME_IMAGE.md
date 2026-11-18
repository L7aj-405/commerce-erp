# Staging PHP runtime image — Morocco legal-time fix

> Scope: **staging only**. Nothing here was deployed or restarted. Staging **Auto Deploy stays on "Manual deployments only"** until the runtime rollout below is verified. Production is a separate release requirement.
> Companion of `STEP_3_MOROCCO_BUSINESS_DATES.md` (F-02, PR #27, merged into `staging` at `df24401`).

## 1. Problem (observed on a staging PHP container)
- `timezone_version_get()` = `2025.3`; `Africa/Casablanca` offset at 2026-11-17 12:00 UTC = `3600` (expected `0`).
- Effect: every Moroccan business date after 2026-09-20 is shifted by one hour (invoice/credit-note dates and their fiscal-year numbering).

## 2. Root cause
- PHP does **not** read the operating system's tzdata: the timezone database is compiled into the PHP binary (timelib) at the PHP release, unless the PECL `timezonedb` extension overrides it.
- The repository has **no Dockerfile, Compose file or `nixpacks.toml`/`railpack.json`**, so Coolify builds the application with its automatic **Railpack** build strategy (the earlier "Nixpacks" wording in `DEPLOYMENT_CHECKLIST.md` was outdated and is corrected). Railpack's PHP provider runs the application on **FrankenPHP** (its own FrankenPHP image, Caddy, its own `php.ini`); the PHP release inside that image embeds tzdb `2025.3`. Updating the host, `apt`, or restarting the container cannot change it.
- What the Railpack docs state (checked): extensions come from `composer.json` `ext-*` and `RAILPACK_PHP_EXTENSIONS`; the Laravel start script runs migrations (unless `RAILPACK_SKIP_MIGRATIONS`), `storage:link` and `optimize`. They do **not** document installing a **pinned PECL** extension such as `timezonedb`, nor timezone data. A Railpack-only fix (e.g. `RAILPACK_PHP_EXTENSIONS=timezonedb`) is therefore **not validated** and would not be version-pinned; it was not chosen. It could be evaluated separately as a lower-impact alternative.
- Inference to confirm in Coolify: the staging resource settings are not readable from the repository.

## 3. The fix (repository changes — five files)
| File | Purpose |
|---|---|
| `Dockerfile` | Multi-stage build: Node asset build; official `php:8.4-apache-bookworm` runtime with the extensions the app needs; **`pecl install timezonedb-2026.6` pinned (same pin as CI)**; `RUN php scripts/check-timezone-runtime.php` as a **build gate**; Composer `--no-dev`; PHP and Apache limits sized for the application (below). |
| `docker-entrypoint.sh` | **Start-time gate** for every container (no bypass flag). Role from `APP_ROLE` (`web` default, `worker`, `scheduler`) or an explicit command. Runs as `www-data`. Worker/scheduler run under a small signal-forwarding wrapper (see 'Stop signal' below). Role-started processes run `artisan optimize` (`APP_OPTIMIZE`, default true); web also creates the storage link; `RUN_MIGRATIONS=true` runs migrations (default off); **`RUN_SCHEDULER=true` (web only) runs exactly one `schedule:work` next to Apache** (default off). |
| `.dockerignore` | Keeps `.env*`, `vendor`, `node_modules`, tests, docs and storage contents out of the build context (no secret enters the image). |
| `docs/phase-1-foundation/STAGING_PHP_RUNTIME_IMAGE.md` | This document. |
| `DEPLOYMENT_CHECKLIST.md` | Replaces the outdated "Nixpacks is fine, no Dockerfile" paragraph. |
`scripts/check-timezone-runtime.php` already exists from PR #27 and is reused unchanged.

One image, three roles ⇒ the **same PHP binary and the same tzdb** in web, worker and scheduler.

### Review of the runtime configuration
| Area | Decision |
|---|---|
| Apache DocumentRoot / routing | `DocumentRoot` and the `<Directory>` set to `/var/www/html/public`; `mod_rewrite` on, `AllowOverride All` so `public/.htaccess` routes everything to `index.php`; `headers` enabled; `ServerName localhost`. |
| PHP extensions | `bcmath`, `pdo_mysql`, `zip` (required by `composer.json`), `gd` (Dompdf images), `pcntl` (queue signal handling), `intl`, `opcache`, `timezonedb` 2026.6 (pinned) and **`redis` (phpredis 6.3.0, pinned)**: `REDIS_CLIENT` defaults to `phpredis` (`config/database.php`) and `predis` is not installed, so without this extension any Redis cache/queue/session setting fails at boot. `mbstring`, `ctype`, `tokenizer`, `xml`, `fileinfo`, `openssl` come with the official image. |
| Uploads | `upload_max_filesize=224M`, `post_max_size=256M`: the app accepts backup archives up to `ORG_BACKUP_MAX_ARCHIVE_BYTES` (214 748 364 B ≈ 205 MiB), fulfillment attachments 5 × 10 MiB, catalog imports 5 MiB, document uploads 2 MiB. The `php.ini-production` defaults (2M/8M) would have rejected them. **If Coolify/Traefik or a proxy in front limits body size, raise it too.** |
| PDFs / exports / timeouts | `memory_limit=512M`, `max_execution_time=300`, `max_input_time=300`, Apache `Timeout 300` for Dompdf and ZIP exports; worker/scheduler are CLI (no execution limit; `queue:work --timeout=3600`, `retry_after=3900` stays larger). Traefik's own timeouts must also allow long exports. |
| Apache concurrency | prefork, `MaxRequestWorkers 40`, `MaxConnectionsPerChild 500`, `KeepAliveTimeout 5`: bounded so a small staging VPS is not exhausted (PHP memory per worker × 40). |
| Persistent storage | Mount a persistent volume on `/var/www/html/storage` (organization backups in `storage/app/private/organization-backups`, public uploads in `storage/app/public`, logs). The entrypoint creates the framework directories and `chown`s only entries not already owned by `www-data` (no full re-chown of a large volume on each start). `public/storage` link is created at web start. |
| Permissions | Apache and the worker/scheduler run as `www-data`; `storage` and `bootstrap/cache` writable by it; the container does not run application code as root. |
| Secrets | No `.env` and no secret in the image (`.dockerignore`, no `ARG`/`ENV` secrets). All configuration through Coolify environment variables, as before. Build needs outbound network only for npm, Composer and Bunny Fonts. |
| Queue worker / scheduler | Same image. Worker: `APP_ROLE=worker`. Scheduler: **inside the web container** with `RUN_SCHEDULER=true` (one `schedule:work` supervised by a restart loop, run as `www-data`) — no extra Coolify resource; `APP_ROLE=scheduler` still exists for a dedicated container but is not needed. The flag is only honoured by the web role (a worker with the flag set starts no scheduler: tested). Both start only after the timezone gate. **Stop signal:** the official `php:*-apache` image sets `STOPSIGNAL SIGWINCH` (Apache's graceful stop) and `docker stop`/Coolify sends exactly that; PHP ignores it, so a plain `exec php` was SIGKILLed after the grace period (observed: 31 s, exit 137). The entrypoint now keeps a tiny wrapper as PID 1 that forwards SIGWINCH/SIGTERM/SIGINT to PHP as SIGTERM: worker stops in ~2 s with exit 0 after finishing its job; Apache keeps its own graceful stop. |
| Behaviour difference vs Railpack | Railpack's start script ran **migrations, `storage:link` and `optimize` automatically**. This image does `storage:link` and `optimize` but **not migrations**: use a Coolify post-deploy command (web only) or set `RUN_MIGRATIONS=true` on the **web** resource only. Do not forget it, or the schema stays behind the code. |
| Web server change | Railpack serves FrankenPHP/Caddy; this image serves Apache/mod_php: re-verify HTTPS behind Traefik (trusted proxies, `APP_URL`, `SESSION_SECURE_COOKIE`), large uploads and long exports on staging before UAT. |

## 4. Validation (disposable containers, no deployment)
Final image `erp-runtime:staging-candidate` (`sha256:8c035e45…`), built with `docker build .` (exit 0; the build gate printed `php=8.4.27 tzdb=2026.6 extension[timezonedb]=loaded`).
| Check | Result |
|---|---|
| Web, worker and scheduler started from the **same image ID** against a disposable MySQL 8.4 | all three `Up`; web `GET /up` = HTTP 200 |
| `php scripts/check-timezone-runtime.php` inside each of the three containers | `tzdb=2026.6`, `timezonedb` loaded, `OK: 11 probes match Morocco's legal time; no clock change in 2027` |
| Process users | Apache master root + workers `www-data`; `queue:work` and `schedule:work` run directly as `www-data` (no wrapper process) |
| Scheduler | scheduled tasks and both heartbeats `DONE` |
| Storage link / permissions | `public/storage -> /var/www/html/storage/app/public` created at web start; `storage` and `bootstrap/cache` owned by `www-data`; `artisan optimize` produced `bootstrap/cache/config.php` |
| Routing and docroot (previous run of the same Dockerfile) | `/`, `/login`, `/up`, `/build/manifest.json` = 200; unknown route = 404; `/.env`, `/composer.json`, `/storage/logs/laravel.log` = 404 (not served); Apache config `Syntax OK`, `rewrite`, `headers`, `mpm_prefork` loaded |
| One-off `php artisan migrate --force` through the entry-point (disposable DB) | all migrations `DONE` |
| Entry-point refuses to start with a failing guard (simulated) / unknown `APP_ROLE` | prints the failure, exit 1 / exit 64 |
| No secrets or tests in the image | only `.env.example`; no `tests/`, `node_modules/` |
| `MoroccoLegalTimeTest`, `BusinessDatesFiscalNumberingTest`, `BusinessDateTimezoneTest`, F-01 `CustomerBalanceNoCrossOrderNettingTest`, Architecture guards, with the built image as the PHP runtime | **56 tests, 647 assertions, 0 failed, 0 skipped** |
Not verified locally: the PHP upload/post/memory limits and the Apache worker settings were set and read from the configuration but **not exercised end-to-end** (no 20 MiB upload, no long PDF/ZIP export, no graceful-stop timing, no `RUN_MIGRATIONS=true` run); Coolify, Traefik/TLS and real storage volumes. These belong to the staging smoke test below.

## 5. Scheduler, Redis and the Coolify layout (four resources: web, worker, MySQL, Redis)

### How the scheduler runs today (what could and could not be determined)
- The repository contains no scheduler definition for the web container, and **Railpack's own Laravel start script does not run a scheduler or a queue worker** (it runs `migrate --force` unless `RAILPACK_SKIP_MIGRATIONS=true`, `storage:link`, `optimize:clear`, `optimize`, then starts FrankenPHP). So the scheduler that runs in the staging web application is **configured in Coolify**, most likely either (a) a **Scheduled Task** on the web resource (`php artisan schedule:run` every minute, executed with `docker exec` inside the web container, as root), or (b) a custom **Start Command**/shell wrapper. This must be confirmed in the Coolify UI (web resource: *Scheduled Tasks*, *Start Command*, *Custom Docker Options*) and the exact command **written down before changing anything** (it is the rollback data).
- With the Dockerfile image: a start-command override does not exist for the Dockerfile build strategy (it is ignored/absent), so (b) stops working. (a) keeps *working* but is **harmful**: `docker exec` runs as root, so `storage/logs/laravel-*.log` and cache files created by the scheduler become root-owned and the Apache `www-data` workers can no longer write them. Keeping (a) together with `RUN_SCHEDULER=true` would also start a **second scheduler**.
- Recommended smallest change (this PR): set **`RUN_SCHEDULER=true` on the web resource only** and **delete the old scheduler mechanism** (Scheduled Task / start-command wrapper). One web instance ⇒ exactly one scheduler, as `www-data`, no fifth resource. If the old Scheduled Task is deliberately kept instead, its command must be `setpriv --reuid=www-data --regid=www-data --init-groups php artisan schedule:run` and `RUN_SCHEDULER` must stay unset.
- Overlap warning: during a Coolify rolling update the old and new web containers briefly coexist; tasks with `withoutOverlapping()` are protected by cache locks, others could run twice in that window. Stop-then-start (or accept the short overlap) — never run a second scheduler resource.

### Redis
Cache/queue/session settings of the Coolify environment are unchanged; the image only has to provide the client. `REDIS_CLIENT=phpredis` (default) needs the extension installed in this PR. Validation: with `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` and `SESSION_DRIVER=redis` (Redis container on the same network) a closure dispatched from the web container was processed by the worker (`DONE`), the cache round-trip worked, and the scheduler's `RecordQueueWorkerHeartbeatJob` ran through Redis. Railpack added the Redis extension automatically when `REDIS_HOST`/`REDIS_URL`/`CACHE_DRIVER`/`SESSION_DRIVER`/`QUEUE_CONNECTION` were set; that is why Redis worked before and had to be made explicit here.

### Required Coolify configuration
| Setting | Web resource | Worker resource |
|---|---|---|
| Build strategy | **Dockerfile** (base dir `/`, file `Dockerfile`), branch `staging` | **Dockerfile**, same repository, same branch, same commit |
| `APP_ROLE` | unset (= web) | **`worker`** |
| `RUN_SCHEDULER` | **`true`** (this is the only scheduler) | **unset** |
| `RUN_MIGRATIONS` | `true` for the first deploy only after the DB snapshot (equivalent to Railpack's automatic migrate), or a post-deploy command; then remove | never |
| Application env (APP_KEY, DB_*, REDIS_*, QUEUE_CONNECTION, CACHE_STORE, SESSION_DRIVER, ...) | unchanged | **identical to web** (same APP_KEY, same DB/Redis) |
| Ports Exposes / domain | `80`, staging domain | `80` can stay but **no domain** |
| **Health check** | enabled, `GET /up` | **disabled** (no HTTP server in the worker: an enabled check would mark it unhealthy and roll the deployment back) |
| Scheduled Task (old `schedule:run`) | **delete** (record it first) | none |
| Start command / custom start wrapper | not available for the Dockerfile strategy; remove if set | same |
| Persistent storage | volume on `/var/www/html/storage` | **the same host directory** if the worker writes files the web serves (organization backups under `storage/app/private/organization-backups`, exports): verify how it is shared today, a per-resource volume would break downloads. Mounted directories are re-owned to `www-data` (uid 33) at start. |
| Auto Deploy | stays **"Manual deployments only"** | same |
| Stop grace period | default is enough (worker exits in ~2 s when idle; a long job gets the Coolify grace period) | |
| MySQL / Redis resources | untouched | untouched |

### Exact deployment order (every step is a manual deployment; production untouched)
0. Preconditions: PR merged into `staging`; DB snapshot (`DEPLOYMENT_CHECKLIST.md` §9); the **current scheduler configuration copied down**; the Coolify environment of web and worker compared (they must match).
1. Web resource: delete the old scheduler mechanism; switch to the Dockerfile build strategy; set `RUN_SCHEDULER=true` (and `RUN_MIGRATIONS=true` for this first deploy); keep health check `/up`.
2. **Deploy web (manual).** Wait for healthy. Verify: `docker exec <web> php scripts/check-timezone-runtime.php` → `OK tzdb=2026.6`; exactly one `schedule:work` (`ps -eo args | grep -c "artisan schedule:work"` → 1); `/up` 200; log shows scheduled tasks `DONE`.
3. Worker resource: switch to the Dockerfile strategy, `APP_ROLE=worker`, health check disabled; **deploy (manual)**. Verify the guard in the worker container, `queue:work` running as `www-data`, and heartbeat jobs `DONE` in its log; then `php artisan queue:restart` is not needed (fresh process).
4. Remove `RUN_MIGRATIONS=true` from web (or leave the post-deploy command). Check System Health: scheduler heartbeat < 3 min, queue worker heartbeat.
5. Functional smoke (Casablanca organization, midnight default date; login; PDF export; upload > 8 MiB; catalog import; organization backup created by the worker and downloadable from the web).
6. Only then may Auto Deploy be reconsidered.

## 6. Rollback (staging)
1. Redeploy the **previous deployment** of web and worker (previous Railpack build), or switch the build strategy back to Railpack and deploy manually.
2. **Restore the old scheduler mechanism** exactly as recorded in step 0 and unset `RUN_SCHEDULER` (never two schedulers).
3. Re-run the caches / `queue:restart` of `DEPLOYMENT_CHECKLIST.md` §5. The database is untouched by this change.
4. Expect the old (wrong) tzdb again: Moroccan business dates are an hour off until the image fix is redone — do not run date UAT on a rolled-back staging.

## 7. Risks and open points
- The Railpack → Dockerfile switch changes the web server (Apache/mod_php instead of FrankenPHP/Caddy) and the start sequence (no automatic migrations). Validate HTTPS behind Traefik, uploads, large PDF/ZIP exports and request timeouts on staging before UAT; this was only smoke-tested locally.
- Coolify/Traefik limits (request body size, timeouts) are outside the image and may be lower than the PHP limits above.
- The PHP resource limits (`memory_limit=512M`, 40 workers) assume the staging VPS has enough RAM; lower `MaxRequestWorkers` if it does not.
- `PECL timezonedb-2026.6` and the check table must be bumped together at the next legal change.
- Production keeps the old runtime until it receives the same treatment under its own authorization.
- The scheduler runs `schedule:work` (equivalent to a per-minute `schedule:run`); keep exactly one instance.
- The scheduler in the web container is a supervised background loop, not a process manager: if Apache (PID 1) exits the container restarts everything; if `schedule:work` crashes it is restarted after 5 s. Do not scale the web resource beyond one instance without moving the scheduler to a dedicated, single-instance container (`APP_ROLE=scheduler`).
- What was validated for the scheduler/Redis change (disposable MySQL 8.4 + Redis 8 + this image): one `schedule:work` in web (`www-data`), none in a worker even with the flag set by mistake, respawn after `pkill`, still one after `docker restart`; Redis cache/session/queue with phpredis end to end; `docker stop` timings worker 1.8 s / scheduler 2.3 s / web 4.1 s, all exit 0; exit status of one-off commands propagated; Apache routes after restart; timezone guard `OK` in each container.
- Not validated: Coolify itself (build strategy switch, Scheduled Tasks, health checks, shared storage), Traefik/TLS, large uploads/long exports.
