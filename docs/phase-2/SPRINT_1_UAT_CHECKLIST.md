# Phase 2 Sprint 1 — Supplier Invoices: browser UAT checklist

**Status of the new UI before this checklist: type-checked and built, but never exercised in a browser against the real backend.**
Treat every item below as unverified until ticked with a date/initials.

## Preconditions
- Staging only, organization in **test fiscal mode** (never a protected/real organization, never production).
- `SUPPLIER_INVOICES_ENABLED=true` for the UAT window (see runbook §5). Before enabling, confirm the OFF state (item A0).
- Accounts (create through the normal member/role screens; each must hold membership of the stores noted):
  | Account | Role / permissions | Store membership |
  |---|---|---|
  | `finance` | `supplier_invoices.view/manage/approve/void`, `finance.monthly_situation.manage`, `finance.outgoing_payments.manage` | Store A and Store B |
  | `buyer` | `supplier_invoices.view/manage` only (e.g. procurement) | Store A |
  | `viewer` | `supplier_invoices.view` only | Store A |
  | `nostore` | same as `finance` | **no** store membership |
  | `owner` | Owner | **no** membership of Store B (to prove no bypass) |
- Reference data: at least 2 stores, 2 active suppliers (one with ICE/IF/RC filled, one without), 2 active tax rates (e.g. 20 % and 0 %),
  1 active financial account in the invoice currency, 1 purchase order (ordered) for a supplier in Store A, a few active variants.
- Test files: one PDF, one PNG, one oversize file (> 10 MB), one `.php` renamed to `.pdf`.
- Browsers: desktop Chrome + one phone-sized viewport (DevTools device mode ≥ 360 px wide) and, if possible, a real phone.

Record, for every failure: URL, account, steps, expected vs actual, screenshot, console/network error.

## A. Feature flag and navigation
- [ ] A0 (flag OFF, before enabling): menu has **no** "Factures fournisseurs"; direct URL `/finance/factures-fournisseurs` → 404 for `finance`.
- [ ] A1 (flag ON): menu shows the entry (Finance group) for `finance`, `buyer`, `viewer`; not for a user without `supplier_invoices.view`.
- [ ] A2 In **Test Mode** (enter it as `finance`): the entry is hidden; direct URL redirects to `/test-mode`; the Test Mode page lists
      "Factures fournisseurs … (Production uniquement)"; a POST to the routes is refused (403 JSON `SANDBOX_DOMAIN_UNAVAILABLE`).

## B. Supplier fiscal fields
- [ ] B1 Create/edit a supplier with ICE, IF, RC, payment term (days), currency, IBAN. Values persist after reload.
- [ ] B2 Wrong-format ICE/IF shows an **advisory** warning and still saves. A second supplier with the same ICE shows the duplicate hint, still saves.
- [ ] B3 The IBAN is shown **masked** (`****1234`); the full value is never prefilled; saving without touching it keeps the stored IBAN.
- [ ] B4 Users without `suppliers.manage` do not see the full IBAN.

## C. Invoice creation and editing
- [ ] C1 `buyer` creates a draft (store A, supplier, number, date, currency). Draft appears in the list with status "Brouillon".
- [ ] C2 Store selection only offers stores the user belongs to; the store cannot be changed after creation.
- [ ] C3 Edit the draft: change a field, save; a second browser tab editing the same draft then saving shows the **stale version** message.
- [ ] C4 Double-click "Enregistrer": only one draft is created (idempotent).
- [ ] C5 Duplicate: the same supplier + number + year is rejected; the same number for another supplier, or another year, is allowed (soft warning).
- [ ] C6 `viewer` can open the list/detail but sees no create/edit/approve/void buttons; direct POST URLs are denied.

## D. Lines and VAT
- [ ] D1 Add several lines (stock + charge). Stock line without a PO: the variant picker searches (≥ 2 chars), shows label + SKU, is keyboard operable.
- [ ] D2 Per-line VAT: 20 % and 0 % lines; the live totals match the server totals after saving (HT, TVA, TTC), including a x.xx5 rounding case.
- [ ] D3 Rounding adjustment within tolerance is accepted and shown; beyond it is rejected.
- [ ] D4 PO-linked line: choosing the PO pre-fills supplier/store/currency rules; a PO of another supplier/store is not offered.
- [ ] D5 No cost/purchase price is displayed by the variant search.

## E. Installment schedules
- [ ] E1 One installment equal to the total; then three installments with different due dates summing exactly to the total.
- [ ] E2 Sum ≠ total is blocked with a clear message; due date before the invoice date is rejected.
- [ ] E3 No schedule entered + supplier payment term set → default schedule offered/applied at approval; no term → an explicit error asks for a due date.

## F. Approval and matching warnings (as `finance`)
- [ ] F1 Approve requires the printed total (control total); a wrong value is refused with the field error.
- [ ] F2 Invoice **without** PO: matching shows "Sans commande fournisseur"; approval requires the acknowledgement + a note.
- [ ] F3 PO-linked invoice: matching shows partial/matched/variance (over-invoiced, not received, price missing) correctly; warnings are visible, not blocking.
- [ ] F4 Soft duplicate warnings must each be ticked to approve.
- [ ] F5 Approve twice quickly (two tabs): exactly one set of obligations exists afterwards.
- [ ] F6 Approval in a **closed month** (invoice date or a due date) is refused with the period message.
- [ ] F7 After approval the invoice is read-only (no edit); the audit/approval block shows who/when.

## G. Finance obligations
- [ ] G1 Situation fin de mois shows one obligation per installment (category Fournisseur, supplier, due date, amount) with a link to the invoice.
- [ ] G2 Edit/cancel of those obligations is **disabled** with the lock indicator; legacy obligations remain editable.
- [ ] G3 Treasury forecast / supplier summary include the future installments; the totals equal the invoice TTC.
- [ ] G4 `php artisan finance:reconcile` → R-8, R-9, R-10 pass.

## H. Partial payment and reversal
- [ ] H1 Pay part of the first installment through the existing outgoing-payment dialog: remaining decreases, status "Partiellement payée".
- [ ] H2 Pay the rest: status "Payée"; the invoice detail shows the obligation paid/remaining.
- [ ] H3 Reverse the payment (reason required): remaining is restored; reversed payment still visible in history.
- [ ] H4 Paying more than the remaining amount is refused.
- [ ] H5 Account balance changes only by posted, non-reversed payments.

## I. Void restrictions
- [ ] I1 Void a **draft** (reason code + text): the number can be re-entered afterwards.
- [ ] I2 Void an approved, **unpaid** invoice: all its obligations become cancelled; they disappear from the forecast.
- [ ] I3 Void is **refused** while a posted payment exists (message says reverse payments first) and while a cheque/traite is issued/presented.
- [ ] I4 Void after reversing the payment succeeds; the reversed payment and allocation remain visible.
- [ ] I5 The void dialog states that supplier credit notes/returns/rebates are **not** a void reason.
- [ ] I6 Void in a closed period is refused.

## J. Attachments
- [ ] J1 Upload the PDF and the PNG to a draft and to an approved invoice; both appear in the list.
- [ ] J2 Download/open each through its link; a URL pasted by a user of another store/organization is denied.
- [ ] J3 The oversize file, and the `.php` renamed `.pdf`, are rejected with a clear message.
- [ ] J4 Uploading the same file twice to one invoice is refused; the same file on another invoice shows a soft warning.
- [ ] J5 Remove works on a draft only; a void invoice accepts no new attachment.

## K. French and Arabic
- [ ] K1 Switch to French: every new screen/label/message/dialog is translated, no raw keys (`supplierInvoice.…`) visible.
- [ ] K2 Switch to Arabic: same; layout is right-to-left and not broken (tables, dialogs, installment editor, totals, badges, icons).
- [ ] K3 Amounts/dates are formatted consistently in both languages.

## L. Mobile / responsive (≥ 360 px)
- [ ] L1 List, detail and form are usable without horizontal page scroll; the line editor and installment editor remain operable.
- [ ] L2 Dialogs (approve/void) fit the screen; buttons reachable; keyboard/focus order sensible; contrast acceptable in light and dark.

## M. Store permissions and Test Mode isolation
- [ ] M1 `nostore`: sees no invoices, cannot approve/void even with the permission (403).
- [ ] M2 `owner` without membership of Store B: cannot see/approve/void Store B invoices (**no bypass**).
- [ ] M3 `buyer` (Store A only) cannot open a Store B invoice by URL, and cannot approve/void (no permission).
- [ ] M4 Cross-organization: an invoice id from another organization returns 404.
- [ ] M5 Deactivate Store A: members can still view and void historic invoices but cannot create/edit/approve there.
- [ ] M6 Test Mode never exposes or creates supplier invoices (see A2), and Production data is unaffected by Test Mode activity.

## Sign-off
Tester / date / environment / commit SHA / browser versions / open defects. The feature must stay disabled on staging until every
item is ticked or its defect is accepted in writing, and the accountant has reviewed the open accounting/legal questions listed in the PR.
