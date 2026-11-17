# Continuous integration and branch protection

## What runs (`.github/workflows/ci.yml`)

Triggers: pull requests and pushes targeting `main` or `staging`, plus manual `workflow_dispatch`.
Permissions: `contents: read` only. No secrets, no deployment, no Staging/Production credentials or hosts.
Every action is pinned to a full commit SHA (the tag is in the trailing comment); update them deliberately.

| Job | Blocking | What it does |
|---|---|---|
| `php-tests` | yes | PHP 8.4 on a fresh runner. `composer validate --strict`, `composer install` (from `composer.lock`), `composer check-platform-reqs`, throw-away `.env` from `.env.example` + generated key, then the **complete** PHPUnit suite (`vendor/bin/phpunit`) against **SQLite in memory** (forced by `phpunit.xml`: sqlite, `:memory:`, array cache/session, sync queue). Warnings, deprecations, notices and **skipped tests are displayed**. A guard step fails the job if `public/build` exists (tests must not depend on, or race with, a frontend build) or if `phpunit.xml` stops pinning the in-memory database. JUnit XML is uploaded as an artifact. |
| `frontend` | yes | Node 22 (Vite 8 needs `^20.19 \|\| >=22.12`). `npm ci --ignore-scripts` (same flag as the project's `setup` script), `npx tsc --noEmit`, `npm run build`. Runs on its own runner, so a build can never modify assets while PHP tests execute. |
| `pint` | yes | `vendor/bin/pint --test`: reports style violations, never rewrites files. |
| `dependency-audit` | no (advisory) | `composer audit` and `npm audit --omit=dev`. `continue-on-error`: known findings stay visible without blocking unrelated work. |
| `ci-gate` | yes | One stable status that is green only if `php-tests`, `frontend` and `pint` all succeeded. Require **this** check. |

## Flaky-test soak (`.github/workflows/flaky-soak.yml`)

Not a merge gate. Manual (`Actions > Flaky-test soak > Run workflow`) and weekly (Monday 03:17 UTC, default branch only). It runs the **complete** suite 1-6 times (default 3), each on an independent fresh runner with no frontend build, optionally in **random order** (default). Each run prints its seed (`--random-order-seed`) and uploads a JUnit report, so an intermittent or order-dependent failure is reproducible locally:

```
vendor/bin/phpunit --order-by=random --random-order-seed=<seed>
```

No test is skipped, excluded or retried. A red soak run is an investigation lead, not noise.

### Status of the `SalesOrderAttentionTest::test_visibility_store_isolation_navigation_and_timezone` failure
It failed once with `Not a valid Inertia response` in a full local run that overlapped an `npm run build`, and then passed in isolation, in its directory (47/47) and in a clean full run (2283/2283). Hiding or emptying `public/build/manifest.json` did **not** reproduce it, so the cause is **not proven**. The CI layout removes the suspected interference (the build is a separate job; `php-tests` asserts `public/build` is absent). If the soak ever reproduces it, the seed and JUnit report identify the preceding tests; do not disable the test.

## Recommended branch protection for `main` (not applied: requires repository-admin authorization)

Settings > Branches > Branch protection rule (or a Ruleset) for `main`:

1. **Require a pull request before merging**: at least 1 approving review; dismiss stale approvals on new commits; require review from code owners if a `CODEOWNERS` file is added.
2. **Require status checks to pass before merging**: select `CI gate` (and, if you prefer the granular view, `PHP tests (full suite)`, `Frontend (typecheck and production build)`, `Code style (Laravel Pint, check only)`). **Require branches to be up to date before merging.**
3. **Block direct pushes** to `main` (restrict who can push; include administrators if policy allows). Block force pushes and deletions.
4. **Require linear history** (optional) and **require conversation resolution**.
5. Allow only the merge method the team uses; keep `staging` as the integration branch and promote to `main` by reviewed PR.
6. Optionally protect `staging` with the same required checks (without the review requirement) so UAT builds are always green.
7. Under Settings > Actions > General: restrict workflow permissions to **read** by default, require approval for workflows from outside collaborators, and consider allowing only actions pinned by SHA.

Deployment (Coolify) is intentionally separate from CI. If deployment is later triggered from GitHub, keep it a different workflow with a protected `environment` and manual approval; never reuse CI jobs for it.

## Local equivalents
```
composer install && cp .env.example .env && php artisan key:generate
vendor/bin/phpunit --no-progress        # full suite, SQLite in memory
vendor/bin/pint --test
npm ci --ignore-scripts && npx tsc --noEmit && npm run build
```
Do not run `npm run build` while PHP tests execute in the same working copy.
