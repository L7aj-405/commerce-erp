# AGENTS.md — commerce-erp

Instructions for coding agents working in this repository.

## Scalability & Distributed Runtime Guardrails (mandatory)

The ERP runs on one VPS today, but all code must stay compatible with future
horizontal scaling (web × N, worker × N, scheduler × 1). Full rules, current
architecture and the known-gaps register: **`SCALABILITY_ARCHITECTURE.md`**.

**Read `SCALABILITY_ARCHITECTURE.md` before changing:** storage/uploads,
queues/jobs, the scheduler, database-heavy code, tenancy/authorization,
API/MCP, desktop sync, notifications/follow-ups, imports/exports.

Non-negotiable rules:

1. **Stateless web nodes** — no business state, sessions, persistent files,
   locks or scheduled-work state owned by one node; no static/in-memory state
   between requests; no hostname/node assumptions.
2. **Storage abstraction** — persistent business assets go through `Storage`
   on a configurable disk, never `storage_path()` as the permanent location;
   temp files are created, used and deleted within one request or job.
3. **Tenant safety** — tenancy comes from trusted server context
   (`ActiveTenantContext`, `McpExecutionContext`), never a client
   `organization_id`; verify membership, store scope and ERP permission;
   include org/store/connection in cache, lock and idempotency keys.
4. **Transactions & locking** — multi-step mutations in transactions,
   `lockForUpdate()` for race-sensitive transitions, DB unique indexes for
   correctness, official numbering only via locked sequence rows (never
   `MAX(id)+1`).
5. **Idempotent, retry-safe jobs** — assume many workers and at-least-once
   delivery; jobs carry ids, reload canonical data, and guard side effects
   with state checks, unique keys, locks or persistent idempotency.
   Scheduled tasks are idempotent or `withoutOverlapping()`.
6. **Pagination for growing datasets** — no unbounded `get()`/`all()` on
   tenant data; paginate, limit, eager-load deliberately, stream exports.
7. **No transport-specific business logic** — web, MCP, API, desktop call the
   same canonical Actions/Services; transports only validate, authorize and
   translate.
8. **UTC storage, explicit business timezone** — store UTC; "today/overdue/
   due" use an explicit organization timezone, never a guessed one.
9. **Offline-sync-friendly design** — client operation ids, persistent
   idempotency and version/conflict checks for client-initiated creates; the
   server stays canonical.
10. **No premature distributed architecture** — no microservices, sharding,
    multi-primary, Kubernetes, Redis Cluster, event sourcing or message buses
    without a measured requirement.
