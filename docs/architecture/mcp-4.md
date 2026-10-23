# MCP-4 product bulk operations

Bulk operations reuse the MCP-3 single-record semantics: every row is evaluated
by `McpProductChangeService::evaluate()`, which applies the same field
allow-lists, `CatalogProductDataValidator`, and WooCommerce ownership rules as
`preview_product_changes`. Execution applies the authoritative catalog Actions
(`UpdateProductAction`, `UpdateVariantAction`, `UpdateProductPurchasePriceAction`,
`ArchiveProductAction`) one item per transaction on the queue.

## Lifecycle

`validating → previewed → queued → running → completed | partial | failed`,
with `expired` for a preview that timed out or went stale, and `failed` for an
operation whose validation could not complete.

1. `prepare_*` stores the operation and its rows (idempotency key required).
   Up to `sync_validation_max_items` rows are validated inline; larger
   operations validate on the queue and the client polls `get_product_operation`.
2. Validation resolves each row inside the connection organization, rejects
   duplicate targets and in-request identifier collisions, and records per-row
   `preview_status` (changed, unchanged, skipped, invalid, conflict) plus the
   target version (`updated_at`) and state hash. Nothing is mutated.
3. The preview fingerprint is an HMAC over the operation id, the preview hash,
   and the expiry. The preview hash binds organization, connection, operation,
   type, required scopes, and every ready item's target, proposed-value hash,
   version, and state hash.
4. `execute_product_operation` rechecks scopes and permissions, verifies the
   fingerprint and preview hash, and rejects the whole operation as stale if
   any ready target's version changed. It then marks ready items pending and
   dispatches chunk jobs after commit. Repeating with the same fingerprint
   returns the current status.
5. Each chunk job re-binds the connection (same checks as the HTTP
   middleware), rechecks scopes, and processes items independently. Each item
   re-evaluates its target under a row lock and fails as `stale_target` if
   anything changed since the preview.

## Retry

Only database concurrency and lost-connection errors are retried (bounded by
`max_item_attempts`, with queue backoff). Validation, authorization, stale,
uniqueness, Woo ownership, and other business failures are recorded on the item
and never retried. Item completion is written in the same transaction as the
mutation, so completed items are never applied twice.

## Retention

`mcp:prune-operations` (daily) expires stale previews and, after
`retention_days`, deletes per-row item records. The operation summary row
(type, counters, attribution snapshot, timestamps) is kept, together with the
operation-level `mcp.product_operation_*` audit events and the per-record audit
events written by the catalog Actions.
