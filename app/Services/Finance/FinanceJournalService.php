<?php

namespace App\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Store;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monthly accountant view: a chronological event ledger plus the existing
 * detailed one-row-per-issued-Invoice sales journal.
 *
 * "Mode d'encaissement" (payment method(s) actually used so far, e.g.
 * "ESPÈCES / TPE") is deliberately NOT the same thing as a payment-terms
 * field like "30 jours" — that concept ("Modalité de paiement") does not
 * exist anywhere in this system (no due-date/terms column on Customer,
 * SalesOrder, or Invoice — confirmed by the Finance V1 audit). Computed live
 * from the order's posted payments here rather than reusing the invoice's
 * own `payment_method_summary` column, because that column is a snapshot
 * frozen at invoice-creation time and can predate payments received later.
 */
class FinanceJournalService
{
    /**
     * Chronological accounting events. Documents and cash movements remain
     * separate rows; no synthetic transaction merges their meanings.
     */
    public function events(Organization $organization, FinancePeriod $period, ?Store $store): Collection
    {
        $scope = fn ($query, string $table) => $query
            ->where("{$table}.organization_id", $organization->getKey())
            ->when($store, fn ($query) => $query->where("{$table}.store_id", $store->getKey()));

        $invoices = $scope(DB::table('invoices'), 'invoices')
            ->where('status', InvoiceStatus::Issued->value)
            ->whereDate('invoice_date', '>=', $period->start->toDateString())->whereDate('invoice_date', '<=', $period->end->toDateString())
            ->get(['id', 'invoice_date as date', 'invoice_number as number', 'customer_name', 'customer_company', 'total_incl_tax as amount'])
            ->map(fn ($row) => $this->event('invoice', $row, 'Facture', "/invoices/{$row->id}", null, false));

        $credits = $scope(DB::table('credit_notes')->join('invoices', 'invoices.id', '=', 'credit_notes.invoice_id'), 'credit_notes')
            ->where('credit_notes.status', 'issued')
            ->whereDate('credit_notes.credit_note_date', '>=', $period->start->toDateString())->whereDate('credit_notes.credit_note_date', '<=', $period->end->toDateString())
            ->get(['credit_notes.id', 'credit_notes.credit_note_date as date', 'credit_notes.credit_note_number as number', 'invoices.customer_name', 'invoices.customer_company', 'credit_notes.total_incl_tax as amount', 'invoices.invoice_number as reference'])
            ->map(fn ($row) => $this->event('credit_note', $row, 'Avoir', "/credit-notes/{$row->id}", "Facture {$row->reference}", true));

        $payments = Payment::query()->where('organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
            ->where('payments.status', PaymentStatus::Posted->value)
            ->whereDate('payments.payment_date', '>=', $period->start->toDateString())->whereDate('payments.payment_date', '<=', $period->end->toDateString())
            ->with('allocations.salesOrder:id,order_number,customer_name,customer_company')->get()
            ->map(function (Payment $payment) {
                $order = $payment->allocations->first()?->salesOrder;

                return [
                    'id' => $payment->id, 'type' => 'payment', 'label' => 'Encaissement',
                    'date' => $payment->payment_date->toDateString(), 'number' => $payment->payment_number,
                    'reference' => $order ? "Commande {$order->order_number}" : null,
                    'customer' => trim($order?->customer_company ?: $order?->customer_name ?: '') ?: '—',
                    'amount' => (string) $payment->amount, 'negative' => false, 'url' => "/payments/{$payment->id}",
                ];
            });

        $refunds = $scope(DB::table('payment_refunds')->join('payments', 'payments.id', '=', 'payment_refunds.payment_id')->join('sales_orders', 'sales_orders.id', '=', 'payment_refunds.sales_order_id'), 'payment_refunds')
            ->where('payment_refunds.status', 'posted')
            ->whereDate('payment_refunds.refund_date', '>=', $period->start->toDateString())->whereDate('payment_refunds.refund_date', '<=', $period->end->toDateString())
            ->get(['payment_refunds.id', 'payment_refunds.refund_date as date', 'payment_refunds.refund_number as number', 'sales_orders.customer_name', 'sales_orders.customer_company', 'payment_refunds.amount', 'payments.id as payment_id', 'payments.payment_number as reference'])
            ->map(fn ($row) => $this->event('refund', $row, 'Remboursement', "/payments/{$row->payment_id}", "Paiement {$row->reference}", true));

        return collect()->concat($invoices)->concat($credits)->concat($payments)->concat($refunds)
            ->sortBy(fn (array $event) => $event['date'].'|'.$event['type'].'|'.str_pad((string) $event['id'], 20, '0', STR_PAD_LEFT))
            ->values();
    }

    public function rows(Organization $organization, FinancePeriod $period, ?Store $store, int $perPage = 30): LengthAwarePaginator
    {
        $paginator = Invoice::query()
            ->where('organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
            ->where('status', InvoiceStatus::Issued->value)
            ->whereDate('invoice_date', '>=', $period->start->toDateString())
            ->whereDate('invoice_date', '<=', $period->end->toDateString())
            ->with(['lines:id,invoice_id,position,quantity,product_name,description,sku,reference,variant_name'])
            ->orderBy('invoice_date')->orderBy('id')
            ->paginate($perPage);

        $salesOrderIds = $paginator->getCollection()->pluck('sales_order_id')->unique()->values()->all();
        $methodsByOrder = $this->paymentMethodsBySalesOrder($salesOrderIds);

        return $paginator->through(fn (Invoice $invoice) => [
            'id' => $invoice->id,
            'date' => $invoice->invoice_date->toDateString(),
            'invoice_number' => $invoice->invoice_number,
            // Full sold-line detail from the invoice's own immutable
            // snapshot — never truncated/summarized and never today's
            // Product row (see linesOf()).
            'lines' => $this->lines($invoice),
            'total_incl_tax' => (string) $invoice->total_incl_tax,
            'customer' => trim($invoice->customer_company ?: $invoice->customer_name ?: '') ?: '—',
            'payment_method' => $this->methodLabel($methodsByOrder[$invoice->sales_order_id] ?? []),
        ]);
    }

    /** @return list<array{quantity: string, designation: string, reference: ?string, variant: ?string}> */
    private function lines(Invoice $invoice): array
    {
        return $invoice->lines->map(fn ($line) => [
            'quantity' => (string) $line->quantity,
            'designation' => $line->product_name ?: $line->description,
            'reference' => $line->reference ?: $line->sku,
            'variant' => $line->variant_name,
        ])->all();
    }

    /** @param  list<int>  $salesOrderIds
     * @return array<int, list<string>>
     */
    private function paymentMethodsBySalesOrder(array $salesOrderIds): array
    {
        if ($salesOrderIds === []) {
            return [];
        }

        return DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereIn('payment_allocations.sales_order_id', $salesOrderIds)
            ->where('payments.status', PaymentStatus::Posted->value)
            ->select('payment_allocations.sales_order_id', 'payments.method')
            ->distinct()
            ->get()
            ->groupBy('sales_order_id')
            ->map(fn ($rows) => $rows->pluck('method')->all())
            ->all();
    }

    /** @param  list<string>  $methods */
    private function methodLabel(array $methods): string
    {
        if ($methods === []) {
            return '—';
        }

        return PaymentMethod::summary($methods);
    }

    private function event(string $type, object $row, string $label, string $url, ?string $reference, bool $negative): array
    {
        return [
            'id' => (int) $row->id,
            'type' => $type,
            'label' => $label,
            'date' => (string) $row->date,
            'number' => $row->number,
            'reference' => $reference,
            'customer' => trim($row->customer_company ?: $row->customer_name ?: '') ?: '—',
            'amount' => (string) $row->amount,
            'negative' => $negative,
            'url' => $url,
        ];
    }
}
