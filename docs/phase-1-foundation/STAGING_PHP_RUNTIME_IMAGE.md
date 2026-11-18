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
| `docker-entrypoint.sh` | **Start-time gate** for every container (no bypass flag). Role from `APP_ROLE` (`web` default, `worker`, `scheduler`) or an explicit command. Runs as `www-data` through `exec setpriv` (no intermediate process: the worker gets SIGTERM directly). Role-started processes run `artisan optimize` (`APP_OPTIMIZE`, default true); web also creates the storage link; migrations only if `RUN_MIGRATIONS=true` (default off). |
| `.dockerignore` | Keeps `.env*`, `vendor`, `node_modules`, tests, docs and storage contents out of the build context (no secret enters the image). |
| `docs/phase-1-foundation/STAGING_PHP_RUNTIME_IMAGE.md` | This document. |
| `DEPLOYMENT_CHECKLIST.md` | Replaces the outdated "Nixpacks is fine, no Dockerfile" paragraph. |
`scripts/check-timezone-runtime.php` already exists from PR #27 and is reused unchanged.

One image, three roles ⇒ the **same PHP binary and the same tzdb** in web, worker and scheduler.

### Review of the runtime configuration
| Area | Decision |
|---|---|
| Apache DocumentRoot / routing | `DocumentRoot` and the `<Directory>` set to `/var/www/html/public`; `mod_rewrite` on, `AllowOverride All` so `public/.htaccess` routes everything to `index.php`; `headers` enabled; `ServerName localhost`. |
| PHP extensions | `bcmath`, `pdo_mysql`, `zip` (required by `composer.json`), `gd` (Dompdf images; checklist recommends), `pcntl` (queue signal handling), `intl`, `opcache`, plus `timezonedb`; `mbstring`, `ctype`, `tokenizer`, `xml`, `fileinfo`, `openssl` come with the official image. |
| Uploads | `upload_max_filesize=224M`, `post_max_size=256M`: the app accepts backup archives up to `ORG_BACKUP_MAX_ARCHIVE_BYTES` (214 748 364 B ≈ 205 MiB), fulfillment attachments 5 × 10 MiB, catalog imports 5 MiB, document uploads 2 MiB. The `php.ini-production` defaults (2M/8M) would have rejected them. **If Coolify/Traefik or a proxy in front limits body size, raise it too.** |
| PDFs / exports / timeouts | `memory_limit=512M`, `max_execution_time=300`, `max_input_time=300`, Apache `Timeout 300` for Dompdf and ZIP exports; worker/scheduler are CLI (no execution limit; `queue:work --timeout=3600`, `retry_after=3900` stays larger). Traefik's own timeouts must also allow long exports. |
| Apache concurrency | prefork, `MaxRequestWorkers 40`, `MaxConnectionsPerChild 500`, `KeepAliveTimeout 5`: bounded so a small staging VPS is not exhausted (PHP memory per worker × 40). |
| Persistent storage | Mount a persistent volume on `/var/www/html/storage` (organization backups in `storage/app/private/organization-backups`, public uploads in `storage/app/public`, logs). The entrypoint creates the framework directories and `chown`s only entries not already owned by `www-data` (no full re-chown of a large volume on each start). `public/storage` link is created at web start. |
| Permissions | Apache and the worker/scheduler run as `www-data`; `storage` and `bootstrap/cache` writable by it; the container does not run application code as root. |
| Secrets | No `.env` and no secret in the image (`.dockerignore`, no `ARG`/`ENV` secrets). All configuration through Coolify environment variables, as before. Build needs outbound network only for npm, Composer and Bunny Fonts. |
| Queue worker / scheduler | Same image: `APP_ROLE=worker` and `APP_ROLE=scheduler` (`schedule:work`, exactly **one** instance). Both start only after the timezone gate. Stop/redeploy: SIGTERM reaches PHP directly (`exec setpriv`), so a running job is allowed to finish up to Coolify's stop grace period. |
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

## 5. Staging rollout (requires separate authorization; production untouched)
Preconditions: this PR merged into `staging`; DB snapshot taken (`DEPLOYMENT_CHECKLIST.md` §9); **Auto Deploy remains "Manual deployments only"** — every step below is a deliberate manual deployment.
1. In the Coolify staging application change **Build strategy: Railpack → Dockerfile** (base directory `/`, file `Dockerfile`). Keep every environment variable; do not add `.env` to the image. Expose port **80**; health check `GET /up`. Keep the persistent storage mount on `/var/www/html/storage` (add it if the Railpack resource used another path).
2. **Web** resource: no start command (`APP_ROLE` unset). Add a **post-deployment command** `php artisan migrate --force` (or `RUN_MIGRATIONS=true` on web only), as Railpack used to do implicitly.
3. **Worker** resource (same repository/branch/Dockerfile): `APP_ROLE=worker`, no port/domain. **Scheduler** resource: `APP_ROLE=scheduler`, exactly one instance (`schedule:work` replaces the per-minute cron).
4. Deploy manually, **web first**, then worker, then scheduler. After the first successful deploy run `php artisan queue:restart` once.
5. Verify in **each** container: `docker exec <container> php scripts/check-timezone-runtime.php` → `OK`, `tzdb=2026.6`, `extension[timezonedb]=loaded` (the entrypoint already refuses to start otherwise).
6. Functional smoke: a Casablanca organization, default-dated quotation/invoice around local midnight (`invoice_date` = local date); `GET /up`; a login; a PDF export; an upload > 8 MiB (fulfillment attachment) and a catalog import; confirm the queue worker heartbeat and scheduler heartbeat in System Health.
7. Only after this verification may Auto Deploy be reconsidered. Nothing here is promoted to `main`.

## 6. Rollback (staging)
1. In Coolify, redeploy the **previous deployment** (previous image) for web, worker and scheduler, or switch the build strategy back to Railpack and redeploy manually. The database is untouched by this change.
2. `php artisan queue:restart`, re-run the caches of `DEPLOYMENT_CHECKLIST.md` §5.
3. Expect the old (wrong) tzdb again: until the image fix is redone, Moroccan business dates are an hour off from 2026-09-20 — treat a rollback as temporary and do not use staging for UAT of dates.

## 7. Risks and open points
- The Railpack → Dockerfile switch changes the web server (Apache/mod_php instead of FrankenPHP/Caddy) and the start sequence (no automatic migrations). Validate HTTPS behind Traefik, uploads, large PDF/ZIP exports and request timeouts on staging before UAT; this was only smoke-tested locally.
- Coolify/Traefik limits (request body size, timeouts) are outside the image and may be lower than the PHP limits above.
- The PHP resource limits (`memory_limit=512M`, 40 workers) assume the staging VPS has enough RAM; lower `MaxRequestWorkers` if it does not.
- `PECL timezonedb-2026.6` and the check table must be bumped together at the next legal change.
- Production keeps the old runtime until it receives the same treatment under its own authorization.
- The scheduler runs `schedule:work` (equivalent to a per-minute `schedule:run`); keep exactly one instance.
