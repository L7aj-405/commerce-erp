# Incident 2026-11-19 — Reservation lost after an order correction (SO-000007)

Severity: HIGH (inventory). Found in Staging browser UAT. Fixed on branch `fix/uat-order-correction-reservation-loss`.

## 1. Symptom

Order SO-000007 (QA-ACC-010, 1 unit) was confirmed, fully paid, reserved/allocated for later delivery, and invoiced.
"Corriger la commande" was submitted without changing quantity, price or total. Afterwards the order was still
`confirmed` / `unfulfilled` / paid, but the reservation went from 1 to 0 (45 on hand, 0 reserved, 45 available). The audit
log showed the reservation **release** at correction start and no matching reservation afterwards.

## 2. Root cause (reproduced, not assumed)

The correction lifecycle is two steps:

1. `StartSalesOrderCorrectionAction` — freezes a revision snapshot, **releases** the active reservations
   (`InventoryReservationManager::release`), sets `inventory_reservation_id = null` on each allocation row, reopens the
   order as `draft`.
2. `ConfirmSalesOrderAction` (editor "Confirmer") — re-derives allocations and **reserves** again.

For a *manual* order, step 2 deletes and rebuilds the allocation rows (new ids), so the reservation reference is new
and everything works. For a **POS-origin order** (`source = pos`, not converted), step 2 deliberately keeps the
entry-time allocation rows (`$isManual` is false). The surviving allocation row keeps its id, so
`reserve(…, SalesOrderInventoryAllocation::class, <same id>)` hits the idempotency lookup in
`InventoryReservationManager::reserve()`:

```php
$existing = InventoryReservation::query()->where(... reference_type, reference_id ...)->first();
if ($existing) { …same quantity… return $existing; }   // status never checked
```

It returned the **Released** reservation from step 1. The allocation was re-linked to a dead reservation, the balance
`reserved` was never incremented, and no `inventory.reservation.created` audit row was written. The order was confirmed
with nothing held. (The unique index `inv_res_reference_unique` also forbids a second row for that reference, so the
lookup could never have created a fresh one.) The same flaw let the confirmed order's stock be sold again: in the real
concurrency run without the fix, the corrected order **and** a rival both "won" the single unit.

Why the QA order matched: "reserve and allocate one unit for later delivery" is the POS later-delivery path, i.e. a
POS-origin order. A normal back-office order is not affected.

## 3. Did Step 4 (F-03) introduce it? No.

* Step 4 (PR #31) changed only invoice issuance, credit-note creation/reception and opening positions — none of
  `ConfirmSalesOrderAction`, `StartSalesOrderCorrectionAction` or `InventoryReservationManager`.
* The status-blind idempotency lookup dates from `a10e6cd` (2026-08-24); the correction flow from the sales-order work
  of September–October 2026. The defect needs a POS-origin order **and** a correction, which is why it surfaced only in
  UAT.
* Verified empirically: the new regression tests fail on the unfixed code and pass with the fix (§5).

## 4. Fix (smallest reliable change)

* `ConfirmSalesOrderAction`: a line re-confirmed at the end of a correction (`$correcting`, revision `in_progress`) is
  always re-derived like a manual order: allocation rows are rebuilt (new ids), preferring the warehouse of the previous
  allocation, against **current** availability, under the same balance locks and in the same transaction as the status
  change.
* `InventoryReservationManager::reserve()`: only an **Active** reservation is an idempotent hit. A released/consumed one
  now raises a `ValidationException` instead of being returned as if stock were held (defense in depth: the failure mode
  was silent).

Behaviour per requirement:

| Requirement | Result |
|---|---|
| Unchanged correction | reservation restored: 1 active reservation, `reserved` = quantity |
| Quantity increase | new total reserved; if stock is not there, confirmation is refused and rolled back |
| Quantity decrease | only the new (smaller) quantity is reserved; excess stays released |
| No double reservation / negative available | `reserved == sum(active reservations) ≤ on_hand` asserted after every scenario |
| No unreserved confirmed order | refusal leaves the order `draft` with its revision `in_progress` — never confirmed-and-unreserved |
| No partial state | whole confirmation is one transaction (verified with a failing second line) |
| Isolation | reservation returns to its own warehouse; other organizations untouched |
| Audit | one `released` (start) + one `created` (completion) per cycle |

### Known residual design property (not changed)

Stock is released when a correction **starts** and re-reserved when it is **confirmed**. During that window another sale
can legitimately take the stock; the correction then fails safely at confirmation ("exceeds available stock") and the
order stays `draft`/`in_progress`, which the operator must resolve (free the stock, or cancel the order). Holding the
reservation for the whole correction (reserve only the delta) would remove the window but is a larger redesign of the
correction flow; it is **not** part of this fix and needs a product decision.

## 5. Regression coverage

`tests/Feature/Sales/SalesOrderCorrectionReservationTest.php` (15 cases; each scenario runs for manual **and**
POS-origin orders): no-op correction; no-op with paid order + issued invoice (HTTP routes; invoice and payment
untouched); quantity increase; decrease; insufficient stock + retry; rollback after a partial reservation; org/warehouse
isolation; three repeated corrections; reservation reconciliation (`reserved` vs active reservations vs `on_hand`, no
inventory movements) after each; direct test that `reserve()` refuses a released reference. 7 of them fail without the
fix.

`tests/Concurrency/order_correction_race.php` (MySQL, separate processes, CI step added): (A) the corrected order's
re-confirmation races N rivals for the last unit — exactly one winner, ledger reconciles, no unreserved confirmed order,
no deadlock; (B) N simultaneous corrections of one order — exactly one starts, reservation released once. Without the
fix scenario A fails (two winners for one unit, confirmed order with no reservation).

## 6. Invoice supersession for a no-op correction — implemented policy, not changed

After a correction completes the order page shows "new invoice version required" even if nothing changed.
`SalesOrderController::show` sets `pendingReplacementInvoice` whenever the current revision is `completed` and no
non-cancelled invoice is linked to that revision; `CreateFullInvoiceFromSalesOrderAction` likewise ties the replacement to
the revision. Nothing compares the before/after snapshots. So this is **the implemented policy (every completed
correction requires a replacement invoice version)**, consistent with the code comments and
`SalesOrderCorrectionAlignmentTest`, rather than an accident of this incident. Whether a *no-financial-change* correction
should be exempt is an accounting/product decision (it touches invoice numbering/versioning), so it was **not** changed.
The revision already stores `before_snapshot`/`after_snapshot`, so a future exemption could be derived from them.

## 7. Credit-note UAT preparation (Task D) — nothing here modifies existing staging records

### How the workflow works (code facts)

* A return needs an order that is `fulfilled` (`CreateCustomerReturnAction`), **and** an order with `pos_warehouse_id`
  (return location) — set only for orders created through **POS checkout**. A back-office order has none ("Aucun
  emplacement de retour…").
* Returns are **disabled by default** (`ReturnPolicyService`: `enabled = false`, 7-day window): enable them in Settings.
* A draft credit note is created **with the return**, only for lines that belong to an **issued** invoice of that order,
  dated **today** (business date). The credit note is issued, and stock moves, at **reception**
  (`ReceiveCustomerReturnAction`). Step 4 refuses both creation and reception when today's month is closed.
* A return of an order without an issued invoice creates no credit note and is not blocked by a closed month.

### Creating an eligible delivered QA sale (use a **new** sale — never touch SO-000007 or other existing records)

1. As an admin: **Politique de retour** in the settings navigation (`/return-policy`): enable returns (window 7 days; partial allowed).
   Settings are organization/store-wide; note the previous values and restore them afterwards.
2. **POS** (`/pos`): sell 1 unit of a QA product that has stock in the POS warehouse, pickup mode, no replenishment
   needed, pay in full with a QA cash/bank account. The sale is fulfilled on the spot (status *livrée*).
3. Open the new order → **Créer facture** (`POST sales/orders/{id}/invoices`) → **Émettre la facture** (the order is fully paid,
   so issuance is allowed). The invoice date is today.

### UAT A — credit note in an **open** month
1. Order page → **Retourner des articles** (`/sales/orders/{id}/returns/create`): pick the line, quantity 1, reason, disposition
   *restock* → Créer. Expect: return `draft`, a draft credit note dated today, no stock movement yet.
2. Return page → **Réceptionner le retour**. Expect: return `received`, credit note `issued` with a number, one stock movement
   (+1 on hand at the return warehouse).

### UAT B — closed month (do on a disposable QA organization/store, or a month you are allowed to close and reopen)
A credit note is dated **today**, so the *current* month must be closed. Closing is only accepted for an **ended** month
(`ManageFinanceMonthReviewAction`), so on a live calendar month this cannot be done from the UI until the month ends.
Safe options, in order of preference: (i) run the scenario on the disposable local/CI database — it is automated by
`ClosedPeriodDocumentGuardsTest` (credit-note creation and reception refusals); (ii) on staging, do it on the **first
days of the month after** a closed month only if a month-end close is genuinely planned, then **reopen**
(`finance/situation-fin-de-mois/reopen`) afterwards. Do not edit dates or database rows to force a closed month.
1. Finance → Situation fin de mois: close the month that contains today's business date (once it has ended).
2. Repeat UAT A step 1 on another eligible sale. Expect refusal "…est clôturée… réouvrez le mois", no return, no credit
   note, no stock movement.
3. To test **reception** refusal: create the return while the month is open, close the month, then **Réceptionner le retour**.
   Expect the same refusal; return stays `draft`.
4. Reopen the month and receive: expect success.

### UAT C — rollback of stock movements after rejection
In UAT B step 3 the rejected reception must leave: return `draft`, credit note `draft`, **no** new inventory movement,
on-hand and reserved unchanged (compare Inventory → product stock before and after). Reception is one database
transaction, and the closed-period check runs before the stock movement.

## 8. Pending decisions (unchanged from Step 4 and new)

* Credit note when the month is closed: refuse whole reception (current) vs receive stock and defer the credit note.
* No-op correction: exempt from a replacement invoice version? (§6).
* Hold the reservation through a correction (reserve only the delta) instead of release-then-rebuild? (§4 residual).

## 9. Validation results (runtime PHP 8.4.12 + timezonedb 2026.6, disposable databases only)

| Check | Result |
|---|---|
| New feature tests | 15/15 pass; 7 fail on the unfixed code |
| Full SQLite suite | 2362/2362, 18,133 assertions, 0 skipped (JUnit) |
| MySQL 8.4.3 (Finance, Payments, Documents, Sales, Pos, Tenancy, Sandbox, Returns, Exchanges, Mcp, Quotations, Inventory, Unit) | 1388/1388, 10,908 assertions, 0 skipped |
| `order_correction_race.php` (6 rivals x 3 rounds; also 4 x 3) | PASSED, 0 deadlocks; FAILS without the fix (two winners for one unit) |
| `closed_period_race.php` (F-03, regression) | PASSED, 0 deadlocks |
| Architecture / guard filter | 63/63 |
| Timezone runtime guard | OK (11 probes) |
| Pint on changed PHP files | pass |
| Frontend | not affected (no TS/TSX change) |

In the passing MySQL race runs a rival always won scenario A; the "corrected order wins" interleaving was not observed
with the fix (it is covered deterministically by the PHPUnit tests).
