<?php

namespace App\Services\Finance;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\DocumentVisibility;
use App\Support\BusinessTime;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Period-based customer account statement: opening balance, dated movements, closing balance.
 *
 * Sign convention: DEBIT = increases what the customer owes, CREDIT = decreases it. Balance = debits - credits.
 *   Debits : confirmed sales orders (TTC, sale date), refunds paid to the customer (refund date), positive opening balances.
 *   Credits: payment allocations (posted payments, payment date), customer returns received (TTC, received date),
 *            unallocated customer advances (payment date, only the part still unallocated), negative opening balances.
 *
 * It is built on the SAME order-specific rule as the receivables ("max(order TTC - returned, 0) - (collected - refunded)"):
 * credit notes are not statement lines because their economic effect is already the customer return that produced them
 * (counting both would double count). Opening balances are customer-level lines and never become sales, VAT, invoices or cash.
 * Scope is the viewer's: only documents of stores the viewer may see, and only with the view permissions of the documents.
 * The statement refuses (returns null) to mix currencies. Balances are exact decimal strings.
 */
final class CustomerStatementService
{
    public const MAX_ROWS = 5000;

    private const RANK = ['opening' => 0, 'sale' => 1, 'refund' => 2, 'return' => 3, 'payment' => 4, 'advance' => 5];

    public function __construct(private readonly DocumentVisibility $visibility) {}

    public function allowed(Organization $organization, User $viewer): bool
    {
        return $this->visibility->canView($viewer, $organization, SalesOrder::class) && $this->visibility->canView($viewer, $organization, Payment::class);
    }

    /**
     * @return array{customer_id:int, from:string, to:string, currency_code:?string, opening:string, closing:string, total_debit:string, total_credit:string, rows:list<array<string,mixed>>, truncated:bool, mixed_currency:bool}|null
     */
    public function build(Organization $organization, Customer $customer, User $viewer, string $from, string $to): ?array
    {
        if (! $this->allowed($organization, $viewer)) {
            return null;
        }
        $organizationId = (int) $organization->getKey();
        $stores = $this->visibility->storeIds($viewer, $organization) ?: [0];
        $customerId = (int) $customer->getKey();
        $events = [];
        $currencies = [];
        $add = function (string $type, string $date, string $reference, string $description, string $debit, string $credit, ?string $currency, int $id, ?string $docType = null, ?int $docId = null) use (&$events, &$currencies) {
            $events[] = compact('type', 'date', 'reference', 'description', 'debit', 'credit', 'id', 'docType', 'docId');
            if ($currency !== null) {
                $currencies[$currency] = true;
            }
        };

        foreach (DB::table('sales_orders')->where('organization_id', $organizationId)->where('customer_id', $customerId)->whereIn('store_id', $stores)
            ->whereNull('sandbox_id')->where('status', 'confirmed')->whereDate('sale_date', '<=', $to)
            ->get(['id', 'order_number', 'sale_date', 'total_incl_tax', 'currency_code']) as $r) {
            $add('sale', substr((string) $r->sale_date, 0, 10), $r->order_number, 'Commande confirmée', Decimal::normalize((string) $r->total_incl_tax), '0.0000', $r->currency_code, $r->id, 'sales_order', $r->id);
        }
        foreach (DB::table('payment_allocations as a')->join('payments as p', 'p.id', '=', 'a.payment_id')->join('sales_orders as s', 's.id', '=', 'a.sales_order_id')
            ->where('a.organization_id', $organizationId)->where('s.customer_id', $customerId)->whereIn('s.store_id', $stores)->whereIn('p.store_id', $stores)
            ->whereNull('s.sandbox_id')->where('p.status', 'posted')->whereDate('p.payment_date', '<=', $to)
            ->get(['a.id', 'a.amount', 'p.id as payment_id', 'p.payment_number', 'p.payment_date', 'p.currency_code', 's.order_number']) as $r) {
            $add('payment', substr((string) $r->payment_date, 0, 10), $r->payment_number, 'Règlement affecté à '.$r->order_number, '0.0000', Decimal::normalize((string) $r->amount), $r->currency_code, $r->id, 'payment', $r->payment_id);
        }
        foreach (DB::table('payment_refunds as f')->join('sales_orders as s', 's.id', '=', 'f.sales_order_id')->where('f.organization_id', $organizationId)
            ->where('s.customer_id', $customerId)->whereIn('s.store_id', $stores)->whereNull('s.sandbox_id')->where('f.status', 'posted')->whereDate('f.refund_date', '<=', $to)
            ->get(['f.id', 'f.refund_number', 'f.refund_date', 'f.amount', 'f.currency_code', 'f.payment_id', 's.order_number']) as $r) {
            $add('refund', substr((string) $r->refund_date, 0, 10), $r->refund_number, 'Remboursement — '.$r->order_number, Decimal::normalize((string) $r->amount), '0.0000', $r->currency_code, $r->id, 'payment', $r->payment_id);
        }
        foreach (DB::table('customer_returns as r')->join('sales_orders as s', 's.id', '=', 'r.sales_order_id')->where('r.organization_id', $organizationId)
            ->where('s.customer_id', $customerId)->whereIn('s.store_id', $stores)->whereNull('s.sandbox_id')->where('r.status', 'received')
            ->get(['r.id', 'r.received_at', 'r.total_incl_tax', 'r.sales_order_id', 's.order_number', 's.currency_code']) as $r) {
            $date = BusinessTime::toLocal($organization, Carbon::parse((string) $r->received_at, 'UTC'))->toDateString();
            if ($date <= $to) {
                $add('return', $date, 'RET-'.$r->id, 'Retour client — '.$r->order_number, '0.0000', Decimal::normalize((string) $r->total_incl_tax), $r->currency_code, $r->id, 'sales_order', $r->sales_order_id);
            }
        }
        foreach (DB::table('customer_opening_balances')->where('organization_id', $organizationId)->where('customer_id', $customerId)->where('status', 'posted')
            ->whereDate('effective_date', '<=', $to)->get() as $r) {
            $amount = Decimal::normalize((string) $r->amount);
            $negative = Decimal::compare($amount, '0') < 0;
            $add('opening', substr((string) $r->effective_date, 0, 10), $r->reference, 'Solde d’ouverture — '.$r->reason, $negative ? '0.0000' : $amount, $negative ? Decimal::subtract('0', $amount) : '0.0000', $r->currency_code, $r->id);
        }
        $allocated = DB::table('payment_allocations')->where('organization_id', $organizationId)->groupBy('payment_id')->select('payment_id')->selectRaw('SUM(amount) as total');
        foreach (DB::table('payments as p')->leftJoinSub($allocated, 'al', 'al.payment_id', '=', 'p.id')->where('p.organization_id', $organizationId)->where('p.customer_id', $customerId)
            ->whereIn('p.store_id', $stores)->where('p.status', 'posted')->whereDate('p.payment_date', '<=', $to)
            ->get(['p.id', 'p.payment_number', 'p.payment_date', 'p.amount', 'p.currency_code', DB::raw('COALESCE(al.total, 0) as allocated')]) as $r) {
            $left = Decimal::subtract(Decimal::normalize((string) $r->amount), Decimal::normalize((string) $r->allocated));
            if (Decimal::compare($left, '0') > 0) {
                $add('advance', substr((string) $r->payment_date, 0, 10), $r->payment_number, 'Avance non affectée', '0.0000', $left, $r->currency_code, $r->id, 'payment', $r->id);
            }
        }

        if (count($currencies) > 1) {
            return ['customer_id' => $customerId, 'from' => $from, 'to' => $to, 'currency_code' => null, 'opening' => '0.0000', 'closing' => '0.0000', 'total_debit' => '0.0000',
                'total_credit' => '0.0000', 'rows' => [], 'truncated' => false, 'mixed_currency' => true];
        }
        usort($events, fn ($a, $b) => [$a['date'], self::RANK[$a['type']], $a['id']] <=> [$b['date'], self::RANK[$b['type']], $b['id']]);
        $opening = '0.0000';
        $rows = [];
        $debits = '0.0000';
        $credits = '0.0000';
        $balance = '0.0000';
        $truncated = false;
        foreach ($events as $event) {
            $balance = Decimal::subtract(Decimal::add($balance, $event['debit']), $event['credit']);
            if ($event['date'] < $from) {
                $opening = $balance;

                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                $truncated = true;
                break;
            }
            $debits = Decimal::add($debits, $event['debit']);
            $credits = Decimal::add($credits, $event['credit']);
            $rows[] = ['date' => $event['date'], 'type' => $event['type'], 'reference' => $event['reference'], 'description' => $event['description'],
                'debit' => $event['debit'], 'credit' => $event['credit'], 'balance' => $balance, 'doc_type' => $event['docType'], 'doc_id' => $event['docId']];
        }

        return ['customer_id' => $customerId, 'from' => $from, 'to' => $to, 'currency_code' => array_key_first($currencies), 'opening' => $opening, 'closing' => $balance,
            'total_debit' => $debits, 'total_credit' => $credits, 'rows' => $rows, 'truncated' => $truncated, 'mixed_currency' => false];
    }
}
