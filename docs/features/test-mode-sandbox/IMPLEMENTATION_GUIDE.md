# Test Mode / Sandbox — Phase 1

## Status and architecture

Phase 1 provides a restricted foundation only. No business table or operation is sandbox-aware yet. Production is represented by a null SandboxContext ID; there is no Production sandbox row.

SandboxContext is container-scoped and resolved after ActiveTenantContext. The session selection includes user, organization, sandbox ID and generation. Each request revalidates membership through the existing tenant resolver, permissions, organization policy and sandbox state. Store changes cannot change sandbox ownership. MCP, queues and other non-browser execution remain Production by default.

ResolveSandboxContext allows only the foundation page, explicit exit and logout while a selection exists. All other GET navigation redirects to the foundation page; other requests return 403 SANDBOX_DOMAIN_UNAVAILABLE. Invalidated selections remain restricted until explicit exit, preventing stale Test Mode submissions from falling through to Production. Later phases must expand this route allowlist only after implementing actual domain isolation.

## Configuration and permissions

Organization settings.sandbox_enabled defaults to false. Existing settings are merged under an organization row lock. sandbox.access and sandbox.switch permit entry; sandbox.manage permits policy changes and is reserved for future management/reset. Reset is not implemented. Owner/Admin defaults follow PermissionProvisioner; custom roles receive nothing automatically.

The organization lock serializes shared sandbox creation. A unique organization index enforces one sandbox in V1; names/status/generation support evolution. Supporting multiple named sandboxes will require deliberately replacing that unique constraint.

## UI and audit

The application header links authorized users to /test-mode. The standalone responsive foundation page shows policy controls in Production and a persistent banner/exit action in Test Mode. No operational navigation is offered. Banner: MODE TEST — Les opérations métier ne sont pas encore disponibles.

AuditLogger records sandbox.created, sandbox.entered, sandbox.left and sandbox.policy_changed. Entry does not activate Test Mode for other sessions. Repeated entry reuses the shared row. Credentials, numbering and business data are untouched.

## Files

Created: app/Models/Sandbox.php; app/Services/Sandbox/SandboxContext.php; app/Providers/SandboxServiceProvider.php; app/Http/Middleware/ResolveSandboxContext.php; app/Http/Controllers/SandboxController.php; resources/js/pages/Sandbox/Index.tsx; tests/Feature/Sandbox/SandboxFoundationTest.php; this guide.

Modified: bootstrap/app.php; bootstrap/providers.php; config/platform.php; resources/js/layouts/ApplicationShell.tsx.

Migration: database/migrations/2026_11_21_000001_create_sandbox_foundation.php. Creates sandboxes with short index names and provisions permissions for existing system roles. Down drops the foundation table; permission catalog entries are retained to avoid destructive role edits. Organization policy uses existing JSON settings, requiring no new organization column.

## Validation

Sandbox integration coverage: Production default; disabled policy; authorization; entry/exit; blocked reads/writes; one shared row; independent users/sessions; changed store; changed organization; policy revocation. Existing TenantContextTest, ActiveSessionTest, RbacAuthorizationTest and SessionIsolationTest passed (19 tests, 87 assertions). TypeScript and production build passed. SQLite in-memory database only; no production migration executed.

## Limitations / next phase

No reset, transactional data, reports, integration or external-client isolation is implemented. The foundation UI is French. Future phases must add domain partitioning, job context propagation, audit attribution and backup/reset contracts before admitting corresponding routes. Do not advertise isolated business operations until those guarantees exist. Existing production processes and background jobs continue normally.

Deploy the additive migration before enabling the organization setting. Test on MySQL before production rollout. Only phase-owned files are committed; unrelated local work is excluded.
