# Testing workflow audit (baseline, no infrastructure changed)

Read-only audit of the current test setup. Numbers come from the repository and from GitHub Actions run
`45160210735` (staging push, commit `8782e66`, all blocking jobs green). Local timings were **not** measured:
the policy is not to run the full suite locally, so no local figure is claimed here.

## 1. Existing test commands

| Purpose | Command | Database |
| --- | --- | --- |
| Full PHP suite | `vendor/bin/phpunit` (or `composer test` -> `php artisan test`) | SQLite `:memory:` (`phpunit.xml`) |
| Architecture guard only | `vendor/bin/phpunit tests/Unit/Architecture` | SQLite |
| MySQL critical suites | `phpunit -c phpunit.mysql.xml tests/Feature/Pos tests/Feature/Tenancy tests/Feature/Sandbox tests/Feature/Exchanges` | MySQL 8.4, `*_test` DB, host pinned to 127.0.0.1 |
| Concurrency races | `php tests/Concurrency/<name>.php --rivals=N --rounds=N --database=...` (12 scripts) | MySQL, real parallel sessions |
| Frontend unit/SSR render | `npm run test:frontend` (`node:test`, 4 files, 26 tests) | none |
| Typecheck / build | `npx tsc --noEmit` / `npm run build` | none |
| Pint (changed files / whole repo) | CI jobs `pint-changed` (blocking) / `pint-baseline` (advisory) | none |

Size: 329 Feature files + 19 Unit files, roughly 2,600 test methods. Largest areas: Finance 57 files, Pos 24,
Documents 24, Catalog 19, Auth 19, Security/TenantRedTeam 17, Sales 17, Sandbox 16, Inventory 16.

## 2. Database setup and migration overhead

- `phpunit.xml` pins SQLite in memory; `phpunit.mysql.xml` force-pins MySQL host, driver and a `*_test` database,
  and CI greps those pins before running.
- 142 migrations, no schema dump (`database/schema` absent), so every migrating test class pays the full chain.
- Database reset is `RefreshDatabase` (via `Tests\Support\PlatformTestCase` and a few direct users). On SQLite
  `:memory:` this migrates once per process, then wraps each test in a transaction.
- The MySQL job's one long step is described by its own log as "migrations run through RefreshDatabase": 5.1 min of its 6.1.
- Each PHP job installs a pinned timezonedb (0.2-0.4 min) before running.

## 3. CI job durations (run 45160210735)

Wall clock about 8.6 min; jobs run in parallel, so the critical path is the slowest job.

| Job | Time | Blocking |
| --- | --- | --- |
| MySQL 8.4 concurrency | 8.5 min | yes |
| MySQL 8.4 critical suites | 6.1 min (5.1 in phpunit) | yes |
| PHP tests (full suite, SQLite) | 5.2 min (4.7 in phpunit) | yes |
| Pint baseline (whole repo) | 1.5 min | no, advisory, currently failing |
| Frontend (tsc, tests, build) | 0.6 min | yes |
| Pint changed files | 0.7 min | yes |
| npm audit / composer audit | 0.2-0.3 min | yes |

Slowest concurrency steps: document number sequences 2.1 min, supplier invoice void-vs-payment 0.9, outgoing
payments over-allocation 0.9. Recent staging runs: 8.4-11.2 min, all green.

Slowest PHP *test classes* are **unknown**: CI uploads a JUnit file (`phpunit-junit` artifact) but nothing aggregates
it. That is the first gap to close before optimizing anything.

## 4. Safe optimization opportunities (proposals only, none applied)

1. **Measure first.** Add a non-blocking CI step that prints the 20 slowest test classes from the existing JUnit file.
   No test or isolation change; gives the evidence every later change needs.
2. **Concurrency job is the critical path (8.5 min).** Candidates, each needing review: run the independent race
   scripts as a matrix of parallel jobs (isolation per job, same assertions, same `--rivals/--rounds`); do not lower
   rivals/rounds.
3. **Fast local lane for developers.** Document targeted commands per area, for example
   `vendor/bin/phpunit tests/Feature/Finance --filter SupplierInvoice` and `phpunit.mysql.xml` for one directory, so
   the full suite stays in CI.
4. **Path-aware local guidance** (not CI gating): map changed paths to suites (Finance, Pos, Tenancy...) in a doc or
   a small script, so "smallest relevant subset" is mechanical.
5. **Pint baseline** is advisory and failing; it costs a runner but does not block. Decide whether to fix the backlog
   or drop the job; do not make it blocking until it is clean.
6. **Schema dump** (`php artisan schema:dump`) could cut migration time for MySQL, but it changes the database test
   architecture and must be validated against both engines with regression evidence before adoption.
7. **Parallel PHPUnit (paratest)** is not installed. It would add a dependency and shared-state risk (SQLite memory is
   per process, MySQL needs per-worker databases); evaluate only after step 1 data exists.

Not recommended: skipping suites on PRs, weakening MySQL pins, lowering race iterations, or treating SQLite results
as MySQL evidence.

## 5. Known coverage limits

- SQLite passing does not prove MySQL behavior; only the four MySQL suites plus the race scripts run on MySQL.
- Frontend tests are unit/SSR render tests, not browser E2E (see `ci-and-branch-protection.md`).
- Browser acceptance is performed by the operator on staging with disposable QA data.
