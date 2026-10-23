# MCP tool: `get_purchase_cost_coverage`

Read-only reporting tool answering purchase-cost completeness questions for
in-stock variants in one call, instead of looping over `search_products` and
`get_product_stock`.

Example question an AI client should route here:

> "Show me all products currently available in stock that are missing a purchase price."

→ `get_purchase_cost_coverage { "purchase_cost_status": "without_cost" }`

## Authorization

Requires all of `products.view`, `inventory.view` and `product_cost.view`
(connection scope ∩ current ERP permission of the active member). No new scope.
The organization comes from the MCP connection only. The tool is listed in
`tools/list` only when all three are allowed.

## Semantics

- Variant level: stock and `purchase_price` are variant attributes.
- Stock = organization-wide sum of `inventory_balances` across all warehouses,
  `available = on_hand − reserved` — the same totals `get_product_stock` reports.
- "In stock": `available > 0` (default) or `on_hand > 0` with
  `stock_basis: "on_hand"`.
- `purchase_cost: null` = missing. An explicit `0.0000` is a recorded cost.
- `summary` is computed over **all** matching in-stock variants (after
  `status`/`query`, before `purchase_cost_status` and pagination);
  `coverage_percent` = with / total × 100, two decimals, `null` when total is 0.
- Items are ordered by product name, product id, variant id. Decimals are strings.

## Inputs

| field | type | default |
|---|---|---|
| `stock_basis` | `available` \| `on_hand` | `available` |
| `status` | `active` \| `inactive` (variant status) | — |
| `purchase_cost_status` | `all` \| `with_cost` \| `without_cost` | `all` |
| `query` | string ≤ 255 — product name, SKU, reference, barcode | — |
| `page` / `per_page` | ≥ 1 / 1–100 | 1 / 25 |

## Query cost

Constant per call: one aggregate (summary), the paginator's count plus one
page query, and one balance lookup for the page's stock breakdown. No
per-variant queries.
Audit: `mcp.purchase_cost_coverage.viewed` (filters and counts only).
