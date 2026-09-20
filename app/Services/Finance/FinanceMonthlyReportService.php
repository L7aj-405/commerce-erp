<?php

namespace App\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Support\Decimal;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Read-only aggregates for the three headline metrics that never need an
 * invoice/payment-allocation join: Ventes, Facturation, Encaissements. Each
 * is a single DB-side SUM() — no rows are ever loaded into PHP and summed in
 * a Collection, and none of these three ever join across the
 * sales_orders / invoices / payments tables.
 *
 * Créances (which DOES need the SalesOrder <-> PaymentAllocation
 * relationship) lives in FinanceReceivablesService.
 */
class FinanceMonthlyReportService
{
    public function __construct(private readonly FinanceReceivablesService $receivables) {}

    /**
     * The full Situation mensuelle payload for one period: the three headline
     * sums plus the opening/closing receivables reconciliation.
     *
     * @return array<string, mixed>
     */
    public function situation(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        $ventes = $this->ventesTotal($organization, $period, $store);
        $facturationBrute = $this->facturationTotal($organization, $period, $store);
        $avoirs = $this->creditNotesTotal($organization, $period, $store);
        $ventesNettes = Decimal::subtract($ventes, $avoirs);
        $facturation = Decimal::subtract($facturationBrute, $avoirs);
        $encaissements = $this->encaissementsTotal($organization, $period, $store);
        $remboursements = $this->refundsTotal($organization, $period, $store);
        $netEncaisse = Decimal::subtract($encaissements, $remboursements);
        $creancesDebut = $this->receivables->totalAsOf($organization, $period->dayBeforeStart(), $store);
        $creancesFin = $this->receivables->totalAsOf($organization, $period->end, $store);
        $obligationsDebut = $this->receivables->refundObligationAsOf($organization, $period->dayBeforeStart(), $store);
        $obligationsFin = $this->receivables->refundObligationAsOf($organization, $period->end, $store);

        // Reconciliation identity: début + facturation - encaissements should
        // equal fin, EXCEPT when a payment landed in this period against an
        // order that has no issued invoice yet ("payment before invoice" is a
        // legitimate state per the existing architecture — see
        // FinanceReceivablesService::unappliedPaymentsTotal()). Rather than
        // silently forcing the arithmetic to balance, the true gap is exposed
        // explicitly so the report stays traceable instead of a black box.
        $expectedFin = Decimal::subtract(Decimal::add($creancesDebut, $facturation), $netEncaisse);
        $unapplied = $this->receivables->unappliedPaymentsTotal($organization, $period, $store);
        $positionDebut = Decimal::subtract($creancesDebut, $obligationsDebut);
        $positionFin = Decimal::subtract($creancesFin, $obligationsFin);
        $expectedPositionFin = Decimal::subtract(Decimal::add($positionDebut, $facturation), $netEncaisse);

        return [
            'period' => $period->month,
            'label' => $period->label(),
            'ventes' => $ventes,
            'ventes_nettes' => $ventesNettes,
            'facturation' => $facturation,
            'facturation_brute' => $facturationBrute,
            'avoirs' => $avoirs,
            'encaissements' => $encaissements,
            'remboursements' => $remboursements,
            'net_encaisse' => $netEncaisse,
            'creances_debut' => $creancesDebut,
            'creances_fin' => $creancesFin,
            'obligations_remboursement_debut' => $obligationsDebut,
            'obligations_remboursement' => $obligationsFin,
            'position_nette_debut' => $positionDebut,
            'position_nette_fin' => $positionFin,
            'reconciliation' => [
                'expected_fin' => $expectedFin,
                'variance' => Decimal::subtract($creancesFin, $expectedFin),
                'unapplied_payments' => $unapplied,
            ],
            'customer_position' => [
                'expected_fin' => $expectedPositionFin,
                'variance' => Decimal::subtract($positionFin, $expectedPositionFin),
            ],
        ];
    }

    public function ventesTotal(Organization $organization, FinancePeriod $period, ?Store $store): string
    {
        return Decimal::normalize((string) $this->baseQuery('sales_orders', $organization, $store)
            ->where('status', SalesOrderStatus::Confirmed->value)
            ->whereDate('sale_date', '>=', $period->start->toDateString())
            ->whereDate('sale_date', '<=', $period->end->toDateString())
            ->sum('total_incl_tax'));
    }

    public function facturationTotal(Organization $organization, FinancePeriod $period, ?Store $store): string
    {
        return $this->facturationTotalBetween($organization, $period->start, $period->end, $store);
    }

    public function facturationTotalBetween(Organization $organization, Carbon $start, Carbon $end, ?Store $store): string
    {
        // status = Issued already IS "the current effective invoice": the
        // original is flipped to Superseded in the very same transaction that
        // issues its correction (IssueInvoiceAction), so at most one row per
        // sales_order_id ever carries status = Issued at once. No extra
        // "latest per order" logic is needed.
        return Decimal::normalize((string) $this->baseQuery('invoices', $organization, $store)
            ->where('status', InvoiceStatus::Issued->value)
            ->whereDate('invoice_date', '>=', $start->toDateString())
            ->whereDate('invoice_date', '<=', $end->toDateString())
            ->sum('total_incl_tax'));
    }

    public function encaissementsTotal(Organization $organization, FinancePeriod $period, ?Store $store): string
    {
        return Decimal::normalize((string) $this->baseQuery('payments', $organization, $store)
            ->where('status', PaymentStatus::Posted->value)
            ->whereDate('payment_date', '>=', $period->start->toDateString())
            ->whereDate('payment_date', '<=', $period->end->toDateString())
            ->sum('amount'));
    }

    public function creditNotesTotal(Organization $organization, FinancePeriod $period, ?Store $store): string
    {
        return Decimal::normalize((string) $this->baseQuery('credit_notes', $organization, $store)
            ->where('status', 'issued')
            ->whereDate('credit_note_date', '>=', $period->start->toDateString())
            ->whereDate('credit_note_date', '<=', $period->end->toDateString())
            ->sum('total_incl_tax'));
    }

    public function refundsTotal(Organization $organization, FinancePeriod $period, ?Store $store): string
    {
        return Decimal::normalize((string) $this->baseQuery('payment_refunds', $organization, $store)
            ->where('status', 'posted')
            ->whereDate('refund_date', '>=', $period->start->toDateString())
            ->whereDate('refund_date', '<=', $period->end->toDateString())
            ->sum('amount'));
    }

    /** Confirmed SalesOrders for the period (Ventes drill-down), paginated. */
    public function ventesBreakdown(Organization $organization, FinancePeriod $period, ?Store $store, int $perPage = 30): LengthAwarePaginator
    {
        return SalesOrder::query()
            ->where('organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
            ->where('status', SalesOrderStatus::Confirmed->value)
            ->whereDate('sale_date', '>=', $period->start->toDateString())
            ->whereDate('sale_date', '<=', $period->end->toDateString())
            ->withSum(['customerReturns as returned_total' => fn ($query) => $query->where('status', 'received')], 'total_incl_tax')
            ->orderBy('sale_date')->orderBy('id')
            ->paginate($perPage)
            ->through(function (SalesOrder $order) {
                $returned = Decimal::normalize((string) ($order->returned_total ?? '0.0000'));
                $net = Decimal::subtract($order->total_incl_tax, $returned);
                if (Decimal::compare($net, '0.0000') < 0) $net = '0.0000';

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'sale_date' => $order->sale_date->toDateString(),
                    'customer' => trim($order->customer_company ?: $order->customer_name ?: '') ?: '—',
                    'store_id' => $order->store_id,
                    'total_incl_tax' => (string) $order->total_incl_tax,
                    'returned_total' => $returned,
                    'net_total' => $net,
                    'payment_status' => $order->payment_status->value,
                ];
            });
    }

    /** Posted Payments for the period (Encaissements drill-down), paginated. */
    public function encaissementsBreakdown(Organization $organization, FinancePeriod $period, ?Store $store, int $perPage = 30)
    {
        return Payment::query()
            ->where('organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
            ->where('status', PaymentStatus::Posted->value)
            ->whereDate('payment_date', '>=', $period->start->toDateString())
            ->whereDate('payment_date', '<=', $period->end->toDateString())
            ->with(['financialAccount:id,name,code,type', 'allocations.salesOrder:id,order_number,customer_name,customer_company'])
            ->orderBy('payment_date')->orderBy('id')
            ->paginate($perPage)
            ->through(fn (Payment $payment) => [
                'id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'payment_date' => $payment->payment_date->toDateString(),
                'method' => $payment->method->value,
                'amount' => Decimal::normalize((string) $payment->amount),
                'financial_account' => $payment->financialAccount?->only(['id', 'name', 'code', 'type']),
                'store_id' => $payment->store_id,
                'order' => $payment->allocations->first()?->salesOrder?->only(['id', 'order_number', 'customer_name', 'customer_company']),
            ]);
    }

    /** Posted refund outflows for the period, kept separate from collections. */
    public function refundsBreakdown(Organization $organization, FinancePeriod $period, ?Store $store, int $perPage = 30)
    {
        return PaymentRefund::query()
            ->where('organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
            ->where('status', 'posted')
            ->whereDate('refund_date', '>=', $period->start->toDateString())
            ->whereDate('refund_date', '<=', $period->end->toDateString())
            ->with(['payment:id,payment_number', 'financialAccount:id,name,code,type', 'salesOrder:id,order_number,customer_name,customer_company'])
            ->orderByDesc('refund_date')->orderByDesc('id')
            ->paginate($perPage, ['*'], 'refund_page')
            ->withQueryString()
            ->through(fn (PaymentRefund $refund) => [
                'id' => $refund->id,
                'refund_number' => $refund->refund_number,
                'refund_date' => $refund->refund_date->toDateString(),
                'method' => $refund->method->value,
                'amount' => Decimal::normalize((string) $refund->amount),
                'reason' => $refund->reason,
                'original_payment' => $refund->payment?->only(['id', 'payment_number']),
                'financial_account' => $refund->financialAccount?->only(['id', 'name', 'code', 'type']),
                'order' => $refund->salesOrder?->only(['id', 'order_number', 'customer_name', 'customer_company']),
            ]);
    }

    /**
     * Memory-safe streaming of every confirmed SalesOrder in the period, for
     * the Ventes export sheet — never materializes the full result set.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function ventesCursor(Organization $organization, FinancePeriod $period, ?Store $store): LazyCollection
    {
        return SalesOrder::query()
            ->where('organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
            ->where('status', SalesOrderStatus::Confirmed->value)
            ->whereDate('sale_date', '>=', $period->start->toDateString())
            ->whereDate('sale_date', '<=', $period->end->toDateString())
            ->orderBy('sale_date')->orderBy('id')
            ->cursor()
            ->map(fn (SalesOrder $order) => [
                'order_number' => $order->order_number,
                'sale_date' => $order->sale_date->toDateString(),
                'customer' => trim($order->customer_company ?: $order->customer_name ?: '') ?: '—',
                'subtotal_excl_tax' => (string) $order->subtotal_excl_tax,
                'tax_total' => (string) $order->tax_total,
                'total_incl_tax' => (string) $order->total_incl_tax,
                'payment_status' => $order->payment_status->value,
            ]);
    }

    /**
     * Memory-safe streaming of every posted Payment in the period, for the
     * Encaissements export sheet. A plain query-builder join (not
     * Eloquent `with()`, which does not batch-eager-load across a `cursor()`
     * stream) keeps this to one query regardless of row count.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function encaissementsCursor(Organization $organization, FinancePeriod $period, ?Store $store): LazyCollection
    {
        return DB::table('payments')
            ->join('financial_accounts', 'financial_accounts.id', '=', 'payments.financial_account_id')
            ->leftJoin('payment_allocations', 'payment_allocations.payment_id', '=', 'payments.id')
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'payment_allocations.sales_order_id')
            ->where('payments.organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('payments.store_id', $store->getKey()))
            ->where('payments.status', PaymentStatus::Posted->value)
            ->whereDate('payments.payment_date', '>=', $period->start->toDateString())
            ->whereDate('payments.payment_date', '<=', $period->end->toDateString())
            ->orderBy('payments.payment_date')->orderBy('payments.id')
            ->select([
                'payments.payment_number', 'payments.payment_date', 'payments.method', 'payments.amount',
                'financial_accounts.name as financial_account_name',
                'sales_orders.order_number', 'sales_orders.customer_name', 'sales_orders.customer_company',
            ])
            ->cursor()
            ->map(fn ($row) => [
                'payment_number' => $row->payment_number,
                'payment_date' => $row->payment_date,
                'method' => PaymentMethod::from($row->method)->documentLabel(),
                'amount' => Decimal::normalize((string) $row->amount),
                'financial_account' => $row->financial_account_name,
                'order_number' => $row->order_number,
                'customer' => $row->customer_company ?: $row->customer_name,
            ]);
    }

    /** Issued Credit Note lines for accounting export, one immutable item snapshot per row. */
    public function creditNotesCursor(Organization $organization, FinancePeriod $period, ?Store $store): LazyCollection
    {
        return DB::table('credit_note_lines')
            ->join('credit_notes', 'credit_notes.id', '=', 'credit_note_lines.credit_note_id')
            ->join('invoices', 'invoices.id', '=', 'credit_notes.invoice_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'credit_notes.sales_order_id')
            ->leftJoin('customer_return_lines', 'customer_return_lines.id', '=', 'credit_note_lines.customer_return_line_id')
            ->where('credit_notes.organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('credit_notes.store_id', $store->getKey()))
            ->where('credit_notes.status', 'issued')
            ->whereDate('credit_notes.credit_note_date', '>=', $period->start->toDateString())
            ->whereDate('credit_notes.credit_note_date', '<=', $period->end->toDateString())
            ->orderBy('credit_notes.credit_note_date')->orderBy('credit_notes.id')->orderBy('credit_note_lines.position')
            ->select([
                'credit_notes.id as credit_note_id', 'credit_notes.credit_note_number', 'credit_notes.credit_note_date',
                'credit_notes.subtotal_excl_tax as document_subtotal_excl_tax', 'credit_notes.discount_total as document_discount_total',
                'credit_notes.tax_total as document_tax_total', 'credit_notes.total_incl_tax as document_total_incl_tax',
                'invoices.invoice_number', 'invoices.version as invoice_version', 'sales_orders.order_number',
                'invoices.customer_name', 'invoices.customer_company', 'credit_note_lines.quantity', 'credit_note_lines.reference',
                'customer_return_lines.sku', 'credit_note_lines.description', 'customer_return_lines.variant_name',
                'credit_note_lines.taxable_amount', 'credit_note_lines.discount_amount', 'credit_note_lines.tax_rate',
                'credit_note_lines.tax_amount', 'credit_note_lines.total_incl_tax',
            ])->cursor()->map(fn ($row) => [
                'credit_note_id' => (int) $row->credit_note_id,
                'credit_note_number' => $row->credit_note_number,
                'credit_note_date' => (string) $row->credit_note_date,
                'invoice_number' => $row->invoice_number,
                'invoice_version' => (int) $row->invoice_version,
                'order_number' => $row->order_number,
                'customer' => $row->customer_company ?: $row->customer_name,
                'quantity' => Decimal::normalize((string) $row->quantity),
                'reference' => $row->reference,
                'sku' => $row->sku,
                'description' => $row->description,
                'variant' => $row->variant_name,
                'line_ht' => Decimal::normalize((string) $row->taxable_amount),
                'line_discount' => Decimal::normalize((string) $row->discount_amount),
                'tax_rate' => Decimal::normalize((string) $row->tax_rate),
                'line_tax' => Decimal::normalize((string) $row->tax_amount),
                'line_ttc' => Decimal::normalize((string) $row->total_incl_tax),
                'document_ht' => Decimal::subtract((string) $row->document_subtotal_excl_tax, (string) $row->document_discount_total),
                'document_tax' => Decimal::normalize((string) $row->document_tax_total),
                'document_ttc' => Decimal::normalize((string) $row->document_total_incl_tax),
            ]);
    }

    /** Signed Invoice/Avoir rows for direct accountant reconciliation. */
    public function accountingDocumentsCursor(Organization $organization, FinancePeriod $period, ?Store $store): LazyCollection
    {
        $invoices = DB::table('invoices')
            ->join('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
            ->where('invoices.organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('invoices.store_id', $store->getKey()))
            ->where('invoices.status', InvoiceStatus::Issued->value)
            ->whereDate('invoices.invoice_date', '>=', $period->start->toDateString())
            ->whereDate('invoices.invoice_date', '<=', $period->end->toDateString())
            ->selectRaw("'FACTURE' as document_type, invoices.id as document_id, invoices.invoice_number as document_number, invoices.invoice_date as document_date")
            ->addSelect([
                'invoices.customer_name', 'invoices.customer_company', 'sales_orders.order_number',
                DB::raw('NULL as original_invoice_number'), 'invoices.subtotal_excl_tax', 'invoices.discount_total',
                'invoices.tax_total', 'invoices.total_incl_tax', DB::raw("'+' as effect"),
            ]);

        $creditNotes = DB::table('credit_notes')
            ->join('invoices', 'invoices.id', '=', 'credit_notes.invoice_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'credit_notes.sales_order_id')
            ->where('credit_notes.organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('credit_notes.store_id', $store->getKey()))
            ->where('credit_notes.status', 'issued')
            ->whereDate('credit_notes.credit_note_date', '>=', $period->start->toDateString())
            ->whereDate('credit_notes.credit_note_date', '<=', $period->end->toDateString())
            ->selectRaw("'AVOIR' as document_type, credit_notes.id as document_id, credit_notes.credit_note_number as document_number, credit_notes.credit_note_date as document_date")
            ->addSelect([
                'invoices.customer_name', 'invoices.customer_company', 'sales_orders.order_number',
                'invoices.invoice_number as original_invoice_number', 'credit_notes.subtotal_excl_tax',
                'credit_notes.discount_total', 'credit_notes.tax_total', 'credit_notes.total_incl_tax', DB::raw("'-' as effect"),
            ]);

        return $invoices->unionAll($creditNotes)
            ->orderBy('document_date')->orderBy('document_type')->orderBy('document_id')
            ->cursor()
            ->map(function ($row) {
                $netHt = Decimal::subtract((string) $row->subtotal_excl_tax, (string) $row->discount_total);
                $ttc = Decimal::normalize((string) $row->total_incl_tax);

                return [
                    'document_type' => $row->document_type,
                    'document_number' => $row->document_number,
                    'document_date' => (string) $row->document_date,
                    'customer' => $row->customer_company ?: $row->customer_name,
                    'order_number' => $row->order_number,
                    'original_invoice_number' => $row->original_invoice_number,
                    'net_ht' => $netHt,
                    'tax_total' => Decimal::normalize((string) $row->tax_total),
                    'total_incl_tax' => $ttc,
                    'effect' => $row->effect,
                    'net_amount' => $row->effect === '-' ? Decimal::subtract('0.0000', $ttc) : $ttc,
                ];
            });
    }

    /** Posted refund outflows for accounting export. */
    public function refundsCursor(Organization $organization, FinancePeriod $period, ?Store $store): LazyCollection
    {
        return DB::table('payment_refunds')
            ->join('payments', 'payments.id', '=', 'payment_refunds.payment_id')
            ->join('financial_accounts', 'financial_accounts.id', '=', 'payment_refunds.financial_account_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'payment_refunds.sales_order_id')
            ->leftJoin('customer_returns', 'customer_returns.id', '=', 'payment_refunds.customer_return_id')
            ->where('payment_refunds.organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('payment_refunds.store_id', $store->getKey()))
            ->where('payment_refunds.status', 'posted')
            ->whereDate('payment_refunds.refund_date', '>=', $period->start->toDateString())
            ->whereDate('payment_refunds.refund_date', '<=', $period->end->toDateString())
            ->orderBy('payment_refunds.refund_date')->orderBy('payment_refunds.id')
            ->select([
                'payment_refunds.refund_number', 'payment_refunds.refund_date', 'payment_refunds.method',
                'payment_refunds.amount', 'payment_refunds.reason', 'payments.payment_number',
                'financial_accounts.name as financial_account_name', 'financial_accounts.code as financial_account_code',
                'sales_orders.order_number', 'sales_orders.customer_name', 'sales_orders.customer_company',
                'customer_returns.return_number',
            ])->cursor()->map(fn ($row) => [
                'refund_number' => $row->refund_number,
                'refund_date' => (string) $row->refund_date,
                'method' => PaymentMethod::from($row->method)->documentLabel(),
                'amount' => Decimal::normalize((string) $row->amount),
                'reason' => $row->reason,
                'payment_number' => $row->payment_number,
                'financial_account' => trim($row->financial_account_code.' '.$row->financial_account_name),
                'order_number' => $row->order_number,
                'customer' => $row->customer_company ?: $row->customer_name,
                'return_number' => $row->return_number,
            ]);
    }

    private function baseQuery(string $table, Organization $organization, ?Store $store)
    {
        return DB::table($table)
            ->where('organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()));
    }
}
