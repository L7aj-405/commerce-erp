<?php

namespace App\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Store;
use App\Support\Decimal;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-side "is this invoice paid?" derivation. There is no paid_amount or
 * fully_paid_at column on `invoices` (deliberately not added for V1 — see
 * the Finance V1 audit). Every method here works over an already-bounded
 * batch (one page, one month, one explicit selection) and enriches it with
 * a small fixed set of aggregate queries — never one query per
 * invoice/order (that would be the N+1 SalesOrderPaymentCalculator::paidAmount()
 * pattern this whole read model exists to avoid).
 */
class FinanceInvoiceReadModel
{
    public function __construct(private readonly FinanceReceivablesService $receivables) {}

    /** Paginated "current effective" issued invoices for a period (Facturation drill-down). */
    public function issuedDuring(Organization $organization, FinancePeriod $period, ?Store $store, int $perPage = 30): LengthAwarePaginator
    {
        $paginator = $this->baseQuery($organization, $store)
            ->where('status', InvoiceStatus::Issued->value)
            ->whereDate('invoice_date', '>=', $period->start->toDateString())
            ->whereDate('invoice_date', '<=', $period->end->toDateString())
            ->orderBy('invoice_date')->orderBy('id')
            ->paginate($perPage)->withQueryString();

        // Batch-enrich the page's invoices in 3 queries total BEFORE mapping —
        // through() maps row-by-row, so building each row's summary lazily
        // inside that closure (one lookup per invoice) would be exactly the
        // N+1 pattern this read model exists to avoid.
        $enrichedById = $this->withPaymentSummaries($paginator->getCollection())->keyBy('id');

        return $paginator->through(fn (Invoice $invoice) => $enrichedById[$invoice->id]);
    }

    /** Number of issued invoices of the period: the same filter as the register, independent of the active tab (FCUX-04). */
    public function countIssuedDuring(Organization $organization, FinancePeriod $period, ?Store $store): int
    {
        return $this->baseQuery($organization, $store)->where('status', InvoiceStatus::Issued->value)
            ->whereDate('invoice_date', '>=', $period->start->toDateString())->whereDate('invoice_date', '<=', $period->end->toDateString())->count();
    }

    public function countCreditNotesDuring(Organization $organization, FinancePeriod $period, ?Store $store): int
    {
        return DB::table('credit_notes')->whereNull('sandbox_id')->where('organization_id', $organization->getKey())->where('status', 'issued')
            ->whereDate('credit_note_date', '>=', $period->start->toDateString())->whereDate('credit_note_date', '<=', $period->end->toDateString())
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))->count();
    }

    /**
     * Issued credit notes of a period, newest first (Finance clients "Avoirs"). Each row keeps the HT / TVA / TTC of the
     * document and the number of the invoice it credits; Test Mode documents are excluded.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function creditNotesDuring(Organization $organization, FinancePeriod $period, ?Store $store, int $perPage = 30): LengthAwarePaginator
    {
        return DB::table('credit_notes')->join('invoices', 'invoices.id', '=', 'credit_notes.invoice_id')->whereNull('credit_notes.sandbox_id')
            ->where('credit_notes.organization_id', $organization->getKey())->where('credit_notes.status', 'issued')
            ->whereDate('credit_notes.credit_note_date', '>=', $period->start->toDateString())->whereDate('credit_notes.credit_note_date', '<=', $period->end->toDateString())
            ->when($store, fn ($query) => $query->where('credit_notes.store_id', $store->getKey()))
            ->orderByDesc('credit_notes.credit_note_date')->orderByDesc('credit_notes.id')
            ->select(['credit_notes.id', 'credit_notes.credit_note_number', 'credit_notes.credit_note_date', 'credit_notes.subtotal_excl_tax', 'credit_notes.discount_total', 'credit_notes.tax_total', 'credit_notes.total_incl_tax',
                'credit_notes.reason', 'invoices.id as invoice_id', 'invoices.invoice_number', 'invoices.customer_name', 'invoices.customer_company'])
            ->paginate($perPage)->withQueryString()
            ->through(fn ($row) => [
                'id' => $row->id, 'credit_note_number' => $row->credit_note_number, 'date' => substr((string) $row->credit_note_date, 0, 10),
                'subtotal_excl_tax' => Decimal::normalize((string) $row->subtotal_excl_tax), 'discount_total' => Decimal::normalize((string) $row->discount_total),
                'net_excl_tax' => Decimal::subtract((string) $row->subtotal_excl_tax, (string) $row->discount_total), 'tax_total' => Decimal::normalize((string) $row->tax_total),
                'total_incl_tax' => Decimal::normalize((string) $row->total_incl_tax), 'reason' => $row->reason,
                'invoice_id' => $row->invoice_id, 'invoice_number' => $row->invoice_number, 'customer' => trim($row->customer_company ?: $row->customer_name ?: '') ?: '—',
            ]);
    }

    /**
     * Enrich an arbitrary (already bounded) collection/paginator of Invoice
     * models with paid/outstanding/status/fully_paid_at, as of a given date
     * (defaults to "now" — the full picture, not just within-period paid
     * amounts). Three batched queries regardless of collection size: net
     * payments, ordered allocations, and issued Credit Note totals.
     */
    public function withPaymentSummaries(iterable $invoices, ?Carbon $asOf = null): Collection
    {
        $invoices = collect($invoices);
        $asOf ??= now();
        $salesOrderIds = $invoices->pluck('sales_order_id')->unique()->values()->all();
        $paidTotals = $this->receivables->paidAmountsBySalesOrder($salesOrderIds, $asOf);
        $orderedAllocations = $this->receivables->orderedAllocationsBySalesOrder($salesOrderIds, $asOf);
        $credits = DB::table('credit_notes')->whereNull('credit_notes.sandbox_id')->whereIn('sales_order_id', $salesOrderIds)->where('status', 'issued')->whereDate('credit_note_date', '<=', $asOf->toDateString())
            ->groupBy('sales_order_id')->select('sales_order_id', DB::raw('SUM(total_incl_tax) as amount'))->pluck('amount', 'sales_order_id');

        return $invoices->map(fn (Invoice $invoice) => $this->summaryRow(
            $invoice,
            $paidTotals[$invoice->sales_order_id] ?? '0.0000',
            $orderedAllocations[$invoice->sales_order_id] ?? [],
            Decimal::normalize((string) ($credits[$invoice->sales_order_id] ?? '0.0000')),
        ));
    }

    /** Mode 1 — Factures émises durant le mois. */
    public function issuedInMonth(Organization $organization, FinancePeriod $period, ?Store $store): Collection
    {
        $invoices = $this->baseQuery($organization, $store)
            ->where('status', InvoiceStatus::Issued->value)
            ->whereDate('invoice_date', '>=', $period->start->toDateString())
            ->whereDate('invoice_date', '<=', $period->end->toDateString())
            ->orderBy('invoice_date')->orderBy('id')
            ->get();

        return $invoices;
    }

    /** Mode 3 — Factures ayant reçu un paiement durant le mois. */
    public function receivedPaymentDuringMonth(Organization $organization, FinancePeriod $period, ?Store $store): Collection
    {
        $salesOrderIds = $this->salesOrderIdsWithPaymentIn($organization, $period, $store);

        return $this->issuedInvoicesForOrders($organization, $store, $salesOrderIds);
    }

    /**
     * Mode 2 — Factures soldées durant le mois: invoices that were NOT fully
     * paid before this period started but ARE fully paid by its end. Bounded
     * to orders that actually received a payment in the period (only those
     * could possibly have crossed the fully-paid threshold this month) — a
     * handful of batched queries, never one per candidate invoice.
     */
    public function settledDuringMonth(Organization $organization, FinancePeriod $period, ?Store $store): Collection
    {
        $salesOrderIds = $this->salesOrderIdsWithPaymentIn($organization, $period, $store);
        $invoices = $this->issuedInvoicesForOrders($organization, $store, $salesOrderIds);

        if ($invoices->isEmpty()) {
            return $invoices;
        }

        $orderIds = $invoices->pluck('sales_order_id')->all();
        $paidBefore = $this->receivables->paidAmountsBySalesOrder($orderIds, $period->dayBeforeStart());
        $paidAfter = $this->receivables->paidAmountsBySalesOrder($orderIds, $period->end);

        return $invoices->filter(function (Invoice $invoice) use ($paidBefore, $paidAfter) {
            $before = $paidBefore[$invoice->sales_order_id] ?? '0.0000';
            $after = $paidAfter[$invoice->sales_order_id] ?? '0.0000';

            return Decimal::compare($before, $invoice->total_incl_tax) < 0
                && Decimal::compare($after, $invoice->total_incl_tax) >= 0;
        })->values();
    }

    /** Mode 4 — Factures de la sélection courante: explicit ids, tenant-verified. */
    public function forExplicitIds(Organization $organization, array $invoiceIds): Collection
    {
        return $this->baseQuery($organization, null)
            ->whereIn('id', $invoiceIds)
            ->orderBy('invoice_date')->orderBy('id')
            ->get();
    }

    /** @return list<int> */
    private function salesOrderIdsWithPaymentIn(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        return DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payments.organization_id', $organization->getKey())
            ->where('payments.status', PaymentStatus::Posted->value)
            ->whereDate('payments.payment_date', '>=', $period->start->toDateString())
            ->whereDate('payments.payment_date', '<=', $period->end->toDateString())
            ->when($store, fn ($query) => $query->where('payments.store_id', $store->getKey()))
            ->distinct()
            ->pluck('payment_allocations.sales_order_id')
            ->all();
    }

    /** @param  list<int>  $salesOrderIds */
    private function issuedInvoicesForOrders(Organization $organization, ?Store $store, array $salesOrderIds): Collection
    {
        if ($salesOrderIds === []) {
            return collect();
        }

        return $this->baseQuery($organization, $store)
            ->where('status', InvoiceStatus::Issued->value)
            ->whereIn('sales_order_id', $salesOrderIds)
            ->orderBy('invoice_date')->orderBy('id')
            ->get();
    }

    private function baseQuery(Organization $organization, ?Store $store)
    {
        return Invoice::query()
            ->where('organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()));
    }

    /**
     * Always fed pre-fetched (batched) amounts by withPaymentSummaries() —
     * never fetches here itself, so there is no accidental per-invoice query
     * path to fall into.
     *
     * @param  list<array{payment_date: string, amount: string}>  $orderedAllocations
     * @return array<string, mixed>
     */
    private function summaryRow(Invoice $invoice, string $paidAmount, array $orderedAllocations, string $creditedAmount): array
    {
        $netTotal = $this->nonNegative(Decimal::subtract($invoice->total_incl_tax, $creditedAmount));
        $outstanding = $this->nonNegative(Decimal::subtract($netTotal, $paidAmount));
        $fullyPaidAt = $this->fullyPaidAt($netTotal, $orderedAllocations);
        $status = match (true) {
            Decimal::compare($paidAmount, '0.0000') <= 0 => 'unpaid',
            $fullyPaidAt !== null => 'paid',
            default => 'partial',
        };

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'customer_name' => $invoice->customer_name,
            'customer_company' => $invoice->customer_company,
            'sales_order_id' => $invoice->sales_order_id,
            'store_id' => $invoice->store_id,
            // Additive (Finance clients): HT / TVA of the issued document and its stamped due date (never invented for old invoices).
            // Presentation-only HT / remise / TVA: null (never zero) when the caller loaded a partial invoice without those columns.
            // Unambiguous presentation (FCUX-02): HT net = HT brut - remise, and HT net + TVA = TTC. Stored amounts are never altered.
            'subtotal_excl_tax' => $this->loaded($invoice, 'subtotal_excl_tax'),
            'discount_total' => $this->loaded($invoice, 'discount_total'),
            'net_excl_tax' => $this->loaded($invoice, 'subtotal_excl_tax') !== null && $this->loaded($invoice, 'discount_total') !== null
                ? Decimal::subtract((string) $invoice->subtotal_excl_tax, (string) $invoice->discount_total) : null,
            'tax_total' => $this->loaded($invoice, 'tax_total'),
            'due_date' => $invoice->due_date?->toDateString(),
            'is_overdue' => $invoice->due_date !== null && Decimal::compare($outstanding, '0.0000') > 0 && $invoice->due_date->toDateString() < now()->toDateString(),
            'total_incl_tax' => (string) $invoice->total_incl_tax,
            'credited_amount' => $creditedAmount,
            'net_total_incl_tax' => $netTotal,
            'paid_amount' => $paidAmount,
            'outstanding' => $outstanding,
            'fully_paid_at' => $fullyPaidAt,
            'status' => $status,
            // Derived financial settlement (FCUX-11): document status and payment status are untouched; a credit note is never a cash payment.
            'settlement_status' => $this->settlementStatus($outstanding, $paidAmount, $creditedAmount),
        ];
    }

    /** @return 'unpaid'|'partial'|'paid'|'settled_by_credit_note'|'settled_mixed' */
    private function settlementStatus(string $outstanding, string $paid, string $credited): string
    {
        $hasPaid = Decimal::compare($paid, '0.0000') > 0;
        $hasCredit = Decimal::compare($credited, '0.0000') > 0;
        if (Decimal::compare($outstanding, '0.0000') > 0) {
            return $hasPaid ? 'partial' : 'unpaid';
        }

        return match (true) {
            $hasCredit && $hasPaid => 'settled_mixed',
            $hasCredit => 'settled_by_credit_note',
            default => 'paid',
        };
    }

    /**
     * Walk allocations (already ordered by payment_date) and return the date
     * the running sum first reached the invoice total, or null if it never
     * did. Pure in-memory computation over an already-fetched batch.
     *
     * @param  list<array{payment_date: string, amount: string}>  $orderedAllocations
     */
    private function fullyPaidAt(string $total, array $orderedAllocations): ?string
    {
        $running = '0.0000';
        foreach ($orderedAllocations as $allocation) {
            $running = Decimal::add($running, $allocation['amount']);
            if (Decimal::compare($running, $total) >= 0) {
                return $allocation['payment_date'];
            }
        }

        return null;
    }

    /** The attribute as a string, or null when this (partial) invoice model did not select it: a missing column is unknown, never zero. */
    private function loaded(Invoice $invoice, string $column): ?string
    {
        $value = $invoice->getAttributes()[$column] ?? null;

        return $value === null ? null : (string) $invoice->{$column};
    }

    /** A receivable can never be negative — an overpayment floors at zero, it never offsets another invoice's balance. */
    private function nonNegative(string $amount): string
    {
        return Decimal::compare($amount, '0.0000') < 0 ? '0.0000' : $amount;
    }
}
