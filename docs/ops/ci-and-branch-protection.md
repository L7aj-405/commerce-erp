# Continuous integration and branch protection

## What runs (`.github/workflows/ci.yml`)

Triggers: pull requests and pushes targeting `main` or `staging`, plus manual `workflow_dispatch`.
Permissions: `contents: read` only. No secrets, no deployment, no Staging/Production credentials or hosts.
Every action is pinned to a full commit SHA (the tag is in the trailing comment); update them deliberately.

| Job | Blocking | What it does |
|---|---|---|
| `php-tests` | yes | PHP 8.4 on a fresh runner. `composer validate --strict`, `composer install` (from `composer.lock`), `composer check-platform-reqs`, throw-away `.env` from `.env.example` + generated key, then the **complete** PHPUnit suite (`vendor/bin/phpunit`) against **SQLite in memory** (forced by `phpunit.xml`: sqlite, `:memory:`, array cache/session, sync queue). Warnings, deprecations, notices and **skipped tests are displayed**. A guard step fails the job if `public/build` exists or if `phpunit.xml` stops pinning the in-memory database; `tests/TestCase.php` stubs Vite (`withoutVite()`), so no test needs built assets. Each step runs with `pipefail`. JUnit XML is uploaded as an artifact. |
| `frontend` | yes | Node 22 (Vite 8 needs `^20.19 \|\| >=22.12`). `npm ci --ignore-scripts` (same flag as the project's `setup` script), `npx tsc --noEmit`, `npm run build`. Runs on its own runner, so a build can never modify assets while PHP tests execute. |
| `pint-changed` | yes | Fetches full history, computes the PHP files changed by the change set (PR: base..head; push: before..after; manual: merge-base with `origin/main`) and runs `vendor/bin/pint --test` on exactly those files. Reports violations, never rewrites files. New and modified code therefore cannot add style debt. |
| `pint-baseline` | no (advisory, visible) | Whole-repository `pint --test --format=json`; an annotation lists the number of non-conforming files and examples (the runner's Pint emits PHP-CS-Fixer JSON, which carries file names only; rule counts come from a local `pint --test --format=json`). This keeps the legacy debt (see the baseline strategy below) visible until it is cleared. |
| `composer-audit` | yes | `composer audit --locked` (audits `composer.lock` itself; nothing is installed). The decision is made by `.github/scripts/ci_annotate.py` from BOTH the exit code and the parsed report: any advisory fails the job; a crash, an unparsable/missing report, an unexpected exit code or an exit code that disagrees with the report is also a failure (annotated as "audit could not run", distinct from "N known vulnerabilities"). Abandoned packages only produce a warning. No `audit.ignore` entries exist. |
| `npm-audit` | yes (high/critical) | `npm audit --omit=dev --json` plus a gate: fails on high/critical findings **or** when the audit could not run; moderate and below are annotated, not blocking. |
| `ci-gate` | yes | One stable status: green only if `php-tests`, `frontend`, `pint-changed`, `npm-audit` and `composer-audit` all succeeded. Require **this** check. |

Failure diagnostics: failing PHPUnit tests, Pint violations and audit findings are emitted as workflow annotations by `.github/scripts/ci_annotate.py` (readable on the run page and through the API); the raw PHPUnit output and JUnit XML are uploaded as an artifact.

## Security audit status and policy

Status at this commit: **`composer audit --locked`: no advisories; `npm audit --omit=dev`: 0 vulnerabilities.** Both are blocking.

Composer advisories that were open before the upgrade (8 advisories, 5 packages) and how they were resolved with the smallest compatible change (`composer update laravel/framework symfony/http-foundation symfony/http-kernel symfony/mime symfony/routing --with-dependencies --minimal-changes`, framework held at the lowest fixed version, then the `composer.json` floor raised from `^13.30` to `^13.40` so a vulnerable version cannot be re-resolved):

| Package | Advisory | Severity | Affected | Fixed in | Upgrade | Used by the application? |
|---|---|---|---|---|---|---|
| laravel/framework | CVE-2026-128083 (PKSA-yxbx-3c9s-cjt9) task output injection, `Concurrency` process driver | low | >=13.0.0,<13.40.0 | 13.40.0 | v13.34.0 -> v13.40.0 | No: the `Concurrency` facade is not used. |
| symfony/http-foundation | CVE-2026-121619 (PKSA-nxjs-s6hn-vb6m) `private_ranges` trusted-proxy shortcut trusts IPv4-mapped / 6to4 / Teredo / NAT64 clients | unrated | >=8.1.0,<8.1.11 | 8.1.11 | v8.1.8 -> v8.1.11 | Indirectly: the app trusts proxies via explicit RFC1918 CIDRs (`TrustedProxyRanges`), not the `private_ranges` keyword, but it does sit behind a proxy. |
| symfony/http-foundation | CVE-2026-118514 (PKSA-24ph-xkrh-y79j) HEAD bypasses `methods` in `security.access_control` | unrated | >=8.1.0,<8.1.11 | 8.1.11 | same | No: Symfony Security is not used. |
| symfony/http-foundation | PKSA-pr6q-2w5s-kzzf path rules in front of PHP bypassed by appending to the script name | unrated | >=8.1.0,<8.1.11 | 8.1.11 | same | Depends on the web-server / proxy path rules in front of PHP (deployment config, not application code). |
| symfony/http-foundation | PKSA-b2gg-vk1g-6d5q generated URLs can point to a client-chosen host | unrated | >=8.1.0,<8.1.11 | 8.1.11 | same | **Yes, relevant:** URLs (e.g. emailed links) are built from the request host; there is no trusted-hosts middleware. Consider `trustHosts` / a fixed `APP_URL` root as hardening (not changed here). |
| symfony/http-kernel | CVE-2026-123339 (PKSA-3gxv-7xhq-mgmz) inline fragments let forwarded headers set host/scheme/port | unrated | >=8.1.0,<8.1.11 | 8.1.11 | v8.1.8 -> v8.1.11 | No: fragment / ESI rendering is not used. |
| symfony/mime | CVE-2026-45070 (PKSA-wtxr-p26d-nn42) email header injection via non-token characters in MIME parameter names | medium | >=8.1.0,<8.1.10 | 8.1.10 | v8.1.7 -> v8.1.10 | **Yes, relevant:** invoice / quotation / delivery-note / fulfillment emails carry attachments, including user-named proof files. |
| symfony/routing | CVE-2026-118516 (PKSA-y8c5-1b7f-r1gj) host placeholders can match the start of the path | unrated | >=8.1.0,<8.1.11 | 8.1.11 | v8.1.8 -> v8.1.11 | No: no domain / host-placeholder routes. |

Only six packages changed (the fixed packages plus `laravel/prompts` v0.3.24 -> v0.3.27, required by the new framework): no unrelated dependency moved and no major version changed.

Previously open npm findings (`follow-redirects` high, `qs` moderate) were fixed by a minimal lockfile patch (1.16.1 -> 1.16.3 and 6.16.0 -> 6.17.1); a lock generated by `npm update --package-lock-only` on Windows was out of sync and failed `npm ci`, so the two entries were patched by hand.

Policy: do not add `audit.ignore`. A new advisory makes `composer-audit` / `npm-audit` fail until the package is updated or a risk acceptance is reviewed and documented here.

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
**Root cause: not proven; most likely explanation found.** It failed once (`Not a valid Inertia response`) in a full local run that overlapped an `npm run build`, then passed in every later run on that machine.

What the CI investigation established:
* On a fresh checkout every Blade/Inertia page render threw `ViteManifestNotFoundException` (no `public/build/manifest.json`), i.e. HTTP 500 / "Not a valid Inertia response". 324 of 2283 tests failed on the runner this way and in the same way in every randomised soak run (deterministic, **not** order-dependent).
* Locally the same tests passed only because a stale `public/hot` file (Vite dev-server marker) made `@vite` skip the manifest. Earlier "clean environment" simulations that hid only `public/build` were therefore invalid. With both `public/hot` and `public/build` absent the failure reproduces locally.
* So whether page tests pass depended on frontend build / dev-server state, which a concurrent `npm run build` can disturb. This is the best-supported explanation for the single local failure, but that exact event was not reproduced.
* Fix: the shared `tests/TestCase.php` now calls `withoutVite()`, so PHP tests no longer depend on built assets or a dev server.

Other environment differences the runner exposed and how they are handled: `APP_DEBUG` is pinned to `false` in `phpunit.xml` (the error-leak test searches for `vendor/laravel`, which can only match on Linux paths, and CI copies `.env.example` where debug is on); the runner installs the `timezonedb` extension so Africa/Casablanca rules match (`tzdb=2025.2`); and the run annotations print the PHP/ICU/SQLite/tz facts and the Casablanca offsets the tests rely on.

Evidence at commit `544864e`: CI `PHP tests (full suite)` passed (2283 tests, 0 failing) and the soak workflow completed 3 randomised full runs, all 2283 tests, 0 failing (seeds 17949320261, 17949320262, 17949320353).

### Pitfalls found while building this pipeline
* **Pipes hide failures.** GitHub's default `run` shell has no `pipefail`: `phpunit | tee out.txt` returned `tee`'s status and a failing suite was reported as success (the PHP and soak jobs were green while 324 tests failed). Both workflows now set `defaults.run.shell: bash` (`-eo pipefail`). Never pipe a gating command without it.
* **Local green is not CI green** while `public/hot`, `public/build`, a `.env`, or a different tz database exist locally. Reproduce CI conditions by hiding `public/hot` and `public/build` and using a clean `.env.testing` copied from `.env.example`.
* On some Windows shells `vendor/bin/phpunit` and `vendor/bin/pint` print nothing and return 0; call them through `php vendor/phpunit/phpunit/phpunit` and `php vendor/laravel/pint/builds/pint`.

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
composer audit --locked && npm audit --omit=dev   # both must be clean
npm ci --ignore-scripts && npx tsc --noEmit && npm run build
```
Do not run `npm run build` while PHP tests execute in the same working copy.
