# Deployment Checklist — commerce-erp (closed pilot, single server)

Updated in PILOT-1.1 from the PILOT-1 production-readiness audit. No secrets
are stored here. Target: **one app server** on Coolify (web + one queue
worker + one scheduler) with a managed MySQL service. Architecture rules and
the known-gaps register live in `SCALABILITY_ARCHITECTURE.md`.

> **The application does not use Redis.** Session, cache, locks, rate limits
> and the queue all run on MySQL (`database` drivers). Do not add Redis for
> the pilot.

---

## 1. Runtime requirements

- **PHP 8.4.1+** (`composer.json`), with extensions `ctype`, `filter`, `hash`,
  `mbstring`, `openssl`, `session`, `tokenizer`, `dom`, `fileinfo`, `libxml`,
  `xmlreader`, `zip` (openspout), **`pdo_mysql`**, **`bcmath`** (used directly by
  `App\Support\Decimal` — prices fatal-error without it), `pcntl` (queue job
  timeouts); `gd` recommended (Dompdf image coverage).
- **Node** `^20.19.0 || >=22.12.0` for the build step (Node 22 LTS).
- **MySQL 8.0+** (verified on 8.4 in strict mode: all migrations from an empty
  database in ~70 s; backup → validate → restore verified on MySQL).
  - charset `utf8mb4`, collation `utf8mb4_unicode_ci` (config defaults);
  - **server time zone UTC** (`SELECT @@global.time_zone, @@system_time_zone`).
    The application also pins every session to `+00:00`
    (`config/database.php`), so a non-UTC server can no longer shift or
    reject timestamps — keep the server in UTC anyway.
- Coolify's Traefik must be the only internet-facing hop (see §13).

## 2. Pre-deploy gate (all must be true)

- [ ] Release built from an **exact commit/tag** (e.g. `pilot-candidate-1`),
      never from a working copy. `git status` clean.
- [ ] MySQL service in **UTC**, `DB_CONNECTION=mysql` set explicitly.
- [ ] **Off-host MySQL backup** configured and one backup verified (§9).
- [ ] **Persistent volume mounted at `/app/storage/app`** (§8).
- [ ] All environment variables of §3 set; no `.env` file baked into the image.
- [ ] **Platform SMTP** works (§6) — required for email verification of every
      account, invitations and password resets. (The bootstrap owner of §7 is
      created pre-verified with a one-time setup link, but needs SMTP for any
      later password reset.)
- [ ] `ORG_BACKUP_SIGNING_KEY` and `ORG_BACKUP_ENCRYPTION_KEY` generated and
      **stored outside the server** (secret manager / password vault).
- [ ] `composer audit` and `npm audit --omit=dev` report no high/critical
      advisories (both clean at PILOT-1).

## 3. Production environment reference (no real values)

Only variables the code actually reads. `.env.example` documents local
defaults; in Coolify set these as environment variables.

```dotenv
# --- Application
APP_NAME="10xScale ERP"
APP_ENV=production
APP_DEBUG=false
APP_KEY=                       # php artisan key:generate --show, once; never rotate casually
APP_URL=https://erp.example.com
APP_LOCALE=fr                  # config default is "en"
# APP_LEGAL_NAME= / APP_LEGAL_CONTACT_EMAIL=   (public legal pages)
# APP_VERSION= / APP_COMMIT_SHA=               (shown on System Health)
PUBLIC_REGISTRATION_ENABLED=false              # closed pilot (also the production default)

# --- Database (Coolify MySQL service)
DB_CONNECTION=mysql
DB_HOST=
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
# MYSQL_ATTR_SSL_CA=           (only if the MySQL connection uses TLS)

# --- Session / cache / queue (database drivers — no Redis)
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=3900

# --- Logs
LOG_CHANNEL=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=14

# --- Platform mail (account emails — mandatory, see §6)
MAIL_MAILER=smtp
MAIL_SCHEME=                   # smtps for implicit TLS (465); empty for STARTTLS (587)
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="${APP_NAME}"

# --- Organization backups (see §9)
ORG_BACKUP_SIGNING_KEY=        # ≥ 32 random bytes, base64:... accepted
ORG_BACKUP_ENCRYPTION_KEY=     # a DIFFERENT ≥ 32 random bytes
ORG_BACKUP_KEY_ID=primary
ORG_BACKUP_ALLOW_LEGACY_UNSIGNED_RESTORE=false
# Optional off-host storage for stored backups instead of the local volume:
# ORG_BACKUP_STORAGE_DRIVER=s3
# ORG_BACKUP_S3_BUCKET= / ORG_BACKUP_S3_ENDPOINT= / ORG_BACKUP_S3_REGION=
# ORG_BACKUP_S3_ACCESS_KEY_ID= / ORG_BACKUP_S3_SECRET_ACCESS_KEY=

# --- MCP (only if AI assistants connect)
MCP_ALLOWED_HOSTS=             # the APP_URL host is always allowed; add other public hostnames, never *
MCP_ALLOWED_ORIGINS=           # browser-based MCP clients only; empty otherwise

# --- Optional
# TRUSTED_PROXIES=             # defaults to private ranges (Coolify/Traefik)
# GOOGLE_AUTH_CLIENT_ID= / GOOGLE_AUTH_CLIENT_SECRET= / GOOGLE_AUTH_REDIRECT_URI=
# GOOGLE_DRIVE_CLIENT_ID= / GOOGLE_DRIVE_CLIENT_SECRET= / GOOGLE_DRIVE_REDIRECT_URI=
```

Key rules:
- `APP_KEY` ≠ `ORG_BACKUP_SIGNING_KEY` ≠ `ORG_BACKUP_ENCRYPTION_KEY`. Losing
  or changing a backup key makes existing archives unverifiable/unreadable.
- `DB_CONNECTION` missing now targets MySQL and fails loudly (no silent SQLite).
- WooCommerce credentials and per-organization document SMTP are stored in
  the database (configured in the app), not in env.

## 4. Build

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build        # needs outbound network (Bunny Fonts) at build time
```

`public/build` is generated on every build (git-ignored). Nixpacks is fine;
no custom Dockerfile is required.

## 5. Deploy (in this order)

```bash
php artisan storage:link        # idempotent; links public/storage → storage/app/public
php artisan migrate --force     # take the DB snapshot of §9 first
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart       # workers pick up the new code after their current job
```

> **NEVER seed production.** Do not run `php artisan db:seed` or
> `php artisan migrate --seed` (nor `migrate:fresh`). `DatabaseSeeder` creates
> a test account `admin@test.com` with a weak password. Required system data
> (permission catalogue, roles) is provisioned by the migrations and by
> organization creation.

Processes (Coolify, supervised):

| Process | Command | Count |
|---|---|---|
| Web | the Nixpacks PHP web server | 1 (pilot) |
| Queue worker | `php artisan queue:work --tries=3 --timeout=3600` | 1, required |
| Scheduler | `php artisan schedule:run` every minute (cron) | **exactly 1** |

Queue facts: `retry_after=3900` must stay strictly greater than the longest
job timeout (3600 s, WooCommerce sync). The worker is required for scheduled
organization backups, Google Drive backup copies, WooCommerce sync, MCP
product operations and the worker heartbeat. Failed jobs:

```bash
php artisan queue:failed            # list
php artisan queue:retry <id>        # retry one (all heavy jobs are idempotent / unique)
php artisan queue:forget <id>       # drop a permanently invalid one after review
```

Scheduled tasks (`routes/console.php`, all idempotent and/or `withoutOverlapping`):

| Every | Task |
|---|---|
| minute | due organization backups (`organization-backups:dispatch-due`), scheduler + queue heartbeats |
| 5 min | follow-up reminders (`follow-ups:notify-due`), recurring follow-ups (`follow-ups:generate-recurring`), system-health transitions |
| 15 min | Attention scan (`attention:scan`: detection, baseline, ownership, notifications, escalation) |
| hourly | backup temporary-file cleanup |
| daily | product-import cleanup, trusted-2FA-device cleanup, notification retention, recurring Finance obligations (00:10), MCP operation (03:20) and OAuth (03:35) pruning |

Run **one** scheduler only. Before a second app server exists, the
scheduled tasks need `onOneServer()` (known gap #7).

## 6. Mail

- **Platform SMTP (`MAIL_*`) is mandatory**: email verification (required for
  every user), password reset, invitations and security notices. Test it
  before onboarding users; never use `MAIL_MAILER=log` in production.
- **Document email** (Devis, Invoices, Delivery Notes) never uses the platform
  mailer: each organization enters its own SMTP in the app (30 s timeout,
  public-host guard). Without it, document emailing is refused.
- Document emails are sent synchronously in the request (known gap #13).

## 7. Registration policy and first owner

- `PUBLIC_REGISTRATION_ENABLED` defaults to **off in production**: `/register`
  answers 404 and Google sign-in never creates an account for an unknown
  email. Login, password reset, email verification, invitations and Google
  sign-in for existing accounts work normally.
- First owner — operator command on the app container (no SQL, no password
  ever chosen or printed):

  ```bash
  php artisan erp:bootstrap-owner owner@company.ma \
      --name="Owner Name" --organization="Company SARL" --timezone=Africa/Casablanca
  ```

  It creates a new verified user (fails if the email already exists — it
  never modifies an existing account), the organization through the normal
  creator (Owner role with all permissions, membership, privileged-role 2FA
  policy, audit), sets the business timezone, and prints a **one-time
  password-setup link** valid 60 minutes. Hand it to the owner privately; if
  it expires, the owner uses "Mot de passe oublié" (needs platform SMTP).
- At first login the owner must enrol **2FA** (mandatory for owner/admin roles
  in new production organizations), then creates stores, users (invitations),
  organization SMTP, document profile/stamp in the app.

## 8. Persistent storage

Mount **one persistent volume at `/app/storage/app`** (not only
`/app/storage/app/public`). It holds:

| Path | Content | Must persist |
|---|---|---|
| `storage/app/public/document-profiles`, `organization-stamps` | logos, company stamps | yes |
| `storage/app/private/organization-backups/*.erpbackup` (stored backups) | organization backups (local driver) | **yes** |
| `storage/app/private/organization-backups/pre-restore` | automatic safety copy before every restore (30 days) | **yes** |
| `storage/app/private/organization-backups/{tmp,validated,downloads,history,cloud-sync,cloud-restore}` | short-lived staging, cleaned hourly | harmless either way |

Not persisted (regenerated): `storage/framework/{cache,sessions,views}`,
`storage/framework/mcp-sessions` (see §12), `public/build`. `storage/logs`:
optional volume (keep it if you want logs across redeploys). Issued PDFs embed
their logo/stamp bytes, so historical documents never depend on the files.

## 9. Backups

Two independent layers — **organization backups do not replace database backups.**

1. **Database (infrastructure) — required before real data.**
   - Enable Coolify scheduled backups of the MySQL service to **off-host
     object storage** (S3-compatible), daily at minimum.
   - Decide and record the retention (recommendation: 14 daily + 8 weekly).
   - Verify one backup completes and can be downloaded; restore = Coolify
     "restore backup" into the MySQL service (or `mysql < dump` into a fresh
     database), then redeploy the same release.
   - Take a manual snapshot immediately before every `migrate --force`.
2. **Organization backups (application).** Per-organization, signed
   (HMAC-SHA256) and encrypted (AES-256) logical exports, scheduled per
   organization in Settings → Sauvegardes and run by the queue worker.
   Stored on the `/app/storage/app` volume (or S3 via
   `ORG_BACKUP_STORAGE_DRIVER=s3`); optional Google Drive copy per user.
   Restore validates signature and schema, isolates the organization and keeps
   a pre-restore safety copy. Requires the same backup keys that created the
   archive — keep them off the server.

## 10. Attention rollout (W4.5.1)

- **Fresh pilot organization (current assumption): safe.** Every domain
  starts empty; the first complete scan establishes the Sales Order
  receivables baseline (historical receivables visible, never notified or
  escalated). Quotations and orders cannot be bulk-imported.
- **Upgrading a database with historical quotations/invoices:** only Sales
  Order receivables have a baseline. The first `attention:scan` would alert
  owners of every expired undecided quotation and escalate after 2 days. Add
  `quotation` / `invoice` to `AttentionBaselines::SUBJECTS` before such a
  rollout. Not needed for the pilot.
- After the pilot organization is set up: run `php artisan attention:scan`
  once and check Travail → Attention (no notification storm).

## 11. Health and post-deploy verification

- `GET /up` = the application boots (Coolify health check). It does **not**
  prove MySQL, queue or scheduler readiness.
- After every deploy, as an owner with `system.health.view`, open **Santé du
  système** and check: database OK, scheduler heartbeat < 3 min, queue
  heartbeat < 3 min, backup keys configured, disk below 80 %.
- Platform/system-health notifications are not sent to tenant users during
  the pilot (`NOTIFICATIONS_PLATFORM_TO_TENANTS=false`): the operator must
  watch Coolify/host monitoring.

Post-deploy smoke flow (in a throwaway organization, test mailer only):
customer → devis → issue → PDF → accept → convert → confirm → payments →
invoice → follow-up reminder → attention scan → backup → validate. Delete the
throwaway organization afterwards.

## 12. MCP (only if AI assistants connect)

- Enable per organization (Settings → IA / MCP), create connections with the
  minimum scopes; OAuth requires HTTPS.
- **Single-server limitation:** MCP protocol sessions are local files under
  `storage/framework/mcp-sessions`. A redeploy drops them; clients simply
  re-initialize (tool data, idempotency records and OAuth tokens are in the
  database). Before a second app server, move them to a shared store (known
  gap #1).

## 13. Reverse proxy, HTTPS, cookies

- The app container is never published directly; Coolify's Traefik is the
  internet edge and must overwrite `X-Forwarded-*` headers. Private-range
  proxies are trusted by default (`TrustedProxyRanges`); set `TRUSTED_PROXIES`
  to Traefik's exact address if available. Login throttling keys on the real
  client IP derived from this.
- HTTPS only; `SESSION_SECURE_COOKIE=true`; `APP_URL` must be the https URL
  (OAuth callbacks, signed links, MCP metadata).

## 14. Rollback

- **Application:** redeploy the previous image/commit, then
  `php artisan queue:restart` and re-run the caches of §5.
- **Database:** restore the pre-migrate snapshot if a migration fails or the
  release must be abandoned. Do **not** run `migrate:rollback` casually in
  production: the W4.4/W4.5 due-date backfills are forward-only data changes.
- **One organization's data:** restore its organization backup (a safety copy
  is taken automatically first).

## 15. First pilot deployment sequence

1. Tag the release candidate; build from the tag (§4).
2. MySQL in UTC with off-host scheduled backups; volume `/app/storage/app`.
3. Set §3 variables; store `APP_KEY` and both backup keys in the vault.
4. Deploy (§5) — snapshot before `migrate --force`, never seed.
5. Start web, one worker, one scheduler; health check `/up`.
6. Verify §11 (System Health) and platform SMTP with a real mailbox you own.
7. Smoke flow in a throwaway organization, then delete it.
8. `php artisan erp:bootstrap-owner …` for the pilot owner (§7).
9. Owner: 2FA, timezone check, stores, organization SMTP, document profile,
   invitations, product/stock import, customers.
10. `php artisan attention:scan` once; verify Attention (§10).
11. Enable the scheduled organization backup; confirm one completes.
12. Enable MCP only if the pilot needs it (§12).

## 16. Pilot monitoring (minimum)

Coolify/host: `/up` uptime, CPU/RAM, **disk** (backups, audit table, logs
grow), MySQL availability and size, backup success. ERP: System Health page,
`php artisan queue:failed`, `storage/logs/laravel-*.log` warnings/errors.
Centralized logging is not required for the pilot.

## 17. Release hygiene

Export source with `scripts/export-source.ps1 -OutputPath <outside-repo>.zip`
(uses `git archive`; refuses tracked secrets). Never package the working
directory recursively (`.env`, logs, uploads, backups, `vendor`).
