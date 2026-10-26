# MCP W6 — Work Center, Attention, Team and Timeline tools

Status: implemented (W6). Tool exposure only: no automation, no polling, no
outbound messages, no document mutation.

## Effective access

`plan entitlement (future) ∩ MCP scope ∩ current ERP permission ∩ tenant/store`.

- The organization is the connection's server-side binding; tools never take
  an `organization_id`.
- `McpExecutionContext::allows/authorize` checks the scope **and** the user's
  ERP permission at every call (a revoked permission or suspended membership
  takes effect immediately).
- The canonical services then filter every record by the user's own
  visibility (follow-up assignment, document view permission, **active**
  member stores). A scope never widens this.
- `WorkCenterToolRegistrar` is the single registration point a future plan
  entitlement can filter.

## Scopes

| Scope | ERP requirement | Tools |
|---|---|---|
| `follow_ups.view` | `follow_ups.view` | `get_my_work` |
| `follow_ups.create` | `follow_ups.create` | `create_follow_up` (+ `attention.view`: `create_follow_up_from_attention`) |
| `follow_ups.update` | `follow_ups.update` | `update_follow_up`, `transition_follow_up` |
| `follow_ups.manage_all` | `follow_ups.manage_all` | `get_team_work`, `get_team_member_work`; also required to assign work/attention to someone else, and for `get_attention` `assigned=all\|{id}` |
| `attention.view` | any of `quotations.view`, `invoices.view`, `sales_orders.view` | `get_attention`, `explain_attention` |
| `attention.update` | same | `claim_attention`, `snooze_attention`, `dismiss_attention` (+ team scope: `assign_attention`) |
| `timeline.view` | any of `customers.view`, `quotations.view`, `sales_orders.view`, `invoices.view` | `get_business_timeline` |

`any_permission` scopes exist because attention and timelines span document
kinds; `App\Services\Mcp\McpScopes` is the only reader of `config('mcp.scopes')`
(context, connection settings, ceiling check, OAuth consent).

## Canonical reuse

| Tool | Service |
|---|---|
| `get_my_work`, `get_team_member_work` | `FollowUpQuery::search/counts/present` (canonical buckets, org timezone) |
| `create_follow_up`, `update_follow_up` | `SaveFollowUpAction` (store/customer derivation, link + assignee rules, due parsing, reminders, audit) |
| `transition_follow_up` | `TransitionFollowUpAction` |
| `get_team_work` | `TeamWorkQuery` (internal `tier` is stripped: no score) |
| `get_attention`, `explain_attention` | `AttentionQuery::search/present/explain` (explain = the detector's stored facts, never re-derived) |
| attention actions + bridge | `HandleAttentionItemAction` (the bridge takes the document from the item; it never resolves the item) |
| `get_business_timeline` | `BusinessTimeline` (DocumentVisibility universe, gated amounts/emails, stable cursor); web navigation links are dropped |
| `get_customer_balance_summary` | `CustomerFinancialSummary` (W5.1): store-scoped; `null` = unavailable, `"0.0000"` = visible zero |

## Idempotency and concurrency

- Creates (`create_follow_up`, `create_follow_up_from_attention`) require an
  `idempotency_key` and run through the existing `McpIdempotencyService`
  (per connection + tool + key, request hash, DB record, cache lock): a retry
  replays the stored response, a different payload with the same key is
  `MCP_IDEMPOTENCY_CONFLICT`. Web semantics are unchanged (no
  `client_operation_id` column was added to `follow_ups`; see gap #16).
- Every update/transition/attention action requires `expected_version`; the
  canonical actions check it under a row lock. A stale version returns
  `MCP_STALE_VERSION` — never last-write-wins. An ended item/closed follow-up
  returns `MCP_INVALID_STATE`.

## Errors

`MCP_FORBIDDEN`, `MCP_*_NOT_FOUND` (also for records outside the user's
visibility — existence is never revealed), `MCP_VALIDATION_FAILED`,
`MCP_STALE_VERSION`, `MCP_INVALID_STATE`, `MCP_IDEMPOTENCY_CONFLICT`,
`MCP_IDEMPOTENCY_BUSY`, `MCP_INVALID_ARGUMENTS`, `MCP_TOOL_INTERNAL_ERROR`.

## Audit

Writes are audited by the canonical actions; `AuditLogger` adds
`actor_channel = mcp` and the connection id/name, keeping the ERP user as the
actor (timelines show "via l'assistant"). Reads emit `mcp.work.viewed`,
`mcp.team.viewed`, `mcp.team_member.viewed`, `mcp.attention.viewed`,
`mcp.attention.explained`, `mcp.timeline.viewed`.

## Hardening shipped with W6

`FollowUpAccess::storeIds/hasStoreAccess` count only **active** member stores
(same rule as `DocumentVisibility`, `ActiveTenantContext`, the opener): work
and attention of a deactivated store are unreachable on every transport.
W6.1 applies the same rule to `AttentionOwnership` (owner validity, ownership
repair, fallback manager, escalation/alert recipients, claim and manual
assignment, assignment picker): an inactive store qualifies nobody, so its
active items are released and stay unassigned; resolved/dismissed items keep
their historical owner.
