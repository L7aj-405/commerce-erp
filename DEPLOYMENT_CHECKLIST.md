# Deployment Checklist — commerce-erp

Prepared during the Pre-Deploy Phase 0 cleanup/audit. No secrets are stored
here. This is a reference for the next phase (Coolify configuration), not a
record of anything already deployed.

## 1. Runtime requirements

- **PHP**: 8.3+ (composer.json requires `^8.3`; verified locally on PHP 8.4.12;
  compatible with the PHP 8.5 target).
- **Required PHP extensions** (from `laravel/framework`, `dompdf/dompdf`,
  `openspout/openspout`, and the app's own `Decimal`/tax-calculation code):
  - `ext-ctype`, `ext-filter`, `ext-hash`, `ext-mbstring`, `ext-openssl`,
    `ext-session`, `ext-tokenizer`
  - `ext-dom`, `ext-fileinfo`, `ext-libxml`, `ext-xmlreader`, `ext-zip`
    (openspout — used by the product import/export feature)
  - `ext-pdo` + **`ext-pdo_mysql`** (MySQL connection — not auto-declared by
    any composer package; verify it is enabled on the PHP image/build pack)
  - **`ext-bcmath`** — used directly by `App\Support\Decimal::divide()` for
    exact tax-exclusive/inclusive price conversions (`bcdiv`/`bcadd`/`bccomp`).
    Not declared in any composer.json; **must be confirmed enabled** or price
    calculations will fatal-error in production.
  - `ext-gd` recommended (not strictly required for this app's PNG-only logo
    embedding via Dompdf's CPDF backend, but improves Dompdf's image-format
    coverage and BMP fallback).
- **Node**: `^20.19.0 || >=22.12.0` (required by `vite` 8 and
  `laravel-vite-plugin` 3). Use Node 22 LTS or newer for the build step.
- **MySQL**: 8.0+ recommended. The schema is safe on modern MySQL (see §5);
  no code path was written for or depends on SQLite in production (SQLite is
  only used by the test suite's in-memory DB, via `phpunit.xml`).

## 2. Build commands

```
composer install --no-dev --prefer-dist --optimize-autoloader

npm ci
npm run build      # requires outbound network access — laravel-vite-plugin
                    # fetches "Instrument Sans" from Bunny Fonts at build time
```

## 3. Laravel runtime commands (deploy step, in order)

```
php artisan storage:link      # see §7 — required, currently NOT linked
php artisan migrate --force   # see §6 for rollback considerations

php artisan config:cache      # verified safe — no closures in any config file
php artisan route:cache       # verified safe — see note below
php artisan view:cache
```

**Route cache note**: `routes/web.php` has one closure-based route
(`GET /`, the guest/redirect home page). This was tested in this audit
(cache generated, routes still resolved correctly via `route:list`, cache
then cleared to leave the repo state untouched) — `route:cache` currently
succeeds without error on this Laravel version. If a future route is added
as a Closure with captured variables from an outer scope, re-verify.

## 4. Environment variables (see `.env.example` for the full annotated list)

- **App**: `APP_NAME`, `APP_ENV=production`, `APP_KEY` (generate with
  `php artisan key:generate`, one time), `APP_DEBUG=false`, `APP_URL` (real
  domain), `APP_LOCALE=fr`.
- **Database**: `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
  `DB_USERNAME`, `DB_PASSWORD` — from the Coolify MySQL service.
- **Session/Cache/Queue**: `SESSION_DRIVER=database`, `CACHE_STORE=database`,
  `QUEUE_CONNECTION=database` (all V1-acceptable, see §9). Set
  `SESSION_SECURE_COOKIE=true` once served over HTTPS.
- **Mail**: `MAIL_MAILER=smtp` (or provider driver), `MAIL_HOST`, `MAIL_PORT`,
  `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` —
  required for the "email invoice/devis/delivery-note" features.
- **Filesystem**: `FILESYSTEM_DISK=local` — fine as long as `storage/app/public`
  is a persistent volume (see §7/§8) and `storage:link` has been run.
- **WooCommerce**: no environment variables — credentials are entered and
  stored per Organization in the database.
- **WhatsApp**: no environment variables — share-link only (`wa.me/<digits>`),
  no API token.
- **Shopify**: not used by this application.
- **PDF/documents**: no environment variables — controlled by
  `config/documents.php` (locale, paper size, `remote_enabled` — keep `false`).

## 5. MySQL / migration audit result

- Searched every migration for the documented anti-pattern — a **nullable
  composite tenant FK** `(organization_id, nullable_fk)` using
  `ON DELETE SET NULL`. **No violations found.** Every nullable composite FK
  in the schema uses `restrictOnDelete()`; `nullOnDelete()` is used only on
  genuine single-column FKs (e.g. `*_by_user_id` actor references, or
  `pos_warehouse_id`), which is safe. Several migrations carry explicit
  in-file comments recording this exact rule and why (e.g.
  `2026_09_16_000001_create_supplier_procurement_tables.php`,
  `2026_09_11_000001_create_quotation_tables.php`).
- No generated/virtual/stored columns, no raw SQL expressions, no
  DB-specific syntax found in any migration.
- `string()->unique()` columns (`users.email`, `permissions.key`) are fine on
  MySQL 8's default `ROW_FORMAT=DYNAMIC` + `innodb_large_prefix=ON` (3072-byte
  index prefix limit); would only be a problem on very old MySQL 5.6/MariaDB
  defaults, which Coolify's MySQL service does not use.
- Recommendation before the real deploy: run the pending migrations once
  against a disposable/staging MySQL database (not the shared dev DB) as a
  final gate — this audit did not execute migrations against MySQL, only
  reasoned from the DDL and from an earlier same-session dry run against a
  disposable SQLite database, which applied cleanly end-to-end.

## 6. Rollback considerations

- `php artisan migrate --force` is additive-only for this project's current
  migration set (no destructive column drops were found in the pending
  migrations reviewed). Standard Laravel migration rollback
  (`php artisan migrate:rollback`) is available but was **not** exercised in
  this audit (destructive commands were out of scope).
- Take a database backup/snapshot through Coolify (or a `mysqldump`) before
  the first production migration run — normal practice, not a project-specific
  requirement.

## 7. Persistent storage (must survive redeploys)

- `storage/app/public/document-profiles/` — organization logo uploads
  (`DocumentProfileController`), served via the `public` disk. **Requires**
  `php artisan storage:link` (confirmed NOT currently linked — `php artisan
  about` reports `public/storage .. NOT LINKED`) and a persistent volume
  mounted at `storage/app/public` (or `storage/app` entirely) in Coolify.
  Losing this directory only affects the *current/draft* org logo — already
  **issued** Invoices/Devis/Delivery Notes embed their logo as a base64 data
  URI captured at issue time (`DocumentSellerProfile`), so historical PDFs
  keep rendering correctly even if the upload is later lost or replaced.
- `storage/logs/` — application log files. Persisting is optional (log
  shipping/rotation is an operational choice, not a functional requirement).
- `storage/framework/{cache,sessions,views}` — must be writable but do **not**
  need to persist across redeploys (regenerate automatically).
- The database itself, obviously.
- Nothing under `public/build` needs to persist — it is regenerated by
  `npm run build` on every deploy (see §8).

## 8. `public/build` strategy

**Build during deployment** (recommended and already the repo's setup):
`.gitignore` excludes `/public/build`, only 4 static files are tracked under
`public/` (`.htaccess`, `favicon.ico`, `index.php`, `robots.txt`). Coolify's
build step must run `npm ci && npm run build` before serving.

## 9. Queue

- `QUEUE_CONNECTION=database` today. The **only** `ShouldQueue` job in the
  codebase is `App\Jobs\SyncWooCommerceProductsJob` (dispatched from
  `WooCommerceIntegrationController` when an organization runs a WooCommerce
  sync). All transactional emails (`InvoiceDocumentMail`,
  `QuotationDocumentMail`, `DeliveryNoteDocumentMail`) are sent
  synchronously (`Mail::send()`, not queued).
- **A queue worker is required in production only if the WooCommerce
  integration is actually used.** Without one, dispatched sync jobs sit in
  the `jobs` table forever and the sync UI will look stuck. Running one
  worker (`php artisan queue:work --tries=3`, supervised) is still
  recommended so this doesn't surprise anyone later.
- `sync` driver was **not** recommended as a substitute — it would make the
  WooCommerce sync run inline on the HTTP request that dispatches it, which
  contradicts why the job exists (a potentially long-running product sync).
- No case was found for Redis; the `redis` queue driver would be over-
  engineering unless a second queue-heavy feature appears.

## 10. Scheduler

**Required.** `routes/console.php` registers:

```
Schedule::command('catalog:cleanup-product-imports')->daily();
```

This deletes expired `ProductImport` staging rows. Coolify needs a cron
entry (or scheduled command/container) running `php artisan schedule:run`
every minute, standard Laravel setup. No other scheduled task exists.

## 11. Cache / session recommendation (V1)

Current drivers (`database` for session, cache and queue) are acceptable for
V1 — no code path assumes Redis, and no Redis client is otherwise required.
Introducing Redis is a future optimization, not a launch blocker. If traffic
later justifies it, move `CACHE_STORE`/`SESSION_DRIVER`/`QUEUE_CONNECTION` to
`redis` one at a time behind a real need.

## 12. Health check

Already present and safe: `GET /up`, registered by Laravel's own
`ApplicationBuilder` (`bootstrap/app.php`'s `->withRouting(health: '/up')`).
Unauthenticated, returns a generic framework page, exposes no environment
variables, credentials, tenant data or stack traces. **No changes needed** —
point Coolify's health check at `/up`.

## 13. Error handling / debug exposure

- `bootstrap/app.php` does not force `APP_DEBUG` — it inherits the `.env`
  value, so setting `APP_DEBUG=false` in Coolify is sufficient; no code
  forces debug mode on.
- `shouldRenderJsonWhen` is scoped to `api/*` and JSON-expecting requests
  only — normal web errors render Laravel's standard (non-debug) error pages
  when `APP_DEBUG=false`.
- No controller/action was found that echoes raw exception messages or SQL
  errors back to the client outside of Laravel's own exception handler.

## 14. Coolify deployment strategy

**Recommended: Nixpacks (buildpack), not a custom Dockerfile.** Reasoning:
- The application is a stock-shape Laravel + Vite project (PHP-FPM/Apache +
  Node build step), nothing in the runtime requires custom system packages
  beyond the PHP extensions listed in §1, all of which are commonly available
  in standard PHP buildpacks/images.
- No `Dockerfile`, `docker-compose*`, `nixpacks.toml` or other deployment
  config currently exists in the repository — there is nothing bespoke to
  preserve.
- A custom Dockerfile only becomes worthwhile if a non-standard system
  dependency shows up later (none was found here).
- Whichever strategy is chosen, it must run the full build sequence from §2
  and the runtime commands from §3, and must supervise a scheduler
  (§10) and, if WooCommerce is used, a queue worker (§9).

## 15. Known non-blockers worth remembering

- `composer.json`'s `post-create-project-cmd` script touches a
  `database/database.sqlite` file and runs `migrate --graceful`; this only
  fires on `composer create-project`, never on `composer install`/`update`,
  so it does not affect the Coolify build/deploy pipeline. Left as-is.
- Dompdf's font cache defaults to its bundled `vendor/dompdf/dompdf/lib/fonts`
  directory; the app only uses the shipped "DejaVu Sans" font (no custom font
  registration), so no runtime write to `vendor/` is expected. If a "cannot
  write font cache" error ever appears, set a custom `fontDir`/`fontCache`
  under `storage/` in `DompdfPdfGenerator`.
