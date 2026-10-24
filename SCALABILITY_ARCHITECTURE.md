# Scalability Architecture — commerce-erp

Status: **mandatory architecture rules** for all ERP work (web, MCP, API,
desktop, jobs, scheduler). Established in Phase AH1 (2026-10-24).

> **No infrastructure migration is required now.** The ERP keeps running on
> one VPS with MySQL and the `database` session/cache/queue drivers. These
> rules exist so that scaling out later is a configuration and infrastructure
> change, not a codebase rewrite.

Related documents: `DEPLOYMENT_CHECKLIST.md` (current runtime/deploy facts),
`PERFORMANCE_TEST_PLAN.md`, `docs/architecture/` (MCP phase designs).

---

## 1. Current and target architecture

### Current (single node)

| Concern | Current implementation |
|---|---|
| Application | One Laravel 13 modular monolith instance on one VPS (Coolify, see `DEPLOYMENT_CHECKLIST.md`) |
| Database | MySQL, single server |
| Session | `SESSION_DRIVER=database` |
| Cache / locks / rate limits | `CACHE_STORE=database` (locks via the `cache_locks` table) |
| Queue | `QUEUE_CONNECTION=database`, one supervised `queue:work` (`retry_after=3900` > longest job timeout 3600) |
| Scheduler | `php artisan schedule:run` every minute (`routes/console.php`) |
| Files | `local` disk (`storage/app/private`), `public` disk (`storage/app/public`, persistent volume), `organization_backups` disk (local by default, S3-compatible via `ORG_BACKUP_STORAGE_DRIVER=s3`) |
| Logs | `storage/logs/laravel.log` (`LOG_STACK=single`) |
| Health | `GET /up` (framework health route, `bootstrap/app.php`) |
| Proxy | `trustProxies(at: TrustedProxyRanges::resolve())`, overridable with `TRUSTED_PROXIES` |

### Future-compatible target (only when measured need exists)

```
                 ┌──────────────── load balancer (health: /up) ────────────────┐
                 │                                                              │
           web node 1 … web node N   (stateless, disposable, same APP_KEY)
                 │
   ┌─────────────┼───────────────────────────┬───────────────────────────┐
   │             │                           │                           │
 MySQL primary   Redis-compatible store      S3-compatible object        centralized logs
 (+ optional     (session / cache / locks /  storage (business assets,   & metrics
  read replicas)  queue) — if/when required   backups)
   │
 worker × N  (independent queue workers)
 scheduler × 1  (singleton, or distributed-safe execution)
 desktop / offline sync layer (later)
```

The strategy is **Modular Monolith + Stateless Horizontal Scaling**.

---

## 2. Stateless application rule

**Invariant: any web node must eventually be disposable.**

A web node must never be the authoritative owner of business state, sessions,
persistent files, queue state, locks or scheduled-work state. Persistent state
goes through shared services (database, cache/lock store, queue, filesystem
disk abstraction).

Never introduce code that depends on:
- a specific hostname, IP or application node;
- a specific local process (e.g. "the worker on this box");
- PHP static / in-memory state surviving between requests;
- a file written by one request being readable by a *later* request, unless it
  is stored on a shared disk or in the database.

Per-request state belongs in container `scoped()` bindings (as
`ActiveTenantContext` and `McpExecutionContext` already are), never in static
properties. All nodes must share `APP_KEY` (sessions, encrypted values, signed
URLs such as MCP document links) and the `ORG_BACKUP_*` keys.

---

## 3. File storage rule

Every filesystem write is one of two kinds.

**A. Persistent business asset** — organization logo, company stamp, stored
backups, future attachments/documents.
- MUST use the Laravel `Storage` abstraction.
- MUST NOT use `storage_path()` / `public_path()` as the permanent location.
- MUST NOT hard-code `disk('local')` / `disk('public')` in domain code when a
  configurable business disk can be used. New asset types read their disk from
  config (pattern: `config('documents.stamp.disk')`,
  `organization_backups` disk).
- Record the disk name next to the path when assets may move between disks
  (pattern: `OrganizationDocumentStamp` stores `storage_disk` + `image_path`).
- Rendering must not fetch assets over HTTP from the app itself; embed bytes
  (pattern: `DocumentSellerProfile::embedLogo`, stamp data URIs).
- Moving `local` → S3-compatible must be possible via configuration only.

**B. Ephemeral / local processing file** — temporary PDF/Excel/CSV/ZIP export,
backup construction workspace, import parsing, PDF temp files.
- May stay node-local (`sys_get_temp_dir()`, `tempnam`, `php://temp`, or a
  `storage/` scratch directory).
- MUST be created and consumed within the same request or the same job run,
  then deleted (e.g. `deleteFileAfterSend(true)`, `@unlink` in `finally`).
- Another node or a later request MUST NOT be expected to read it. If a
  multi-step flow (upload → confirm) needs the file later, it must be persisted
  on a shared disk with an expiry, or the flow redesigned.

Recommended future abstraction (not implemented in AH1): a single
`documents.assets_disk` (or `filesystems.business_assets_disk`) config key used
by logo and stamp storage, defaulting to `public`, so that a move to S3 is one
env change. Do not migrate existing files in AH1.

---

## 4. Session / cache / queue rule

`SESSION_DRIVER=database`, `CACHE_STORE=database` and
`QUEUE_CONNECTION=database` are **valid for the current deployment**. A future
move to Redis must not require business-code changes.

- Business logic MUST NOT assume a specific cache backend (no driver checks, no
  `file`/`array` assumptions, no direct table access to `cache`/`jobs`).
- Locks MUST use `Cache::lock()` / `Cache::add()` (atomic) — never
  `Cache::has()` followed by `Cache::put()` for exclusivity.
- Single-use tokens (preview fingerprints, replay markers) MUST be consumed
  atomically: claim with `Cache::add()` (pattern: `McpPartyPreviews::consume`,
  `McpQuotationDocumentService` claim key), not `get()` + `forget()`.
- The cache store used in production MUST be shared by all nodes (database or
  Redis — never `file` or `array` outside tests), because rate limiters,
  `withoutOverlapping` mutexes, `ShouldBeUnique` locks and MCP previews depend
  on it.
- Queue jobs MUST NOT depend on local process memory.
- Jobs serialize stable identifiers (ids, UUIDs), not large mutable models
  (pattern: every job in `app/Jobs` takes `int`/`string` ids).
- Jobs reload canonical data in `handle()` and re-check state/authorization
  before acting (pattern: `ValidateMcpProductOperationJob` re-resolves the MCP
  connection runtime; backup jobs re-check status).

---

## 5. Queue / job idempotency rule

Assume **more than one worker** and **at-least-once delivery**.

- Any job that may be retried and is externally or financially significant
  (email, stock sync, imports, integrations, backups, documents, finance) MUST
  be safe against duplicate execution.
- Use, in order of preference:
  1. a persistent state machine checked under a row lock
     (pattern: `McpProductOperationProcessor` — item `lockForUpdate`, status
     `pending` → `succeeded/failed` in the same transaction as the mutation);
  2. a database unique key on the operation identity
     (pattern: `org_backup_scheduled_slot_unique`, `client_operation_id`
     uniques);
  3. `ShouldBeUnique` + `uniqueId()` and/or `Cache::lock()` for exclusivity
     (pattern: `SyncWooCommerceProductsJob` + `woocommerce-sync:{id}` lock);
  4. persistent idempotency records for client-initiated mutations
     (pattern: `McpIdempotencyService` / `McpIdempotencyRecord`).
- `retry_after` must stay strictly greater than the longest job `timeout`.
- Jobs dispatched inside transactions rely on `after_commit=true` (configured
  for the database and redis connections); keep it.
- Never assume a job runs on the node that dispatched it.

---

## 6. Scheduler rule

Target deployment: **web × N, worker × N, scheduler × 1** (or an equivalent
distributed-safe execution).

- Every scheduled task MUST be idempotent, or protected with
  `withoutOverlapping()` (mutex in the shared cache store) and, once more than
  one container could run `schedule:run`, `onOneServer()`.
- Scheduled tasks that dispatch work MUST rely on database uniqueness for
  "exactly once per slot" (pattern: `organization-backups:dispatch-due` +
  `scheduled_slot` unique).
- A scheduled task that cleans node-local temp files only cleans the node it
  runs on. Node-local temp files must therefore be removed by the request/job
  that created them; the cleanup command is a safety net, not the mechanism.
- No scheduled task may read node-local files written by web requests.

---

## 7. Database rules

The ERP must stay correct with multiple nodes writing to the same database.

- Multi-step business mutations run in `DB::transaction()`.
- Race-sensitive state transitions lock the row (`lockForUpdate()`), then
  re-check state (pattern: inventory `balances->lock(...)`, invoice family
  lock, quotation issuance).
- Correctness-critical uniqueness is enforced by a database unique index, not
  only by application checks (patterns: composite `(organization_id, …)`
  uniques, `client_operation_id` uniques, sequence/version uniques).
- **No `SELECT MAX(id)+1` numbering for official documents.** Official numbers
  come from dedicated counter rows created with `insertOrIgnore` and locked
  `FOR UPDATE` inside the issuing transaction (pattern:
  `QuotationNumberGenerator`, `InvoiceNumberGenerator`, the `*_sequences`
  tables). `max()+1` is acceptable only for per-parent child ordinals
  (line positions, revision numbers, addendum sequences) computed under the
  parent's lock and backed by a unique index.
- Keep transactions short: no HTTP calls, mail sending, PDF rendering of
  unrelated documents, or file uploads inside a row-locking transaction unless
  the existing design requires it and documents why.
- Deadlocks: where a flow already retries (`DB::transaction($cb, $attempts)`),
  keep it; lock rows in a consistent order (e.g. by id) in new multi-row code.
- Index tenant access paths: `(organization_id[, store_id], status, date)` and
  the search columns used by list pages.

---

## 8. Read replica readiness (future only)

Do not implement read/write splitting now. Future topology:
- **Primary**: all writes and consistency-sensitive reads.
- **Replicas**: reports, analytics, non-critical heavy reads.

Rules for code written today:
- Never assume a replica is consistent immediately after a write.
- Write-then-read flows needing fresh data stay on the primary (Laravel
  `sticky` connections when introduced).
- Financial, stock and authorization decisions never read from a replica.
- Report/analytics services should be isolated classes so they can later be
  pointed at a read connection without touching mutation code.

---

## 9. Tenant isolation rule (mandatory)

- Every organization-owned resource is scoped from **trusted server-side
  context**, never from a client-supplied `organization_id`.
- Canonical mechanisms:
  - Web: `ResolveTenantContext` middleware → `ActiveTenantContext` (scoped),
    which validates active membership, active organization and store
    membership on every request; `ScopesToActiveOrganization` /
    `ScopesToActiveStore` model concerns; policies comparing the resource's
    `organization_id`/`store_id` with the context.
  - MCP: `AuthenticateMcpConnection` → `McpExecutionContext` (scoped):
    connection → organization → user membership → MCP scope + ERP permission
    (`config('mcp.scopes')`); `McpSalesStoreAccess` for store scope.
  - Database: composite tenant foreign keys `(organization_id, fk)`.
- Verify membership, store scope (where applicable) and the existing ERP
  permission. MCP, API and desktop reuse the same policies/permissions.
- Cache keys, locks and idempotency scopes include the organization, store
  and/or connection identity where applicable (pattern:
  `mcp:idempotency:{hash(connection|tool|key)}`, `system-health:v1:{org}:…`),
  or are bound to a random token whose stored payload is re-checked against
  the organization and connection.

---

## 10. Pagination / query growth rule

- No unbounded `get()` / `all()` on potentially large tenant datasets
  (products, variants, customers, contacts, orders, invoices, payments,
  movements, audit logs, notifications).
- Lists and searches paginate and filter; autocomplete endpoints `limit()`.
- Eager-load intentionally (`with([... 'relation:id,col'])`); avoid N+1.
- Detail pages with potentially unbounded children (payments, revisions,
  movements, deliveries, audit trails) need explicit limits or pagination once
  they can grow large.
- Large exports stream (`cursor()`, `lazyById()`, OpenSpout writers) and
  should eventually move to queued jobs that write to a shared disk.
- Do not over-optimize small bounded lookup tables (roles, tax rates, units,
  warehouses, stores).

---

## 11. Time / timezone rule

- Storage timestamps stay canonical **UTC** (`config('app.timezone') = 'UTC'`).
- Business concepts — *today, tomorrow, overdue, due date, reminder, business
  day, validity date* — use the **explicit organization timezone**. Never
  infer a timezone (browser, IP, server, language, country).
- **Implemented (TIME-1):** the organization business timezone is an IANA
  identifier stored in `organizations.settings['timezone']`, set from
  Settings → Company (`UpdateOrganizationTimezoneAction`, permission
  `settings.update`, audited as `organization.settings_changed`). It resolves
  to **UTC until explicitly configured**.
  - The only resolver: `Organization::businessTimezone()` (and
    `hasConfiguredTimezone()`). Never write `$org->settings['timezone'] ?? 'UTC'`.
  - Validation: `App\Support\BusinessTime::rules()` (`timezone:all`).
  - Helpers: `BusinessTime::now()`, `today()`, `isBeforeToday()`,
    `localToUtc()`, `toLocal()`.
  - Persist: organization-local input → `localToUtc()` → store UTC. Display:
    stored UTC → `toLocal()`. DATE columns stay calendar dates and are
    compared as `Y-m-d` against `BusinessTime::today()`, never shifted.
  - Quotation validity (`Quotation::isPastValidity()`, issue check, list
    validity filter) already uses it. Other `now()`/`today()` business-date
    code still uses UTC and moves over feature by feature.
- **Transport contract (Work Center / follow-ups, MCP, API):** accept either
  (A) `due_date` `YYYY-MM-DD` with optional `due_time` `HH:MM`, interpreted
  in the organization timezone, or (B) an ISO 8601 datetime **with an explicit
  offset**. Reject bare datetimes without an offset. Clients interpret
  "Monday / tomorrow / in three days" themselves and send normalized values.
  The business timezone always comes from the server-resolved organization; a
  client offset only describes that input instant and never overrides it.
- The timezone is not cached; if it ever is, the key must include the
  organization id and be invalidated by the update action.

---

## 12. API / MCP / desktop business logic rule

Web UI, MCP, future public/private API, desktop and mobile apps all call the
**same canonical Actions/Services** (`app/Actions/**`, domain services such as
`CustomerManager`, `QuotationNumberGenerator`).

- Transports (controllers, MCP tools, API/desktop endpoints) handle only
  validation, authorization/context and request/response translation.
- Shared validation rules live with the canonical action (pattern:
  `CustomerManager::rules()`, `SaveOrganizationContactAction::rules()`).
- Never duplicate business rules in controllers, MCP tools, desktop endpoints
  or frontend code. Canonical audit events are emitted by the action;
  transports do not emit duplicates.

---

## 13. Offline-desktop readiness (design constraints only)

Offline sync is **not** built now, and these columns are **not** added to all
tables now. New designs must not make offline sync impossible:

- client-generated operation IDs (UUID) for client-initiated creates
  (existing precedent: `client_operation_id` on sales orders, payments,
  returns, refunds, POS addenda, with `(organization_id, …)` unique keys);
- UUID/ULID-friendly public identifiers where records may be created offline;
- persistent idempotency so a retried offline mutation never duplicates an
  order, quotation, payment or task;
- record versions / optimistic concurrency (precedent: MCP version tokens) and
  explicit conflict detection;
- device identification and per-device sync cursors/checkpoints;
- the server remains the canonical authority (numbering, stock, finance).

Work Center follow-ups (W1, `follow_ups`) already carry an integer `version`
(optimistic concurrency, `expected_version` on every write/transition). Before
offline/desktop creation they will still need a `client_operation_id` (UUID,
unique per organization) for idempotent creates, a device id, and a sync
cursor (e.g. `updated_at` + id). Not added in W1.

---

## 14. Observability rule (future scale requirements)

Needed before running multiple nodes in production; nothing is installed in AH1:
- structured, centralized logs (stderr/JSON or a log shipper — not only
  per-node `storage/logs`);
- request latency p50/p95/p99 and HTTP 5xx rate;
- DB slow queries, lock waits and deadlocks;
- queue depth, oldest queued job age, failed jobs, worker restarts;
- CPU / RAM / disk per node; object-storage errors;
- backup success/failure (already surfaced by System Health).

Existing building blocks: `SystemHealthService`, scheduler and queue worker
heartbeats (`SystemHealthHeartbeat`), `SystemHealthNotificationMonitor`.

---

## 15. Health / deployment rule

- `GET /up` is the load-balancer health endpoint (unauthenticated, no tenant
  data).
- Startup and runtime must not require node-local persistent business data.
- A deployment must be able to: start a new node → pass `/up` → receive
  traffic → terminate an old node, with no business-data migration.
- Build artifacts (`public/build`) are produced at build time on every node.

---

## 16. No premature complexity

Prohibited without a **measured** requirement and an explicit architecture
decision: microservices, database sharding, multi-primary databases,
Kubernetes, Redis Cluster, event sourcing, distributed message buses.

---

## 17. Known gaps register (AH1 audit, 2026-10-24)

Required-before: `NOW` · `2 APP NODES` · `LARGE SCALE` · `DESKTOP OFFLINE` · `LATER`.

| # | Finding | Where | Required before | Priority |
|---|---|---|---|---|
| 1 | MCP protocol sessions use `FileSessionStore`/`FileSessionLock` under `storage/framework/mcp-sessions/{connection}`; a request routed to another node loses the session. The SDK ships `Psr16SessionStore` (Laravel cache is PSR-16). | `app/Services/Mcp/McpServerFactory.php` | 2 APP NODES | P0 |
| 2 | Backup restore is two-step: validate stages `validated/{token}.erpbackup` on local disk, restore reads it in a later request. | `OrganizationBackupValidator`, `OrganizationBackupController::restore`, `OrganizationBackupCloudController` | 2 APP NODES | P1 |
| 3 | Stored backups default to the local `organization_backups` disk; worker-created backups are read by web requests (download/restore/cloud sync). Config switch to S3 already exists. | `config/filesystems.php`, `OrganizationBackupStorage` | 2 APP NODES (config) | P1 |
| 4 | Pre-restore safety copies are kept 30 days on node-local `storage/app/private/organization-backups/pre-restore`. | `OrganizationBackupRestorer` | 2 APP NODES | P1 |
| 5 | Organization logo hard-codes `Storage::disk('public')`; stamp disk is configurable but whitelisted to `public`/`local` only. | `DocumentProfileController`, `DocumentSellerProfile::embedLogo`, `DocumentStampStorage::ALLOWED_DISKS` | 2 APP NODES (shared volume) / S3 move | P1 |
| 6 | `organization-backups:cleanup-temporary-files` cleans node-local directories — only the node running the scheduler. | `routes/console.php` | 2 APP NODES | P2 |
| 7 | No scheduled task uses `onOneServer()`; safe only while exactly one container runs `schedule:run`. | `routes/console.php` | 2 APP NODES | P1 |
| 8 | Logs written to per-node `storage/logs/laravel.log`. | `config/logging.php`, `LOG_STACK=single` | 2 APP NODES | P2 |
| 9 | System Health disk metrics read the local node's `storage_path()`. | `SystemHealthService` | 2 APP NODES | P2 |
| 10 | Some MCP previews are consumed with `Cache::get()` + `forget()` (not atomic); TOTP replay uses `has()` + `put()`. Downstream row locks / canonical state checks limit impact. | `McpProductChangeService`, `McpQuotationDraftService`, `McpQuotationLifecycleService`, `McpSalesOrderService`, `TotpService` | LARGE SCALE | P2 |
| 11 | Official number generators scan every allocated number of the organization (`pluck()->max()`) while holding the sequence lock. | `QuotationNumberGenerator`, `InvoiceNumberGenerator` | LARGE SCALE | P2 |
| 12 | Heavy exports (Finance ZIP packages, Excel) are built synchronously in the HTTP request in node-local temp. | `app/Services/Finance/Export/*` | LARGE SCALE | P2 |
| 13 | Document emails are sent synchronously in the request (no queue); MCP has a claim key, web relies on user behaviour. | `SendQuotationEmailAction`, `SendInvoiceEmailAction`, `SendDeliveryNoteEmailAction` | LARGE SCALE | P2 |
| 14 | Personal-cloud backup upload retry after a partial upload may create a duplicate remote file. | `SyncOrganizationBackupToPersonalCloudJob` | LATER | P2 |
| 15 | ~~No organization timezone~~ — **resolved in TIME-1** (see §11). Remaining: other business-date defaults (`quotation_date`, sale/payment/invoice dates, Finance "today"/month, commission periods, dashboard "today") still use UTC `now()`. | see §11 | LATER (per feature) | P2 |
| 16 | Client operation ids exist only for POS/sales/payments/returns; quotations, customers, contacts have none; no device id / sync cursor. | migrations | DESKTOP OFFLINE | P2 |
