<?php

namespace App\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Organization;
use App\Models\Store;
use App\Support\Decimal;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * "CA encaissé" — the accountant-facing breakdown behind the Situation
 * mensuelle "Encaissements" headline number.
 *
 * Business rule (deliberately different from Ventes / Facturation): a row
 * belongs to the month of its Payment.payment_date — never sale_date or
 * invoice_date. A single Invoice can therefore legitimately contribute rows
 * to several different months' CA encaissé, each showing only the amount
 * actually received that month (see PaymentAllocation, the source of truth
 * for "how much of THIS payment applies to THIS order"). A Posted payment
 * with no Issued invoice yet ("avance sur commande") still counts in full —
 * an invoice is never required.
 *
 * This is a read-only, presentation-only view: it never touches
 * SalesOrder/Invoice/Payment accounting, and the "Paiement comptant /
 * partiel / Avance sur commande / Solde" label is a display classification
 * derived from the order's payment history, never a Payment.method value.
 */
class FinanceCaEncaisseService
{
    public function __construct(private readonly FinanceReceivablesService $receivables) {}

    /** The month's CA encaissé total — a single aggregate query. */
    public function total(Organization $organization, FinancePeriod $period, ?Store $store): string
    {
        return (string) $this->baseQuery($organization, $period, $store)->sum('payment_allocations.amount');
    }

    /** Paginated CA encaissé rows for the UI table. */
    public function rows(Organization $organization, FinancePeriod $period, ?Store $store, int $perPage = 30): LengthAwarePaginator
    {
        $paginator = $this->baseQuery($organization, $period, $store)
            ->select($this->columns())
            ->orderBy('payments.payment_date')->orderBy('payment_allocations.id')
            ->paginate($perPage);

        $enrichedById = $this->enrich($organization, collect($paginator->items()));

        return $paginator->through(fn ($row) => $enrichedById[(int) $row->allocation_id]);
    }

    /**
     * Memory-safe streaming of every qualifying row, for XLSX/PDF export —
     * never materializes the full period into memory at once. Fetched in
     * bounded chunks (keyset pagination on payment_allocations.id); each
     * chunk is enriched with exactly 3 extra batched queries regardless of
     * chunk size, so the query count stays flat across an arbitrarily large
     * month.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function cursor(Organization $organization, FinancePeriod $period, ?Store $store, int $chunkSize = 500): LazyCollection
    {
        return LazyCollection::make(function () use ($organization, $period, $store, $chunkSize) {
            $lastId = 0;

            while (true) {
                $chunk = $this->baseQuery($organization, $period, $store)
                    ->select($this->columns())
                    ->where('payment_allocations.id', '>', $lastId)
                    ->orderBy('payment_allocations.id')
                    ->limit($chunkSize)
                    ->get();

                if ($chunk->isEmpty()) {
                    return;
                }

                $enrichedById = $this->enrich($organization, $chunk);
                foreach ($chunk as $row) {
                    yield $enrichedById[(int) $row->allocation_id];
                }

                $lastId = (int) $chunk->last()->allocation_id;
            }
        });
    }

    private function baseQuery(Organization $organization, FinancePeriod $period, ?Store $store)
    {
        return DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'payment_allocations.sales_order_id')
            ->where('payments.organization_id', $organization->getKey())
            ->where('payments.status', PaymentStatus::Posted->value)
            ->whereDate('payments.payment_date', '>=', $period->start->toDateString())
            ->whereDate('payments.payment_date', '<=', $period->end->toDateString())
            ->when($store, fn ($query) => $query->where('payments.store_id', $store->getKey()));
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [
            'payment_allocations.id as allocation_id',
            'payment_allocations.sales_order_id',
            'payment_allocations.amount as encaisse_amount',
            'payments.id as payment_id',
            'payments.payment_number',
            'payments.payment_date',
            'payments.method',
            'sales_orders.order_number',
            'sales_orders.sale_date',
            'sales_orders.customer_name',
            'sales_orders.customer_company',
        ];
    }

    /**
     * Batch-enrich an already-fetched (bounded) set of rows with full sold
     * line detail, invoice reference and the display classification — 3
     * extra queries total regardless of how many rows are passed in (one of
     * them fetches every line for every order in the batch, never one query
     * per order — see linesBySalesOrder()). Never call the per-order helpers
     * below inside a loop over rows.
     *
     * @return array<int, array<string, mixed>> allocation_id => formatted row
     */
    private function enrich(Organization $organization, Collection $rows): array
    {
        $salesOrderIds = $rows->pluck('sales_order_id')->unique()->values()->all();
        $lines = $this->linesBySalesOrder($salesOrderIds);
        $invoices = $this->issuedInvoicesBySalesOrder($organization, $salesOrderIds);
        $orderedAllocations = $this->receivables->orderedAllocationsBySalesOrder($salesOrderIds);

        return $rows->mapWithKeys(fn ($row) => [
            (int) $row->allocation_id => $this->formatRow($row, $lines, $invoices, $orderedAllocations),
        ])->all();
    }

    /**
     * Every sold line for each order in the batch — the authoritative
     * SalesOrderLine snapshot, never today's Product row, and never
     * truncated/summarized: "Article A (+3 autres)" made it impossible for
     * the accountant to see what was actually sold, which is exactly the
     * defect this replaces. Works whether or not the order has an invoice
     * yet (an advance-sur-commande payment still shows its real order
     * lines) — one query for the whole batch, grouped in PHP.
     *
     * @param  list<int>  $salesOrderIds
     * @return array<int, list<array{quantity: string, designation: string, reference: ?string, variant: ?string}>>
     */
    private function linesBySalesOrder(array $salesOrderIds): array
    {
        if ($salesOrderIds === []) {
            return [];
        }

        return DB::table('sales_order_lines')
            ->whereIn('sales_order_id', $salesOrderIds)
            ->orderBy('sales_order_id')->orderBy('position')
            ->get(['sales_order_id', 'quantity', 'product_name', 'sku', 'reference', 'variant_name'])
            ->groupBy('sales_order_id')
            ->map(fn (Collection $lines) => $lines->map(fn ($line) => [
                'quantity' => (string) $line->quantity,
                'designation' => $line->product_name,
                'reference' => $line->reference ?: $line->sku,
                'variant' => $line->variant_name,
            ])->values()->all())
            ->all();
    }

    /**
     * The current effective (Issued) invoice per sales order, when one
     * exists — never fabricated. A payment that predates invoicing (or an
     * order that was never invoiced) simply has no entry here.
     *
     * @param  list<int>  $salesOrderIds
     * @return array<int, array{id: int, invoice_number: string, total_incl_tax: string}>
     */
    private function issuedInvoicesBySalesOrder(Organization $organization, array $salesOrderIds): array
    {
        if ($salesOrderIds === []) {
            return [];
        }

        return DB::table('invoices')
            ->where('organization_id', $organization->getKey())
            ->where('status', InvoiceStatus::Issued->value)
            ->whereIn('sales_order_id', $salesOrderIds)
            ->get(['sales_order_id', 'id', 'invoice_number', 'total_incl_tax'])
            ->keyBy('sales_order_id')
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'invoice_number' => $row->invoice_number,
                'total_incl_tax' => (string) $row->total_incl_tax,
            ])
            ->all();
    }

    /**
     * @param  array<int, list<array{quantity: string, designation: string, reference: ?string, variant: ?string}>>  $lines
     * @param  array<int, array{id: int, invoice_number: string, total_incl_tax: string}>  $invoices
     * @param  array<int, list<array{payment_id: int, payment_date: string, amount: string}>>  $orderedAllocations
     * @return array<string, mixed>
     */
    private function formatRow(object $row, array $lines, array $invoices, array $orderedAllocations): array
    {
        $salesOrderId = (int) $row->sales_order_id;
        $invoice = $invoices[$salesOrderId] ?? null;

        return [
            'id' => (int) $row->allocation_id,
            'payment_id' => (int) $row->payment_id,
            'payment_number' => $row->payment_number,
            'sale_date' => (string) $row->sale_date,
            'payment_date' => (string) $row->payment_date,
            'reference' => $invoice['invoice_number'] ?? $row->order_number,
            'reference_type' => $invoice ? 'invoice' : 'order',
            'invoice_id' => $invoice['id'] ?? null,
            'sales_order_id' => $salesOrderId,
            'order_number' => $row->order_number,
            // Full sold-line detail (§ Finance Journal/CA designation fix) —
            // never a truncated/summarized string. This is DOCUMENT DETAIL:
            // it must never be treated as a per-line amount, and the
            // payment-grain `amount` below stays exactly one value per row
            // regardless of how many lines the order has.
            'lines' => $lines[$salesOrderId] ?? [],
            'customer' => trim(($row->customer_company ?: $row->customer_name) ?: '') ?: '—',
            'method' => $row->method,
            'method_label' => PaymentMethod::from($row->method)->operationalLabel(),
            'amount' => (string) $row->encaisse_amount,
            'status_label' => $this->classify((int) $row->payment_id, $invoice, $orderedAllocations[$salesOrderId] ?? []),
        ];
    }

    /**
     * Presentation-only classification of how this payment sits in its
     * order's payment history — never a Payment.method, never persisted.
     *
     * - No Issued invoice at all yet: "Avance sur commande" (real money in,
     *   ahead of invoicing — the AVANCE SUR COMMANDE case).
     * - The order's very first posted payment, and it alone covers the
     *   invoice total: "Paiement comptant".
     * - The first payment, but it does not cover the total: "Paiement
     *   partiel" (more installments expected).
     * - A later payment that brings the running total up to (or past) the
     *   invoice total: "Solde / Reliquat" (the closing installment).
     * - Any other later, still-insufficient payment: "Paiement partiel".
     *
     * @param  ?array{id: int, invoice_number: string, total_incl_tax: string}  $invoice
     * @param  list<array{payment_id: int, payment_date: string, amount: string}>  $orderedAllocations
     */
    private function classify(int $paymentId, ?array $invoice, array $orderedAllocations): string
    {
        if ($invoice === null) {
            return 'Avance sur commande';
        }

        $cumulative = '0.0000';
        $index = null;
        foreach ($orderedAllocations as $position => $allocation) {
            $cumulative = Decimal::add($cumulative, $allocation['amount']);
            if ($allocation['payment_id'] === $paymentId) {
                $index = $position;
                break;
            }
        }

        $isFirst = $index === 0;
        $coversTotal = Decimal::compare($cumulative, $invoice['total_incl_tax']) >= 0;

        return match (true) {
            $isFirst && $coversTotal => 'Paiement comptant',
            ! $isFirst && $coversTotal => 'Solde / Reliquat',
            default => 'Paiement partiel',
        };
    }
}
