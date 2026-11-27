# Final Phase 1 backend UAT — Step 6 (payment permissions) and Step 11 (typed policies)

Base: `origin/staging` 5b6f863. Evidence = automated tests on **disposable fixtures** (SQLite `:memory:` and the local disposable MySQL 8.4 `commerce_erp_dev_test`). Nothing was run against Staging or Production, and no Staging data was read or written.

Limits that apply to every PASS below: these are automated backend tests, not the independent human UAT with real role accounts that Step 6 still requires on Staging; browser behavior is not covered.

## Step 6 — payment permission matrix (roles: refund-only / reverse-only / both / neither)
Existing `PaymentPermissionMatrixTest` (unchanged) + new `PaymentPermissionNoMutationTest` (added here: it snapshots payments, payment_refunds, payment_allocations, financial_accounts, sales_orders and audit_logs before/after each denied call and requires an identical hash).

| Operation (real route / Policy) | Permission rule proven | Result |
|---|---|---|
| `POST payments.reverse` — `PaymentPolicy::reverse` | requires `payments.reverse`; `payments.refund` alone → 403, no mutation | PASS (4 roles) |
| `POST sales.returns.refunds.store` — `CustomerReturnPolicy` | requires `payments.refund`; reverse alone → 403, 0 refunds, no `payment.refunded` audit | PASS (4 roles, existing test) |
| `POST sales.exchanges.refunds.store` | same | PASS (4 roles, existing test) |
| `POST sales.orders.cancel` on an order with posted payments — `SalesOrderPolicy::cancel` | requires `payments.refund`; reverse-only / neither → 403, full financial snapshot identical, order not cancelled; refund-capable roles cancel, a refund row for the exact amount is created and audited | PASS (4 roles, **new**) |
| Same route, order without posted payments | cancel allowed without `payments.refund` | PASS (new) |
| Cross-organization | both permissions elsewhere do not authorize | PASS (existing test) |
| Exchange page `can.refund` | follows `payments.refund` only | PASS (existing test) |

Mutation check: replacing `payments.refund` by `payments.reverse` in `SalesOrderPolicy::cancel` made the new cancellation matrix fail (refund-only case); policy restored, file unchanged.

Not covered / residual: `monthly-situation.payments.reverse` (outgoing payments) is a different permission domain and was not part of this matrix. Independent Staging role-account UAT: **BLOCKED** (needs the operator; commands below).

## Step 11 — typed policies
`PolicyResolver`, `PolicyAudit`, `UpdateOrganizationPoliciesAction` were reviewed. The action has **no HTTP route or UI**, so authorization is proven at Action level (the action raises HTTP 403 itself); there is no route-level 403 to test yet.

| # | Check | Evidence | Result |
|---|---|---|---|
| 1 | Existing orgs keep 7 / 30 days | `test_organizations_default_to_current_behavior`, `test_due_dates_are_identical_…` | PASS |
| 2 | Reason required | `test_a_reason_is_required` | PASS |
| 3 | `organization.policy_changed` audit, old→new, reason, actor, `future_documents_only` | `test_owner_change_is_audited_old_to_new_with_reason`; no-op changes write nothing | PASS |
| 4 | Unauthorized rejected | `test_each_policy_requires_its_named_permission` (403, values unchanged; legacy `settings.update` alone insufficient) | PASS |
| 5 | Cross-organization rejected | `test_tenant_isolation_…`, `test_holder_of_the_permission_in_org_a_…` (403, no audit, no change) | PASS |
| 6 | Only future documents | `test_a_policy_change_only_affects_future_documents` | PASS |
| 7 | Confirmed order / issued invoice keep due dates | same test (order and issued invoice due dates unchanged) | PASS |
| 8 | Invalid values rejected | `test_enum_and_bound_validation_…` (bad enums, 366, -1, "x", null enum), `test_unknown_policy_keys_are_refused` | PASS |
| 9 | Null restores inherited default | `test_clearing_an_override_…` + resolver decision tables | PASS |
| 10 | `invoice_issuance` / `credit_limit_enforcement` inactive | **Gap found and closed**: the existing test used a helper that pays the order before issuing, so the refusal path was never exercised. New `PolicyInactivityTest`: an unpaid order is refused with the identical message for all 3×3 value combinations and no invoice is issued; `block` does not stop a 999 999 order confirmation; due dates unchanged | PASS |

Residual: policy changes take effect only through the Action (no route/UI yet); the DB has no CHECK bounding the day columns (validated in the action and again by the resolver on read).

## Test runs (state: `test/phase-1-final-backend-uat` = staging 5b6f863 + 2 new test files + this report)
| Run | Env | Result |
|---|---|---|
| `PaymentPermissionNoMutationTest` | SQLite | PASS 9 tests / 33 assertions |
| `tests/Feature/Policies` | SQLite | PASS 41 / 157 |
| Payments, Policies, UsersRolesPermissions, SalesOrderCancellationTest, SalesPermissionProvisioningTest, QaAccessCommandTest, `tests/Unit/Architecture` (tenant-scope, financial-precision, AuditCoverage, policy guards) | SQLite | PASS 221 / 1580 |
| Payments, Policies, UsersRolesPermissions, SalesOrderCancellationTest | MySQL 8.4 local `commerce_erp_dev_test` | PASS 190 / 932 |
| Pint on the new test files | — | PASS (after auto-format) |
| Full SQLite suite | — | NOT RUN in this task (only test files added; no application code changed) |

## Staging verification (prepared, NOT executed — BLOCKED: no Staging access from this session)
All commands below are read-only. Do not run mutations on the shared QA dataset; for mutation scenarios first create a disposable organization.

```bash
# S11-1 defaults on existing organizations: expect invoice_issuance=after_full_payment, credit_limit_enforcement=warn, both day columns NULL
php artisan tinker --execute='DB::table("organizations")->get(["id","invoice_issuance","credit_limit_enforcement","sales_order_payment_term_days","invoice_payment_term_days"])->each(fn($o)=>print(json_encode($o).PHP_EOL));'
# S11-2 migrations applied: expect both rows
php artisan migrate:status | grep -E "typed_policy_columns|provision_policy_permissions"
# S11-3 permissions exist: expect 2
php artisan tinker --execute='echo App\Models\Permission::whereIn("key",["settings.billing.manage","customers.manage_credit"])->count();'
# S11-4 no policy change recorded unless the operator performed one: expect 0 (or only the operator's disposable-org changes)
php artisan tinker --execute='echo DB::table("audit_logs")->where("event","organization.policy_changed")->count();'
# S11-5 due-date stamping unchanged for a recent confirmed order: expect due_date - sale_date = 7 (and invoices 30) on orgs with NULL overrides
php artisan tinker --execute='DB::table("sales_orders")->whereNotNull("due_date")->latest("id")->limit(5)->get(["id","sale_date","due_date"])->each(fn($o)=>print($o->id." ".(new Carbon\Carbon($o->due_date))->diffInDays($o->sale_date).PHP_EOL));'
# S6-1 which users hold which payment permission (per org) — review for refund/reverse separation
php artisan tinker --execute='$o=App\Models\Organization::find(<ORG_ID>); App\Models\User::all()->each(function($u) use($o){ $k=$u->permissionKeysFor($o); print($u->id." refund=".(int)in_array("payments.refund",$k)." reverse=".(int)in_array("payments.reverse",$k).PHP_EOL);});'
```
Mutation scenarios (disposable org only): sign in with four disposable accounts (refund-only, reverse-only, both, neither) and attempt reverse / return refund / exchange refund / paid-order cancel; expect 403 for denied cases and unchanged `payments`, `payment_refunds`, `audit_logs` counts. For Step 11, use `UpdateOrganizationPoliciesAction` via tinker on the disposable org with and without a reason, and verify the `organization.policy_changed` rows.

## Verdict
- Step 6 permission matrix, backend: **PASS** on disposable fixtures (SQLite + MySQL). Independent Staging role-account UAT: **BLOCKED** (operator).
- Step 11 checks 1–10: **PASS** on disposable fixtures. Staging read-only evidence: **BLOCKED** (commands prepared above).
