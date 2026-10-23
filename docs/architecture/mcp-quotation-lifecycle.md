# MCP quotation lifecycle: issue → accept → convert

| scope (= ERP permission) | tools |
|---|---|
| `quotations.issue` | `preview_quotation_issue`, `issue_quotation` |
| `quotations.accept` | `preview_quotation_acceptance`, `accept_quotation` |
| `quotations.convert` | `preview_quotation_conversion`, `convert_quotation_to_sales_order` |

Effective authorization: active membership ∩ current ERP permission ∩ scope,
organization from the connection, quotation limited to the user's stores.
Conversion runs the ERP's `CreateSalesOrderAction` / `SaveSalesOrderLineAction`,
so the ERP additionally requires `sales_orders.create` / `sales_orders.update`
(and `sales_orders.override_price` / `apply_discount` when the quotation's prices
differ from the catalog or carry discounts) — reported as a `not_permitted` issue.

## Reuse

Each transition is the authoritative action: `IssueQuotationAction`,
`DecideQuotationAction::accept`, `ConvertQuotationToSalesOrderAction` (called
exactly like the web conversion, default warehouse). Previews run the action in
a transaction that is always rolled back (`McpDryRun`), so eligibility and the
conversion result are the ERP's own. Conversion produces a DRAFT Sales Order;
it is never confirmed here, no stock is reserved, no invoice or payment exists.

## Fingerprints / staleness

Random, cache-stored, `mcp.preview_ttl_seconds`, single use; bound to
organization, connection, transition, quotation id, required scope and a state
hash (quotation + line versions; for conversion also store, customer, variants,
products and non-stock items). Conversion additionally binds the hash of the
previewed Sales Order (customer, store, currency, lines, prices, taxes,
discounts, totals — not its id/number); execution re-runs the action and rolls
back on any difference (`MCP_PREVIEW_STALE`).

## Duplicate protection

- `convert_quotation_to_sales_order` requires an idempotency key: same key +
  same quotation replays the stored result; same key + other quotation →
  `MCP_IDEMPOTENCY_CONFLICT`.
- A quotation that already has `converted_sales_order_id` is never converted
  again: preview reports `already_converted` with the existing order, execution
  returns `MCP_QUOTATION_ALREADY_CONVERTED` with its id (the ERP action's own
  guard stays in place as well).
- `issue_quotation` / `accept_quotation`: a retry with the same fingerprint after
  success returns the quotation (`replay: true`).

## Example

`get_quotation {quotation_id: 23}` → `preview_quotation_issue` → `issue_quotation`
→ `preview_quotation_acceptance` → `accept_quotation` →
`preview_quotation_conversion` (check `sales_order_preview`, `stock_warnings`) →
`convert_quotation_to_sales_order {quotation_id: 23, preview_fingerprint, idempotency_key}`.
