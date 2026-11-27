# Step 6 QA accounts (Staging only)

Four temporary roles and accounts in `10xScale QA Morocco`, scoped to the store `QA Showroom`, to test
`payments.refund` / `payments.reverse` independently. Implemented by `php artisan erp:qa-access`.

## What it creates

| Role slug | Name | Extra permissions beyond the shared base |
|---|---|---|
| `qa-step6-refund` | QA Refund only | `payments.refund` |
| `qa-step6-reverse` | QA Reverse only | `payments.reverse` |
| `qa-step6-both` | QA Refund and Reverse | both |
| `qa-step6-neither` | QA Neither | none |

Shared base (identical for all four, so the two payment permissions are the only difference):
`sales_orders.view`, `sales_orders.cancel`, `payments.view`, `sales_returns.view`, `sales_exchanges.view`,
`sales_exchanges.process`. No role/member/settings permission is granted. Roles are non-privileged, so the
Owner/Admin 2FA policy does not apply to them.

## Guarantees

- Refuses when `APP_ENV=production`. Dry run unless `--execute`.
- Runs AS an existing QA administrator through `RolePermissionService` / `MembershipService`: the escalation
  guards stay active (a role can only receive permissions the administrator holds). If the administrator lacks one,
  the command stops before creating anything and names it.
- Never edits an existing role or account. Existing emails that it did not create are refused.
- No invitation and no email. Accounts get an unusable random password; you receive one-time password-setup links.
- Nothing is stored in the repository. Emails are passed on the command line; links are printed once.

## Prerequisites

1. The QA administrator (normally the Owner of `10xScale QA Morocco`) holds `payments.refund`
   (requires the Step 6 migrations `2026_11_23_000001/2` to have run) and `members.create`, `roles.assign-permissions`,
   `store-memberships.manage`.
2. That administrator has logged in once and selected organization `10xScale QA Morocco` and store `QA Showroom`
   (the command verifies the active context and changes nothing about it).
3. Choose four mailbox addresses you control (plus-addressing works). No mail is sent to them.

## Setup

```
E="--actor=<admin email> --email-refund=<a> --email-reverse=<b> --email-both=<c> --email-neither=<d>"
php artisan erp:qa-access plan  $E            # dry run
php artisan erp:qa-access setup $E --execute  # apply, prints four one-time links
php artisan erp:qa-access links $E --execute  # re-issue fresh links if one expired
```

## Authentication

Open each link, choose a password (standard reset form), then sign in at `/login` with email and password.
No session is forged. Links expire per `auth.passwords.users.expire`.

## Validation

For each account, open a posted payment and a paid order in `QA Showroom`:

| Account | Refund / cancel paid order / return or exchange refund | Reverse payment |
|---|---|---|
| refund | allowed | 403 / button hidden |
| reverse | 403 | allowed |
| both | allowed | allowed |
| neither | 403 | 403 |

Backend enforcement of all four combinations is covered by `tests/Feature/Console/QaAccessCommandTest.php`.

## Cleanup

```
php artisan erp:qa-access cleanup $E            # dry run
php artisan erp:qa-access cleanup $E --execute
```

Removes the four store and organization memberships, deletes the `qa-step6-*` roles (kept if somebody else still holds
one), and locks the accounts (random password, sessions and reset tokens revoked). Account rows are kept on purpose so
audit history and records created during QA stay intact. Owner, Admin and staff roles are never touched.
