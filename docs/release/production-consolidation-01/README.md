# Release candidate — production-consolidation-01

Status: **awaiting production release approval. Not merged, not deployed, no production or staging data touched.**

| | |
|---|---|
| Branch | `release/production-consolidation-01` (cut from main) |
| Base (main) | `5285c6caf659bfca1fae5da2900f7d5f9ee27e3f` |
| Integrated (staging) | `fe426830155a6af224f906c7689f45e045fe0be8` |
| Migrations | 15 new — [MIGRATIONS.md](MIGRATIONS.md) |
| Deployment, Coolify, verification, rollback | [RUNBOOK.md](RUNBOOK.md) |
| Read-only checks | `scripts/release/preflight.mysql`, `postflight.mysql`, `integrity-snapshot.mysql` |

## 1. Git reconciliation

- Merge base of main and staging: `22a3e01` (the F-01 commit). Staging was 161 commits ahead, main 5.
- Main's 5 commits: merge of PR #22 (`00bd3a0`, tree identical to staging commit `eeb5f6a`), merge of PR #24
  (`1483554`), the revert `2fd70e5`, the Pint follow-up `24b07e7`, merge of PR #25 (`5285c6c`).
  **No production-only fix exists**: main's only own content is the revert and formatting of three restored files.
- A plain staging → main merge would have silently dropped Step 1 (CI, architecture guards) and F-01.

Commits on the release branch:

| Commit | Content |
|---|---|
| `a1267ce` | Revert of the Pint follow-up `24b07e7` |
| `2328be6` | Revert of the revert `2fd70e5`: restores PR #24 exactly once. Tree `be81dad5…` equals both `1483554` and `22a3e01` |
| `2c3fcd9` | Merge of staging, no conflicts. Tree `8f81bd86…` is **identical** to staging's tree |
| `41a1e5a` | Security patch of `composer.lock` |
| (next) | These documents and the three query scripts |

Main and staging were neither reset nor force-pushed.

## 2. Every difference from staging

| Path | Reason |
|---|---|
| `composer.lock` | `symfony/http-foundation`, `http-kernel`, `routing` 8.1.11 → 8.1.12, `mailer` 8.1.7 → 8.1.12. Five advisories dated 2026-11-25 (CVE-2026-133743, -133746, -133751, -133754, -133755) fail the blocking audit gate. Staging has the same vulnerability: backport it |
| `docs/release/production-consolidation-01/*` | Release documents (excluded from the image) |
| `scripts/release/*.mysql` | Read-only query scripts (`.mysql` because tracked `*.sql` files are forbidden) |

No application, migration, configuration or infrastructure file differs from staging.

## 3. Required safeguards present

Guaranteed by tree identity with staging: architecture guards (`tests/Unit/Architecture`), tenant-scoping and
financial-precision scans, Customer Balance F-01 (`CustomerBalanceNoCrossOrderNettingTest`), MySQL and concurrency jobs.

## 4. Validation

CI results for this branch are on the pull request; **CI gate must be green before approval**.

| Check (local, Windows, PHP 8.4.12, tzdb 2026.6) | Result |
|---|---|
| TypeScript `tsc --noEmit` | pass |
| Frontend tests | 73 / 73 pass |
| Vite production build | pass (chunk-size warning) |
| Pint on all 332 PHP files changed since main | pass |
| `composer validate`, `composer audit --locked` (after patch) | valid, no advisory |
| `npm audit --omit=dev` | 0 vulnerabilities |
| Docker image build (before the lock patch) | pass |
| Full PHP suite (includes architecture guards) | **not completed locally** — stopped, machine low on memory |
| MySQL 8.4 critical suites | **not completed locally** — same reason |
| MySQL concurrency races | **not run locally** |

Reference only: all blocking CI jobs passed on staging `fe42683` (same code, previous lockfile); the advisory
whole-repository Pint baseline failed there, as designed non-blocking.

### Upgrade rehearsal (disposable MySQL 8.4.3, data created by **main** code, synthetic and small)

| Step | Result |
|---|---|
| Preflight on main schema | runs read-only, no `STOP` |
| 15 migrations | success, about 27 s |
| Checksums of all 153 compared tables, business totals | identical; only 82 permission / grant lines added and migrations 132 → 147 |
| Postflight | all `PASS`; 19/19 constraints; 0 rows without a party; 0 cross-tenant links |
| `finance:reconcile`, 2 organizations | no variance (R-2 skipped: no opening position) |
| Backfill re-run | no-op |
| Violating stock row | preflight `STOP`; migration 3 refused, no constraint added, migrations 1–2 applied |
| Pre-existing constraint name | migration 3 failed half-way and left constraints behind (documented) |
| Restore of the pre-migration dump | snapshot identical; second migration succeeded |
| Main code on upgraded schema | wrote customer, supplier, order, payment; backfill re-run linked the new rows |

### Container rehearsal (disposable volumes)

`/up` 200 and `/login` 200; runtime extensions and tzdb 2026.6; database cache/queue/session; POS cleanup and supplier
invoices off; volume contents identical after the path change and back; web and worker share files; maintenance mode
set in one container applies to the other while `/up` stays 200; idle worker stops cleanly.

## 5. Blockers before approval

1. CI gate green on the pull request.
2. **Disable production auto-deploy before merging** (RUNBOOK §0): Railpack runs migrations at start by default
   ([Railpack PHP documentation](https://railpack.com/languages/php)).
3. Rescue the Worker's container-local files before any restart or redeploy.
4. Confirm in Coolify: volume names, same server, scheduler, ports, bind-mount support, MySQL version.
5. Production preflight and clone rehearsal (not performed: no production access).
6. Decisions: immediate invoice-before-payment eligibility for Company customers; permission grants; 
   `SUPPLIER_INVOICES_ENABLED`.
7. Operator browser UAT sign-off of staging `fe42683`.

Not verified: Coolify/Traefik behaviour, production data volumes and lock durations, SMTP, large uploads, long jobs
during a worker stop.
