# Return eligibility display fix (Phase 1 UAT remediation)

## Observed
SO-000012 (ID 13), 2026-11-21: the order page showed "Délai de retour dépassé" while the displayed deadline (2026-11-26 12:06) was still in the future. No return was attempted.

## Root cause
`ReturnPolicyService::evaluate()` returned `within_policy = enabled && now <= deadline`. That flag is false in two different situations — returns **disabled** for the store, and deadline **passed** — and `Orders/Show.tsx` rendered every `!within_policy` as "Délai de retour dépassé". Returns are disabled by default (`enabled=false`), so a fulfilled order with a future deadline reproduces the message.

The most likely trigger for SO-000012 is therefore returns being disabled for its store/organization. This was derived from code, **not** confirmed against Staging data: verify the effective policy on that store (Settings → Retours).

`Exchanges/Create.tsx` had the same conflation (it showed "Délai dépassé" in addition to "Retours désactivés"). `Returns/Create.tsx` and `Returns/Index.tsx` already branched on `enabled` correctly.

## Backend
The blocking logic was already correct: `CreateCustomerReturnAction` checks fulfilment, then `enabled` ("Les retours sont désactivés."), then the window ("Délai de retour dépassé."). Eligibility rules, override rules and the inclusive deadline are unchanged; nothing is loosened.

## Change
- `ReturnPolicyService::evaluate()` now also returns `status` (`not_fulfilled | disabled | within_window | window_expired`) and `window_open` (purely temporal). `within_policy` is unchanged.
- Instants are computed and serialized in the organization's business timezone (`BusinessTime`), instead of `config('app.timezone')`. The comparison is on absolute instants, so eligibility does not change; only the offset in the ISO string follows the business calendar.
- `/sales/returns/search` also returns `status`.
- `Orders/Show.tsx` shows allowed / disabled (new key `orders.show.returnDisabled`, FR + AR) / expired from `status`; `Exchanges/Create.tsx` shows the expired message only when returns are enabled.
- No migration; no data touched; existing returns, refunds, stock and financial records unaffected.

## Tests (`tests/Feature/Returns/CustomerReturnWorkflowTest.php`)
- before / at / after the deadline in a non-UTC business timezone (Asia/Dubai, no DST), inclusive boundary, offset of the reported deadline.
- disabled returns with a future deadline report `disabled` (service, order page prop, search endpoint).
- backend refusal messages match the reported status (disabled vs expired).
