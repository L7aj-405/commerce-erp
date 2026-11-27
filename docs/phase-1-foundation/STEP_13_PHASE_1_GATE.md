# Step 13 — Phase 1 exit gate (execution report)

Branch `fix/phase-1-step-13` = `origin/staging` (ec066ac) + Step 11 (`dbcda13`) + Step 12 (`771bf7b`) merged (integration checkout, never a deployment source).

## 1. Dependency matrix
| Step | Depends on | Shared files | Parallel? | Merge order |
|---|---|---|---|---|
| 11 typed policies | 6 (in staging) | `config/platform.php`, `IssueInvoiceAction`, `ConfirmSalesOrderAction` | yes with 12 | 1st |
| 12 reliability UX | 2 (in staging) | `IssueInvoiceAction` (message constant only), `translations.ts` | yes with 11 | 2nd (merges cleanly after 11, verified) |
| 13 gate | 11, 12 | this doc only | no | last |

## 2. Combined test results
State tested: integration checkout `d440f6b` (staging ec066ac + Step 11 + Step 12). Runs used an untracked worktree autoloader because `vendor/` was a junction to another checkout.

| Run | Env | Result |
|---|---|---|
| `tests/Unit/Architecture` + `tests/Feature/Policies` (includes tenant-scope, financial-precision, AuditCoverage, policy architecture guards) | SQLite :memory: | PASS — 55 tests, 654 assertions, exit 0 |
| Full suite `phpunit` | SQLite :memory: | PASS — 2515 tests, 19088 assertions, exit 0 |
| CI MySQL subset (Finance, Payments, Documents, Sales, Pos, Tenancy, Sandbox, Exchanges) + Policies | MySQL 8.4 local disposable `commerce_erp_dev_test` | **1 FAIL of 982** (981 pass, 7903 assertions): `QuotationPdfLayoutTest::test_long_quotation_paginates_and_closing_section_and_stamp_render_exactly_once` ("0 is identical to 1"). Re-run alone: PASS (1 test, 6 assertions). The failing run overlapped the full SQLite suite (heavy PDF rendering, ~91 s alone); treated as a load-sensitive flake, **not proven**. Re-check in GitHub CI. |
| `npx tsc --noEmit`, `npm run build` | — | PASS on Step 12 branch per Agent 12 (exit 0); not re-run on the merge |
| Pint | — | Agent 12: `pint --test` PASS on changed files. Agent 11: remaining findings are CRLF/line-ending style on pre-existing files; not rewritten |
| Concurrency scripts | — | NOT RUN (no Step 11–13 change touches race-sensitive mutations) |
| Migration tests on MySQL | — | Exercised implicitly by RefreshDatabase on MySQL; no dedicated rollback run |
| `php artisan test` wrapper | — | Exits 1 even on passing runs in this Windows setup (reported by Agent 11); PHPUnit direct exit codes are used above |

## 3. Phase 1 matrix (Implementation / CI / Merged / Deployed / UAT)
| Step | Implementation | Merged | Deployed | UAT / blockers |
|---|---|---|---|---|
| 1–5 | done | yes | yes | accepted |
| 6 | done | yes | yes | independent permission-matrix UAT **pending** |
| 7 | done | yes | yes | accepted (12 CHECK constraints verified) |
| 8 | done | yes | yes | 7/7 PASS; R-2 returned null expected/actual — confirm accounts with opening positions were exercised |
| 9 | done | yes (#41) | reported by operator | deployed Web/Worker SHA health evidence required |
| 10 | done | yes (#42) | reported by operator | isolated-copy restore UAT **pending** (never on shared QA org) |
| 11 | done, pushed | no | no | UAT below |
| 12 | done, pushed | no | no | UAT below; browser checks not yet performed |
| 13 | this report | no | no | needs CI + operator review |

CI for Steps 11–13 is **not yet verified**: no PR exists (no authenticated PR tool). A pushed branch is not a CI result.

## 4. Open decisions to review (plan D-F1…D-F5)
- D-F2 policy storage: implemented as typed columns on `organizations` + `PolicyResolver` (recommendation followed).
- Step 11 grants `settings.billing.manage` / `customers.manage_credit` to admin, general_admin and roles holding `settings.update`; **Finance does not receive `customers.manage_credit`** although the design doc says "Owner/Finance" — owner decision needed.
- D-F1, D-F3, D-F4, D-F5 and accounting answers Q-A1…Q-A4/Q-B1/Q-E1 remain with the owner; Phase 1 Gate item 6 is not evidenced here.

## 5. Staging deployment plan (to be executed by the operator)
1. Confirm CI green on each PR. Merge 11 then 12 (13 is docs-only; merge last). Record each merge SHA.
2. Back up the Staging DB first. Preflight (read-only): `SHOW COLUMNS FROM organizations LIKE 'invoice_issuance';` must return no rows before Step 11 migrates.
3. Deploy Web and Worker/scheduler on the **same merge commit**; restart queue workers; verify `php artisan about` / System Health on both.
4. Migrations run: `2026_11_25_000001` (4 nullable/defaulted columns on `organizations`) and `2026_11_25_000002` (permission provisioning; `down()` is a no-op). Rollback: redeploy previous commit; the added columns are harmless to old code (can be dropped via `down()` of 000001 if required).
5. Post-deploy smoke: `php artisan finance:reconcile` still read-only and PASS; AuditCoverage health; confirm an order (due = +7 d) and issue an invoice (due = +30 d) unchanged; Step 12 UI checks below.

## 6. Staging UAT
**Step 11** (database-level evidence via tinker/SQL; not a browser test)
1. Existing org, no action: due dates identical to before (7 d order, 30 d invoice).
2. Set `sales_order_payment_term_days=15` with reason via `UpdateOrganizationPoliciesAction`: audit event `organization.policy_changed` old→new + reason + actor; new order +15 d; previously confirmed order unchanged.
3. User without permission / other org: 403, no audit row.
4. Invalid enum / 366 days rejected; null restores 7/30.
5. `invoice_issuance=on_delivery` changes nothing about invoice issuance.
6. Cleanup: reset the QA org's overrides to null.

**Step 12** (browser/UI; prop-level evidence from tests only)
1. Unpaid confirmed order → issue invoice: actionable message; "record payment" link only for users with `payments.create`.
2. Customer page tooltips; Incomplet badge for user lacking `payments.view`; Estimé on non-invoiced balance.
3. Créances total definition tooltip.
4. Payment page: distinct cancel vs refund help (FR/AR).
5. MCP settings + create dialog show the production alert; a `preview_*` MCP call returns `production_warning`.
6. Role editor: tick payments.create + reverse + refund → alert; untick → clears.
7. Check light/dark contrast, RTL, touch target of `InfoTip` (unverified).

## 7. Readiness
Not "accepted". Phase 1 is accepted only after CI green on all PRs, Staging UAT above, and the outstanding Step 6/8/9/10 items.
