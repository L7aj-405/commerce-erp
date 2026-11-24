<?php

namespace App\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Store;
use App\Support\BusinessTime;
use App\Support\Decimal;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
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
    /** @param array{type?:string, search?:string} $filters server-side filters (event type key, customer / number search) */
    public function events(Organization $organization, FinancePeriod $period, ?Store $store, array $filters = []): Collection
    {
        $scope = fn ($query, string $table) => $query
            ->where("{$table}.organization_id", $organization->getKey())
            ->when($store, fn ($query) => $query->where("{$table}.store_id", $store->getKey()));

        $invoices = $scope(DB::table('invoices')->whereNull('invoices.sandbox_id'), 'invoices')
            ->where('status', InvoiceStatus::Issued->value)
            ->whereDate('invoice_date', '>=', $period->start->toDateString())->whereDate('invoice_date', '<=', $period->end->toDateString())
            ->get(['id', 'invoice_date as date', 'invoice_number as number', 'customer_name', 'customer_company', 'total_incl_tax as amount'])
            ->map(fn ($row) => $this->event('invoice', $row, 'Facture', "/invoices/{$row->id}", null, false));

        $credits = $scope(DB::table('credit_notes')->whereNull('credit_notes.sandbox_id')->join('invoices', 'invoices.id', '=', 'credit_notes.invoice_id'), 'credit_notes')
            ->where('credit_notes.status', 'issued')
            ->whereDate('credit_notes.credit_note_date', '>=', $period->start->toDateString())->whereDate('credit_notes.credit_note_date', '<=', $period->end->toDateString())
            ->get(['credit_notes.id', 'credit_notes.credit_note_date as date', 'credit_notes.credit_note_number as number', 'invoices.customer_name', 'invoices.customer_company', 'credit_notes.total_incl_tax as amount', 'invoices.invoice_number as reference'])
            ->map(fn ($row) => $this->event('credit_note', $row, 'Avoir', "/credit-notes/{$row->id}", "Facture {$row->reference}", true));

        $payments = Payment::query()->where('organization_id', $organization->getKey())
            ->when($store, fn ($query) => $query->where('store_id', $store->getKey()))
            ->where('payments.status', PaymentStatus::Posted->value)
            ->whereDate('payments.payment_date', '>=', $period->start->toDateString())->whereDate('payments.payment_date', '<=', $period->end->toDateString())
            ->with('allocations.salesOrder:id,order_number,customer_name,customer_company')->get();
        // Unallocated advances have no order: their customer comes from payments.customer_id (one batched lookup).
        $advanceCustomers = DB::table('customers')->where('organization_id', $organization->getKey())->whereIn('id', $payments->pluck('customer_id')->filter()->unique()->all())->pluck('display_name', 'id');
        $payments = $payments
            ->map(function (Payment $payment) use ($advanceCustomers) {
                $order = $payment->allocations->first()?->salesOrder;

                return [
                    'id' => $payment->id, 'type' => 'payment', 'label' => 'Encaissement',
                    'date' => $payment->payment_date->toDateString(), 'number' => $payment->payment_number,
                    'reference' => $order ? "Commande {$order->order_number}" : ($payment->customer_id ? 'Avance non affectée' : null),
                    'customer' => trim($order?->customer_company ?: $order?->customer_name ?: '') ?: ($advanceCustomers[$payment->customer_id] ?? '—'),
                    'amount' => (string) $payment->amount, 'negative' => false, 'url' => "/payments/{$payment->id}",
                ];
            });

        $refunds = $scope(DB::table('payment_refunds')->join('payments', 'payments.id', '=', 'payment_refunds.payment_id')->whereNull('sales_orders.sandbox_id')->join('sales_orders', 'sales_orders.id', '=', 'payment_refunds.sales_order_id'), 'payment_refunds')
            ->where('payment_refunds.status', 'posted')
            ->whereDate('payment_refunds.refund_date', '>=', $period->start->toDateString())->whereDate('payment_refunds.refund_date', '<=', $period->end->toDateString())
            ->get(['payment_refunds.id', 'payment_refunds.refund_date as date', 'payment_refunds.refund_number as number', 'sales_orders.customer_name', 'sales_orders.customer_company', 'payment_refunds.amount', 'payments.id as payment_id', 'payments.payment_number as reference'])
            ->map(fn ($row) => $this->event('refund', $row, 'Remboursement', "/payments/{$row->payment_id}", "Paiement {$row->reference}", true));

        return collect()->concat($invoices)->concat($credits)->concat($payments)->concat($refunds)->concat($this->customerEvents($organization, $period, $store))
            ->when($filters['type'] ?? null, fn ($events, string $type) => $events->where('type', $type))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', fn ($events) => $events->filter(function (array $event) use ($filters) {
                $needle = mb_strtolower(trim((string) $filters['search']));

                return str_contains(mb_strtolower($event['customer'].' '.$event['number'].' '.($event['reference'] ?? '')), $needle);
            }))
            ->sortBy(fn (array $event) => $event['date'].'|'.$event['type'].'|'.str_pad((string) $event['id'], 20, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * Finance clients additions: customer-account events that are NOT collections of their own. Every row is INFORMATIONAL
     * (`informational` = true): the money itself is already carried by the payment / refund rows above, so an instrument receipt
     * followed by its clearance, an advance allocation or an opening balance is never counted as a second collection.
     * Sources are authoritative records (opening balances, payment reversals, allocation audit events, instrument history).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function customerEvents(Organization $organization, FinancePeriod $period, ?Store $store): Collection
    {
        $orgId = $organization->getKey();
        $from = $period->start->toDateString();
        $to = $period->end->toDateString();
        $info = fn (string $type, string $label, int $id, string $date, string $number, ?string $reference, ?string $customer, string $amount, bool $negative, string $url) => [
            'id' => $id, 'type' => $type, 'label' => $label, 'date' => $date, 'number' => $number, 'reference' => $reference, 'customer' => $customer ?: '—',
            'amount' => $amount, 'negative' => $negative, 'url' => $url, 'informational' => true,
        ];
        $events = collect();

        foreach (DB::table('customer_opening_balances as o')->join('customers as c', 'c.id', '=', 'o.customer_id')->where('o.organization_id', $orgId)->get(['o.id', 'o.customer_id', 'o.effective_date', 'o.amount', 'o.reference', 'o.status', 'o.reversed_at', 'c.display_name']) as $o) {
            $amount = Decimal::normalize((string) $o->amount);
            $negative = Decimal::compare($amount, '0') < 0;
            $abs = $negative ? Decimal::subtract('0', $amount) : $amount;
            $url = "/finance/clients/comptes/{$o->customer_id}?section=terms";
            $date = substr((string) $o->effective_date, 0, 10);
            if ($date >= $from && $date <= $to) {
                $events->push($info('opening_balance', 'Solde d’ouverture client', (int) $o->id, $date, (string) $o->reference, null, $o->display_name, $abs, $negative, $url));
            }
            $reversed = $o->reversed_at === null ? null : BusinessTime::toLocal($organization, Carbon::parse((string) $o->reversed_at, 'UTC'))->toDateString();
            if ($reversed !== null && $reversed >= $from && $reversed <= $to) {
                $events->push($info('opening_balance_reversal', 'Inversion de solde d’ouverture', (int) $o->id, $reversed, (string) $o->reference, null, $o->display_name, $abs, ! $negative, $url));
            }
        }

        $payments = DB::table('payments as p')->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')->where('p.organization_id', $orgId)->where('p.status', 'reversed')->whereNotNull('p.reversed_at')
            ->when($store, fn ($q) => $q->where('p.store_id', $store->getKey()))->get(['p.id', 'p.payment_number', 'p.amount', 'p.reversed_at', 'c.display_name']);
        foreach ($payments as $p) {
            $date = BusinessTime::toLocal($organization, Carbon::parse((string) $p->reversed_at, 'UTC'))->toDateString();
            if ($date >= $from && $date <= $to) {
                $events->push($info('payment_reversal', 'Annulation de règlement', (int) $p->id, $date, (string) $p->payment_number, null, $p->display_name, Decimal::normalize((string) $p->amount), true, "/payments/{$p->id}"));
            }
        }

        $audits = DB::table('audit_logs')->where('organization_id', $orgId)->whereIn('event', ['customer_advance.allocated', 'customer_advance.deallocated'])
            ->when($store, fn ($q) => $q->where('store_id', $store->getKey()))->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to.' 23:59:59')->get(['id', 'event', 'auditable_id', 'old_values', 'new_values', 'created_at']);
        if ($audits->isNotEmpty()) {
            $names = DB::table('payments as p')->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')->where('p.organization_id', $orgId)->whereIn('p.id', $audits->pluck('auditable_id')->map(fn ($v) => (int) $v))->pluck('c.display_name', 'p.id');
            foreach ($audits as $audit) {
                $new = json_decode((string) $audit->new_values, true) ?: [];
                $old = json_decode((string) $audit->old_values, true) ?: [];
                $allocated = $audit->event === 'customer_advance.allocated';
                $amount = Decimal::normalize((string) ($allocated ? ($new['allocated'] ?? '0') : ($old['amount'] ?? '0')));
                $date = BusinessTime::toLocal($organization, Carbon::parse((string) $audit->created_at, 'UTC'))->toDateString();
                $events->push($info($allocated ? 'allocation' : 'deallocation', $allocated ? 'Affectation d’avance' : 'Désaffectation d’avance', (int) $audit->id, $date,
                    (string) ($new['payment_number'] ?? '—'), null, $names[(int) $audit->auditable_id] ?? null, $amount, ! $allocated, "/payments/{$audit->auditable_id}"));
            }
        }

        $labels = ['received' => 'Instrument reçu', 'in_portfolio' => 'Instrument en portefeuille', 'submitted' => 'Instrument remis en banque', 'cleared' => 'Instrument encaissé',
            'rejected' => 'Instrument rejeté / impayé', 'cancelled' => 'Instrument annulé'];
        foreach (DB::table('customer_instrument_events as e')->join('customer_payment_instruments as i', 'i.id', '=', 'e.instrument_id')->join('customers as c', 'c.id', '=', 'i.customer_id')
            ->where('e.organization_id', $orgId)->when($store, fn ($q) => $q->where('i.store_id', $store->getKey()))->whereDate('e.event_date', '>=', $from)->whereDate('e.event_date', '<=', $to)
            ->get(['e.id', 'e.to_state', 'e.event_date', 'i.instrument_number', 'i.amount', 'i.type', 'c.display_name', 'i.customer_id']) as $e) {
            $events->push($info('instrument_'.$e->to_state, $labels[$e->to_state] ?? $e->to_state, (int) $e->id, substr((string) $e->event_date, 0, 10), (string) $e->instrument_number, $e->type === 'cheque' ? 'Chèque' : 'Effet',
                $e->display_name, Decimal::normalize((string) $e->amount), $e->to_state === 'rejected' || $e->to_state === 'cancelled', '/finance/clients/instruments?search='.urlencode((string) $e->instrument_number)));
        }

        return $events;
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
            ->paginate($perPage)->withQueryString();

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
