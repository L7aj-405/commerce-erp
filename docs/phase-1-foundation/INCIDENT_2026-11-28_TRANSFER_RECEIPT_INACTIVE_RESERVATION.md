# Incident 2026-11-28 — Transfer request receipt refused (TRQ-000030 / SO-000073)

Severity: P1. Release in production: `a8a1707`. Branch: `hotfix/transfer-receipt-inactive-reservation` (from main).
No production data was changed.
Repository is public: business figures are deliberately omitted.

## 1. Symptom

Receiving TRQ-000030 returns `reservation: Only an active reservation can be increased.` The receipt dialog shows nothing.

## 2. Observed data (read-only ERP connector, 2026-11-28)

* SO-000073: POS, confirmed, unfulfilled. One line, 3 × variant 133.
* Allocations: Showroom 2 and depot 1, **both reservations `released`**; nothing reserved at either warehouse.
* Created 2026-11-27 12:08 UTC, `confirmed_at` 13:16 UTC. The release merge `a8a1707` is stamped 13:54 UTC.

## 3. Root cause

Two defects.

1. **How the state arose** — the order-correction defect of
   [2026-11-19](INCIDENT_2026-11-19_ORDER_CORRECTION_RESERVATION_LOSS.md). Starting a correction releases the
   reservations; for a POS-origin order the old re-confirmation kept the allocation rows, and `reserve()` handed the
   released reservation back as an idempotent hit. The fix (`28f1432`) was **not** in the previous production main
   (`5285c6c`); it reached production only with `a8a1707`. Upgrading did not repair orders already affected.
2. **Why receipt fails** — `ReceiveTransferRequestAction` skipped an inactive source reservation but called
   `increase()` on the destination reservation whatever its status. Unchanged from `5285c6c` to `a8a1707`.

### Evidence level

* Reproduced locally: with `ConfirmSalesOrderAction` and `InventoryReservationManager` from `5285c6c`, a no-op
  correction of a 2 + 1 POS order leaves both reservations released, 0 reserved, and receipt fails with the exact
  production message. The same sequence on `a8a1707` code keeps both active and receives.
* For SO-000073 itself: the data matches and `confirmed_at` precedes the release. **Still to confirm** from its audit
  trail (query 4 of `scripts/incidents/transfer-receipt-reservations.mysql`): `correction_started`, two
  `reservation.released`, `correction_completed`, no `reservation.created` in between. The merge time is not the
  deployment time; check the deployment log too.

### Other lifecycles reviewed

Cancellation releases and cancels the order; correction releases and returns the order to draft; POS draft discard
applies to drafts; manual release/consume refuses sales-allocation references; selective reset deletes the rows.
A **consumed** reservation on an allocation is legitimate after a partial shipment, and the old receipt code failed on it
too.

## 4. Fix

`App\Services\Inventory\TransferReceiptEarmarker`, called by the receipt inside its transaction.

| State of an allocation on a confirmed order | Receipt behaviour |
|---|---|
| Active | source released (or decreased by the travelling quantity); destination increased |
| Released, missing or unlinked, goods still to ship | **new** reservation on a replaced allocation row, against free stock; audit `transfer_request.reservation_restored` |
| Consumed (shipped) | left as history; only the arriving quantity gets a new hold |
| Nothing left to ship | quantity lands as free stock |

Also: never holds more than is still to ship; only the transferred customer quantity leaves the source; refuses when the
customer quantity cannot be traced to source allocations, when the order is not confirmed, or when an active
reservation disagrees with its allocation. The order is locked before the request, and balances are locked in
confirmation order. Released and consumed reservations are never modified. No migration.

**Approved business rule (2026-11-28):** the receipt re-establishes a lost hold, including the destination units that did not travel
(2 of the 3 here). Without this the order stays under-reserved and cannot be shipped, and correction is closed once a
transfer has shipped. If the stock is no longer free, the whole receipt is refused with a message and can be retried.

UI: refusals appear inside the dialog, which stays open; success keeps the toast. Server messages are in
`lang/fr` and `lang/en` and follow `APP_LOCALE`; dialog headings are French and Arabic (the UI has no English locale).

## 5. Affected data

Through the connector (limited to the stores its user belongs to), the confirmed unfulfilled orders it returned show one
affected order, SO-000073. This is **not** a database-wide result, and does not cover draft or cancelled orders,
fulfilled orders that lost a hold before shipping, or balances. Run the read-only script for all organizations.

## 6. Recovery of TRQ-000030

No SQL repair. Until recovery, SO-000073 holds **nothing**: its units can be sold or transferred by others.

1. Before deployment: run the script read-only; save the output. Confirm section 4, note every line of TRQ-000030
   (section 5; it may carry replenishment besides the customer unit), and check that the goods are physically there.
2. Check `APP_LOCALE=fr` in production, take the usual backup, deploy the approved hotfix (web and worker).
3. Preconditions: depot available ≥ total of TRQ-000030; Showroom available + that total ≥ 3.
4. A user with `inventory.transfer_requests.receive` clicks **Réceptionner** once.
5. Verify (T = total units of TRQ-000030, compared with the step 1 output): status `received`, one stock transfer;
   Showroom on hand +T, reserved +3; depot on hand −T, reserved unchanged; SO-000073 one Showroom allocation of 3, active; two older reservations still
   `released`; two `reservation_restored` audit events. Then hand the order over normally.
6. If refused: nothing changed. Resolve the cause named in the dialog and retry.

For any other affected order: with a shipped customer transfer, receiving it repairs the transferred lines. Orders
**without** one are not repaired by this hotfix: a commercial correction on the fixed code re-reserves them when
correction is allowed (it replaces the invoice version); otherwise decide case by case.

## 7. Known, not changed

* The order read models report such allocations as reserved (`reserved: true`, `already_reserved_for_this_order`)
  because they test for a reservation id, not its status. This hid the problem; fix separately.
* Cancelling an affected order is refused ("Every catalog allocation must have an active reservation").
