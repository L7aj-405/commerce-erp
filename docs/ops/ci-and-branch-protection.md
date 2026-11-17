# Continuous integration and branch protection

## What runs (`.github/workflows/ci.yml`)

Triggers: pull requests and pushes targeting `main` or `staging`, plus manual `workflow_dispatch`.
Permissions: `contents: read` only. No secrets, no deployment, no Staging/Production credentials or hosts.
Every action is pinned to a full commit SHA (the tag is in the trailing comment); update them deliberately.

| Job | Blocking | What it does |
|---|---|---|
| `php-tests` | yes | PHP 8.4 on a fresh runner. `composer validate --strict`, `composer install` (from `composer.lock`), `composer check-platform-reqs`, throw-away `.env` from `.env.example` + generated key, then the **complete** PHPUnit suite (`vendor/bin/phpunit`) against **SQLite in memory** (forced by `phpunit.xml`: sqlite, `:memory:`, array cache/session, sync queue). Warnings, deprecations, notices and **skipped tests are displayed**. A guard step fails the job if `public/build` exists (tests must not depend on, or race with, a frontend build) or if `phpunit.xml` stops pinning the in-memory database. JUnit XML is uploaded as an artifact. |
| `frontend` | yes | Node 22 (Vite 8 needs `^20.19 \|\| >=22.12`). `npm ci --ignore-scripts` (same flag as the project's `setup` script), `npx tsc --noEmit`, `npm run build`. Runs on its own runner, so a build can never modify assets while PHP tests execute. |
| `pint-changed` | yes | Fetches full history, computes the PHP files changed by the change set (PR: base..head; push: before..after; manual: merge-base with `origin/main`) and runs `vendor/bin/pint --test` on exactly those files. Reports violations, never rewrites files. New and modified code therefore cannot add style debt. |
| `pint-baseline` | no (advisory, visible) | Whole-repository `pint --test --format=json`; an annotation lists the number of non-conforming files, the most frequent rules and examples. This keeps the legacy debt (see the baseline strategy below) visible until it is cleared. |
| `composer-audit` | no, **temporarily** | `composer audit --locked` (audits `composer.lock` itself; nothing is installed). The annotation separates **"N known vulnerabilities"** from **"audit could not run (command/infrastructure error)"** (Composer exit codes 1/2/3 are findings, anything else or unparsable output is an infrastructure failure). The job still fails and annotates every finding; it is only kept out of the gate until the pending dependency upgrade is approved (below). |
| `npm-audit` | yes (high/critical) | `npm audit --omit=dev --json` plus a gate: fails on high/critical findings **or** when the audit could not run; moderate and below are annotated, not blocking. |
| `ci-gate` | yes | One stable status: green only if `php-tests`, `frontend`, `pint-changed` and `npm-audit` all succeeded. Require **this** check. |

Failure diagnostics: failing PHPUnit tests, Pint violations and audit findings are emitted as workflow annotations by `.github/scripts/ci_annotate.py` (readable on the run page and through the API); the raw PHPUnit output and JUnit XML are uploaded as an artifact.

## Security audit status and policy

Audit results at this commit (locked dependencies):

* **npm (production): 0 vulnerabilities.** Fixed by a minimal lockfile patch: `follow-redirects` 1.16.1 -> 1.16.3 (high) and `qs` 6.16.0 -> 6.17.1 (moderate); only version/resolved/integrity changed, `npm ci` and the build were verified. The lock was patched by hand because a generated lock (`npm update --package-lock-only` on Windows) was out of sync and failed `npm ci`.
* **Composer: 8 advisories in 5 packages, NOT fixed here.** `laravel/framework` v13.34.0 (low, fixed in >= 13.40.0), `symfony/http-foundation` v8.1.8 (trusted-proxy, path-rule and host-generation advisories; fixed in >= 8.1.11), `symfony/http-kernel` v8.1.8, `symfony/mime` v8.1.7 (medium, email header injection; fixed in >= 8.1.10) and `symfony/routing` (host placeholder matching). `composer update laravel/framework symfony/http-foundation symfony/http-kernel symfony/mime symfony/routing --with-dependencies` resolves them inside the existing constraints but moves about 40 packages (Laravel 13.34 -> 13.42, Guzzle 8.2 -> 8.5, Carbon, Monolog, ...). That is a deliberate dependency upgrade that must be reviewed and run against the full suite and Staging; it is intentionally **not** bundled into a CI change.
* Policy: the failing `composer-audit` job is not hidden or ignored (no `audit.ignore` entries were added). When the upgrade lands, add `composer-audit` to `ci-gate.needs` and drop `continue-on-error`.

## Pint baseline strategy

Measured on a CI-faithful LF checkout: 415 PHP files in the repository do not match the Laravel Pint preset (mostly `braces_position`, `ordered_imports`, `not_operator_with_successor_space`, `statement_indentation`, `unary_operator_spaces`, `single_line_empty_body`). They are pre-existing technical debt, not caused by one feature. The release (main..staging) touched 84 PHP files; 45 of them failed, including 11 files the release added. Those 45 were formatted in this change (formatting only; the full suite stayed green: 2283 tests, 17339 assertions).

For the remaining legacy files:

1. Keep `pint-changed` blocking (already active): every changed file must conform, so the debt can only shrink.
2. Schedule one **dedicated, mechanical formatting PR** (`vendor/bin/pint`, nothing else, no behaviour change), reviewed by the full test suite, and list its commit in a `.git-blame-ignore-revs` file so `git blame` stays useful. Do not mix it with feature work.
3. After it merges, delete the `pint-baseline` job's `continue-on-error` and gate on a whole-repo `pint --test`; `pint-changed` can then be removed.

Note for local runs: on some Windows shells `vendor/bin/pint` and `vendor/bin/phpunit` print nothing and return 0 (a wrapper problem), which can mask failures. Use `php vendor/laravel/pint/builds/pint --test` and `php vendor/phpunit/phpunit/phpunit`.

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
php vendor/phpunit/phpunit/phpunit --no-progress        # full suite, SQLite in memory
php vendor/laravel/pint/builds/pint --test              # whole repo (legacy debt) - CI gates changed files
composer audit --locked && npm audit --omit=dev
npm ci --ignore-scripts && npx tsc --noEmit && npm run build
```
Do not run `npm run build` while PHP tests execute in the same working copy.
