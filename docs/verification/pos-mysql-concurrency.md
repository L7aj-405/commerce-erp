# POS concurrency — MySQL staging verification

The automated suite runs on SQLite, which ignores `SELECT … FOR UPDATE`. The
row-lock behaviour below is therefore verified **on MySQL (staging)** before
each release that touches POS carts, checkout or inventory allocation.

What the code guarantees (and what to confirm):

| Path | Lock / guard |
|---|---|
| Add a line (`PosController::storeDraftLine`) | `sales_orders` row of the cart locked first, then find-or-increment of the line |
| Finalize (`CreatePosSaleAction::completeDraft`) | cart row lock → `PosCartStockGuard` locks every `inventory_balances` row of the cart's articles → re-check free stock → reserve; any shortage throws `PosStockConflictException` and the whole transaction rolls back |
| Idempotency | `sales_orders.client_operation_id` (unique per store) |
| Terminal carts | each browser has its own active cart (`sales_orders.pos_terminal_id`, cookie `pos_terminal`) |

## Setup

```bash
php artisan migrate --force                      # staging, MySQL 8
# Organisation with one Store, location "Showroom · MAIN", product X with on_hand = 1
```

Two independent browsers (or one normal + one private window): **Terminal A**
and **Terminal B**, same user or two users, same Store, POS open on MAIN.

## Scenario 1 — independent carts, concurrent adds

1. A and B each add product X.
2. Expected: two different carts (different order numbers in the cart header);
   each cart has ONE line `X × 1`.
3. In one terminal open a second tab and click "+" on X in both tabs at the same
   moment. Expected: one line `X × 3` (never two lines of X).

SQL check:

```sql
SELECT sales_order_id, product_variant_id, COUNT(*) lines, SUM(quantity) qty
FROM sales_order_lines WHERE product_variant_id = :x
GROUP BY sales_order_id, product_variant_id;      -- every row: lines = 1
```

## Scenario 2 — two sales of the last unit

1. Carts A and B both contain `X × 1`; on_hand = 1, reserved = 0.
2. Click **Valider** in both terminals as simultaneously as possible (or run the
   script below).
3. Expected:
   - exactly one sale is confirmed (and fulfilled for a pickup);
   - the other terminal shows « Le stock a changé depuis l’ouverture du panier »
     with `X — demandé 1 · disponible 0 · manquant 1`, and its cart is intact;
   - the loser has no payment, no reservation, no stock movement.

```sql
SELECT on_hand, reserved FROM inventory_balances WHERE product_variant_id = :x;   -- 0,0 (pickup) or 1,1 (delivery)
SELECT status, COUNT(*) FROM sales_orders WHERE id IN (:a, :b) GROUP BY status;   -- confirmed 1, draft 1
SELECT COUNT(*) FROM payments WHERE client_operation_id = :loser_operation;      -- 0
SELECT COUNT(*) FROM inventory_reservations WHERE reference_id IN (
  SELECT a.id FROM sales_order_inventory_allocations a
  JOIN sales_order_lines l ON l.id = a.sales_order_line_id WHERE l.sales_order_id = :loser); -- 0
```

Scripted race (from two shells, same second):

```bash
php artisan tinker --execute='app(App\Actions\Pos\CreatePosSaleAction::class)->execute(
  App\Models\User::find(USER_ID), App\Models\Organization::find(ORG_ID), App\Models\Store::find(STORE_ID),
  ["order_id" => CART_ID, "client_operation_id" => (string) Str::uuid(), "fulfillment_mode" => "pickup", "payments" => []]);'
```

Run it for cart A in shell 1 and cart B in shell 2. Exactly one command
succeeds; the other throws `PosStockConflictException` (a 422 in the UI).

## Scenario 3 — inventory allocation never duplicated

With on_hand MAIN = 1, depot ESP = 1 and one cart `X × 2`: finalize. Expected
allocations MAIN 1 + ESP 1 (one reservation per warehouse), never 2 on MAIN.

```sql
SELECT warehouse_id, SUM(quantity) FROM sales_order_inventory_allocations
WHERE sales_order_line_id = :line GROUP BY warehouse_id;
```

Record the result (date, build, pass/fail) in the release notes.
