# MCP-7 Sales Orders

Tools for AI clients to search, read, draft, update and explicitly confirm
Sales Orders. No invoice issuance, payment posting, cancellation or deletion is
reachable through MCP in this phase.

## Scopes

| scope | ERP permission | tools |
|---|---|---|
| `sales_orders.view` | `sales_orders.view` | `search_sales_orders`, `get_sales_order` |
| `sales_orders.create` | `sales_orders.create` | `preview_sales_order` (new), `create_sales_order_draft` |
| `sales_orders.update` | `sales_orders.update` | `preview_sales_order` (existing draft), `update_sales_order_draft` |
| `sales_orders.confirm` | `sales_orders.confirm` | `preview_sales_order_confirmation`, `confirm_sales_order` |

Effective authorization is unchanged: active membership ∩ current ERP permission
∩ connection scope. The organization comes from the connection; orders are
limited to active stores the connection user is a member of. Line-level ERP
permissions still apply inside the actions: a catalog price override needs
`sales_orders.override_price`, a discount needs `sales_orders.apply_discount`.
`inventory.view` adds informational stock to previews and allocations to reads.

## Reuse of ERP logic

Previews run the authoritative actions — `CreateSalesOrderAction`,
`UpdateSalesOrderAction`, `SaveSalesOrderLineAction`,
`RemoveSalesOrderLineAction`, `ConfirmSalesOrderAction` — inside a database
transaction that is always rolled back. Pricing, tax resolution, permission
gates, stock allocation and procurement checks are therefore exactly the ERP's;
nothing is re-implemented. (These actions have no non-database side effects.)
Execution runs the same steps for real and re-hashes the outcome against the
preview; any difference rolls the whole transaction back (`MCP_PREVIEW_STALE`).

The actions' tenant check (`MatchesActorTenant`) uses the MCP connection
instead of the browser's active organisation/store when an MCP context is bound;
store membership and permission checks are unchanged.

Header mapping: `order_date` → `sale_date`; `currency_code` = organization
currency on create, kept on update; salesperson defaults to the connection user.
The ERP Sales Order has no `terms` field.

## Fingerprints

Random 64-hex token in cache, `mcp.preview_ttl_seconds` (300 s), single use,
bound to organization, connection, mode (`create`/`update`/`confirm`), target id,
required scope, and:

- drafts: normalized payload hash, state hash (order + line versions, store,
  customer, variants/products), proposed-outcome hash (header, lines, totals);
- confirmation: the order + line version token.

## Example workflows

**1. "Find draft orders for customer X."**
`search_customers {query: "X"}` → `search_sales_orders {customer_id: 42, status: "draft"}`.

**2. "Create a draft Sales Order for customer X with 2 units of SKU Y."**
`search_products {query: "Y"}` → variant 917 →
`preview_sales_order {sales_order: {customer_id: 42, items: [{variant_id: 917, quantity: "2"}]}}` →
review lines/totals/issues →
`create_sales_order_draft {sales_order: <same object>, preview_fingerprint, idempotency_key: "so-42-y-2"}`.

**3. "Preview confirmation of Sales Order SO-123, then confirm it."**
`search_sales_orders {query: "SO-123"}` → id 311 →
`preview_sales_order_confirmation {sales_order_id: 311}` → check `issues` and
`reservation_plan` →
`confirm_sales_order {sales_order_id: 311, preview_fingerprint}`.
The order is confirmed and stock reserved; no invoice or payment is created.

## Idempotency

- `create_sales_order_draft`: persistent `McpIdempotencyService` — same key +
  same payload replays the stored compact result; same key + different payload
  → `MCP_IDEMPOTENCY_CONFLICT`.
- `confirm_sales_order`: the ERP confirms a draft once; a retry with the same
  fingerprint after success returns the confirmed order (`replay: true`).
- `update_sales_order_draft`: single-use fingerprint; preview again to retry.
