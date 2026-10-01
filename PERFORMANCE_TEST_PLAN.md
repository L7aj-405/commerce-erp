# 10xScale ERP staging performance test plan

Run this plan only against an isolated staging environment containing synthetic
data. Never point load tools at production. Use production-equivalent PHP,
MySQL, queue-worker, storage and Dompdf configuration, with `APP_DEBUG=false`.

## Dataset profiles

- Baseline: 10 users, 2 stores, 5 warehouses, 10,000 products/variants, 20,000
  inventory balances, 25,000 orders, 25,000 payments and 20,000 issued invoices.
- Large: 50,000 products/variants, 250,000 inventory movements, 100,000 orders,
  100,000 payments and 75,000 issued documents.
- Include partial payments, returns/avoirs, multi-warehouse stock, multi-page
  PDFs and WooCommerce variable products. Keep at least two organizations to
  verify that optimization never weakens tenant isolation.

## Scenarios

Measure both cold and warm runs for:

1. Dashboard and Finance monthly views (organization-wide and store-filtered).
2. Product, contact, order, payment, invoice and inventory-movement searches,
   including late pagination pages and common status/date filters.
3. POS product lookup, cart confirmation and two simultaneous reservations for
   the final available quantity; correctness and lock contention are both gates.
4. Official Invoice/Devis/BL/Avoir PDF download, repeated download, public
   signed-link download and a 12+/multi-page document.
5. PDF Studio rapid edits for 60 seconds; confirm debounce, cancellation,
   throttling, memory stability and no cross-organization preview reuse.
6. Finance XLSX/PDF, invoice ZIP and full package at 10, 100 and the configured
   500-document synchronous ceiling; verify rejection above the ceiling.
7. Full and incremental WooCommerce synchronization with simple and variable
   products, category reuse, retries, API pagination and aggregate stock.
8. Manual/scheduled backup, validation and cloud copy near configured archive
   limits; restore only into a disposable staging organization.

## Workload and metrics

- Ramp 1, 5, 10 and 25 concurrent authenticated users; sustain each level for
  at least 10 minutes after warm-up. Keep POS/inventory mutation concurrency
  separate from read-only browsing so lock waits remain attributable.
- Record p50, p95 and p99 latency; throughput; HTTP error rate; PHP CPU and peak
  memory; MySQL query count, total query time, slowest queries, rows examined,
  lock waits/deadlocks and connection saturation; queue duration/retries,
  backlog age and failed jobs; PDF render duration/peak memory; export duration,
  temporary disk high-water mark and cleanup success.
- Capture `EXPLAIN ANALYZE` for slow Finance date/status queries before and after
  applying the reporting-index migration. Confirm the intended composite index
  is selected rather than assuming an index helped.

## Acceptance gates

- No tenant data leakage, duplicate mutation, negative stock, changed totals or
  altered issued-document snapshot under any load.
- Zero unexpected 5xx responses and zero stuck/duplicated queue jobs.
- Define latency and capacity thresholds from an agreed staging baseline; this
  repository audit did not run benchmarks and intentionally claims no numbers.
- Preserve raw reports, application revision, dataset seed, environment sizing
  and query plans so later runs are comparable.
