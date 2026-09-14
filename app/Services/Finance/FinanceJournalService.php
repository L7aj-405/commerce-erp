<?php

namespace App\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Store;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Monthly sales journal (accountant-style table): one row per invoice issued
 * in the period.
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
    public function rows(Organization $organization, FinancePeriod $period, ?Store $store, int $perPage = 30): LengthAwarePaginator
    {
        $paginator = Invoice::query()
            ->where('organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
            ->where('status', InvoiceStatus::Issued->value)
            ->whereDate('invoice_date', '>=', $period->start->toDateString())
            ->whereDate('invoice_date', '<=', $period->end->toDateString())
            ->with(['lines:id,invoice_id,product_name,description'])
            ->orderBy('invoice_date')->orderBy('id')
            ->paginate($perPage);

        $salesOrderIds = $paginator->getCollection()->pluck('sales_order_id')->unique()->values()->all();
        $methodsByOrder = $this->paymentMethodsBySalesOrder($salesOrderIds);

        return $paginator->through(fn (Invoice $invoice) => [
            'id' => $invoice->id,
            'date' => $invoice->invoice_date->toDateString(),
            'invoice_number' => $invoice->invoice_number,
            'designation' => $this->designation($invoice),
            'total_incl_tax' => (string) $invoice->total_incl_tax,
            'customer' => trim($invoice->customer_company ?: $invoice->customer_name ?: '') ?: '—',
            'payment_method' => $this->methodLabel($methodsByOrder[$invoice->sales_order_id] ?? []),
        ]);
    }

    private function designation(Invoice $invoice): string
    {
        $lines = $invoice->lines;
        if ($lines->isEmpty()) {
            return '—';
        }
        $first = $lines->first();
        $label = $first->product_name ?: $first->description;

        return $lines->count() > 1 ? "{$label} (+".($lines->count() - 1).' autre(s))' : $label;
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
}
