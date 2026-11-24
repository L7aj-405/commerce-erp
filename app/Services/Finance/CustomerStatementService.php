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
 * Period-based COMMERCIAL customer account statement: opening balance, dated movements, closing balance.
 *
 * It is the order-based commercial position (confirmed orders, what was collected against them), NOT the invoiced receivable.
 * The result therefore also carries a `breakdown` that keeps the other notions apart, all as of the statement end date:
 *   commercial_balance  = closing (what the customer owes on confirmed orders, net of advances and opening balances)
 *   invoiced_receivable = open balance of ISSUED invoices            (FinanceReceivablesService, same figure as the receivables pages)
 *   unbilled            = open balance of confirmed orders without an issued invoice
 *   advances_available  = customer money received and not allocated yet
 *   refund_obligation   = money kept above what the customer owes (a debt of the organization, never a negative receivable)
 *
 * Sign convention: DEBIT = increases what the customer owes, CREDIT = decreases it. Balance = debits - credits.
 *   Debits : confirmed sales orders (TTC, sale date), refunds paid to the customer (refund date), positive opening balances,
 *            the application of an advance to an order (reverses the advance credit, see below).
 *   Credits: payment allocations (at their EFFECTIVE date), customer returns received (TTC, received date), the customer advance
 *            at its receipt date, negative opening balances, the restoration of an advance when an allocation is released.
 *
 * Advances are dated by two independent dates. The treasury receipt is a credit at the payment date for the part not allocated AT
 * RECEIPT. Allocating it later (effective date) credits the order and debits the advance by the same amount, so the customer-level
 * balance does not move and a closed period is never rewritten; releasing an allocation reverses both on the release date.
 * Everything is derived from dated events (live allocations + payment_allocation_releases), never from today's allocation total,
 * so a statement for a past period is reproducible after later allocations, reversals and corrections.
 *
 * Credit notes are not statement lines: in this domain a credit note can only be created from a customer return
 * (credit_notes.customer_return_id is mandatory and CreateCustomerReturnAction is the only creator), so its economic effect is
 * the return already counted; counting both would double count. The breakdown reports any issued credit note whose return is not
 * received (should be zero) so a violation of that guarantee is visible instead of silently ignored.
 *
 * Totals (opening, debit, credit, closing) always cover the WHOLE period; pagination only slices the displayed rows. Exports pass
 * no page and receive every row. Scope is the viewer's stores/permissions. Mixed currencies are refused (no silent mixing).
 */
final class CustomerStatementService
{
    private const RANK = ['opening' => 0, 'sale' => 1, 'refund' => 2, 'return' => 3, 'payment' => 4, 'advance' => 5, 'advance_applied' => 6, 'release' => 7, 'advance_restored' => 8];

    public const PER_PAGE = 100;

    public function __construct(private readonly DocumentVisibility $visibility, private readonly FinanceReceivablesService $receivables) {}

    public function allowed(Organization $organization, User $viewer): bool
    {
        return $this->visibility->canView($viewer, $organization, SalesOrder::class) && $this->visibility->canView($viewer, $organization, Payment::class);
    }

    /**
     * @param  ?int  $page  1-based page of rows to return, or null for EVERY row (exports)
     * @return array<string,mixed>|null
     */
    public function build(Organization $organization, Customer $customer, User $viewer, string $from, string $to, ?int $page = null, int $perPage = self::PER_PAGE): ?array
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
        $zero = '0.0000';

        foreach (DB::table('sales_orders')->where('organization_id', $organizationId)->where('customer_id', $customerId)->whereIn('store_id', $stores)
            ->whereNull('sandbox_id')->where('status', 'confirmed')->whereDate('sale_date', '<=', $to)
            ->get(['id', 'order_number', 'sale_date', 'total_incl_tax', 'currency_code']) as $r) {
            $add('sale', substr((string) $r->sale_date, 0, 10), $r->order_number, 'Commande confirmée', Decimal::normalize((string) $r->total_incl_tax), $zero, $r->currency_code, $r->id, 'sales_order', $r->id);
        }

        // Allocation intervals: live rows (still allocated) and released rows (de-allocated on released_date).
        $base = fn ($table, $alias, $amountDate) => DB::table("{$table} as {$alias}")->join('payments as p', 'p.id', '=', "{$alias}.payment_id")->join('sales_orders as s', 's.id', '=', "{$alias}.sales_order_id")
            ->where("{$alias}.organization_id", $organizationId)->where('s.customer_id', $customerId)->whereIn('s.store_id', $stores)->whereIn('p.store_id', $stores)
            ->whereNull('s.sandbox_id')->where('p.status', 'posted');
        $intervals = [];
        foreach ($base('payment_allocations', 'a', null)->get(['a.id', 'a.amount', 'a.effective_date', 'p.id as payment_id', 'p.payment_number', 'p.payment_date', 'p.currency_code', 'p.customer_id as p_customer', 's.order_number']) as $r) {
            $intervals[] = ['key' => 'a'.$r->id, 'amount' => Decimal::normalize((string) $r->amount), 'eff' => substr((string) ($r->effective_date ?: $r->payment_date), 0, 10), 'released' => null, 'payment_id' => $r->payment_id,
                'number' => $r->payment_number, 'pay_date' => substr((string) $r->payment_date, 0, 10), 'currency' => $r->currency_code, 'advance' => $r->p_customer !== null, 'order' => $r->order_number, 'id' => (int) $r->id];
        }
        foreach ($base('payment_allocation_releases', 'a', null)->get(['a.id', 'a.amount', 'a.effective_date', 'a.released_date', 'p.id as payment_id', 'p.payment_number', 'p.payment_date', 'p.currency_code', 'p.customer_id as p_customer', 's.order_number']) as $r) {
            $intervals[] = ['key' => 'r'.$r->id, 'amount' => Decimal::normalize((string) $r->amount), 'eff' => substr((string) $r->effective_date, 0, 10), 'released' => substr((string) $r->released_date, 0, 10), 'payment_id' => $r->payment_id,
                'number' => $r->payment_number, 'pay_date' => substr((string) $r->payment_date, 0, 10), 'currency' => $r->currency_code, 'advance' => $r->p_customer !== null, 'order' => $r->order_number, 'id' => 1000000000 + (int) $r->id];
        }
        // Advances applied to opening balances: same dated mechanics as order allocations (credit the line on its effective date).
        foreach (DB::table('customer_opening_balance_settlements as a')->join('payments as p', 'p.id', '=', 'a.payment_id')->join('customer_opening_balances as o', 'o.id', '=', 'a.opening_balance_id')
            ->where('a.organization_id', $organizationId)->where('a.customer_id', $customerId)->whereIn('p.store_id', $stores)->where('p.status', 'posted')
            ->get(['a.id', 'a.amount', 'a.effective_date', 'a.released_date', 'p.id as payment_id', 'p.payment_number', 'p.payment_date', 'p.currency_code', 'o.reference as opening_reference']) as $r) {
            $intervals[] = ['key' => 'o'.$r->id, 'amount' => Decimal::normalize((string) $r->amount), 'eff' => substr((string) $r->effective_date, 0, 10), 'released' => $r->released_date ? substr((string) $r->released_date, 0, 10) : null,
                'payment_id' => $r->payment_id, 'number' => $r->payment_number, 'pay_date' => substr((string) $r->payment_date, 0, 10), 'currency' => $r->currency_code, 'advance' => true,
                'order' => 'solde d’ouverture '.$r->opening_reference, 'id' => 2000000000 + (int) $r->id];
        }
        $atReceipt = [];
        foreach ($intervals as $i) {
            if ($i['eff'] <= $to) {
                $later = $i['advance'] && $i['eff'] > $i['pay_date'];
                $add('payment', $i['eff'], $i['number'], ($later ? 'Avance affectée à ' : 'Règlement affecté à ').$i['order'], $zero, $i['amount'], $i['currency'], $i['id'], 'payment', $i['payment_id']);
                if ($later) {
                    $add('advance_applied', $i['eff'], $i['number'], 'Imputation de l’avance sur '.$i['order'], $i['amount'], $zero, $i['currency'], $i['id'], 'payment', $i['payment_id']);
                }
            }
            if ($i['released'] !== null && $i['released'] <= $to) {
                $add('release', $i['released'], $i['number'], 'Affectation retirée — '.$i['order'], $i['amount'], $zero, $i['currency'], $i['id'], 'payment', $i['payment_id']);
                if ($i['advance']) {
                    $add('advance_restored', $i['released'], $i['number'], 'Avance rétablie après retrait', $zero, $i['amount'], $i['currency'], $i['id'], 'payment', $i['payment_id']);
                }
            }
            if ($i['advance'] && $i['eff'] <= $i['pay_date']) {
                $atReceipt[$i['payment_id']] = Decimal::add($atReceipt[$i['payment_id']] ?? $zero, $i['amount']);
            }
        }
        // Customer advances: credit the part not allocated AT RECEIPT on the receipt date (later allocations/releases are their own events).
        foreach (DB::table('payments')->where('organization_id', $organizationId)->where('customer_id', $customerId)->whereIn('store_id', $stores)->where('status', 'posted')
            ->whereDate('payment_date', '<=', $to)->get(['id', 'payment_number', 'payment_date', 'amount', 'currency_code']) as $r) {
            $left = Decimal::subtract(Decimal::normalize((string) $r->amount), $atReceipt[$r->id] ?? $zero);
            if (Decimal::compare($left, '0') > 0) {
                $add('advance', substr((string) $r->payment_date, 0, 10), $r->payment_number, 'Avance reçue', $zero, $left, $r->currency_code, $r->id, 'payment', $r->id);
            }
        }
        foreach (DB::table('payment_refunds as f')->join('sales_orders as s', 's.id', '=', 'f.sales_order_id')->where('f.organization_id', $organizationId)
            ->where('s.customer_id', $customerId)->whereIn('s.store_id', $stores)->whereNull('s.sandbox_id')->where('f.status', 'posted')->whereDate('f.refund_date', '<=', $to)
            ->get(['f.id', 'f.refund_number', 'f.refund_date', 'f.amount', 'f.currency_code', 'f.payment_id', 's.order_number']) as $r) {
            $add('refund', substr((string) $r->refund_date, 0, 10), $r->refund_number, 'Remboursement — '.$r->order_number, Decimal::normalize((string) $r->amount), $zero, $r->currency_code, $r->id, 'payment', $r->payment_id);
        }
        foreach (DB::table('customer_returns as r')->join('sales_orders as s', 's.id', '=', 'r.sales_order_id')->where('r.organization_id', $organizationId)
            ->where('s.customer_id', $customerId)->whereIn('s.store_id', $stores)->whereNull('s.sandbox_id')->where('r.status', 'received')
            ->get(['r.id', 'r.received_at', 'r.total_incl_tax', 'r.sales_order_id', 's.order_number', 's.currency_code']) as $r) {
            $date = BusinessTime::toLocal($organization, Carbon::parse((string) $r->received_at, 'UTC'))->toDateString();
            if ($date <= $to) {
                $add('return', $date, 'RET-'.$r->id, 'Retour client — '.$r->order_number, $zero, Decimal::normalize((string) $r->total_incl_tax), $r->currency_code, $r->id, 'sales_order', $r->sales_order_id);
            }
        }
        foreach (DB::table('customer_opening_balances')->where('organization_id', $organizationId)->where('customer_id', $customerId)->where('status', 'posted')
            ->whereDate('effective_date', '<=', $to)->get() as $r) {
            $amount = Decimal::normalize((string) $r->amount);
            $negative = Decimal::compare($amount, '0') < 0;
            $add('opening', substr((string) $r->effective_date, 0, 10), $r->reference, 'Solde d’ouverture — '.$r->reason, $negative ? $zero : $amount, $negative ? Decimal::subtract('0', $amount) : $zero, $r->currency_code, $r->id);
        }

        if (count($currencies) > 1) {
            return ['customer_id' => $customerId, 'from' => $from, 'to' => $to, 'currency_code' => null, 'opening' => $zero, 'closing' => $zero, 'total_debit' => $zero,
                'total_credit' => $zero, 'rows' => [], 'total_rows' => 0, 'page' => 1, 'pages' => 1, 'per_page' => $perPage, 'truncated' => false, 'mixed_currency' => true, 'breakdown' => null];
        }
        usort($events, fn ($a, $b) => [$a['date'], self::RANK[$a['type']], $a['id']] <=> [$b['date'], self::RANK[$b['type']], $b['id']]);
        $opening = $zero;
        $all = [];
        $debits = $zero;
        $credits = $zero;
        $balance = $zero;
        $advances = $zero;
        foreach ($events as $event) {
            $balance = Decimal::subtract(Decimal::add($balance, $event['debit']), $event['credit']);
            if (in_array($event['type'], ['advance', 'advance_applied', 'advance_restored'], true)) {
                $advances = Decimal::add($advances, Decimal::subtract($event['credit'], $event['debit']));
            }
            if ($event['date'] < $from) {
                $opening = $balance;

                continue;
            }
            $debits = Decimal::add($debits, $event['debit']);
            $credits = Decimal::add($credits, $event['credit']);
            $all[] = ['date' => $event['date'], 'type' => $event['type'], 'reference' => $event['reference'], 'description' => $event['description'],
                'debit' => $event['debit'], 'credit' => $event['credit'], 'balance' => $balance, 'doc_type' => $event['docType'], 'doc_id' => $event['docId']];
        }
        $total = count($all);
        $perPage = max(1, $perPage);
        $pages = max(1, intdiv($total + $perPage - 1, $perPage));
        $page = $page === null ? null : min(max(1, $page), $pages);
        $rows = $page === null ? $all : array_slice($all, ($page - 1) * $perPage, $perPage);

        return ['customer_id' => $customerId, 'from' => $from, 'to' => $to, 'currency_code' => array_key_first($currencies), 'opening' => $opening, 'closing' => $balance,
            'total_debit' => $debits, 'total_credit' => $credits, 'rows' => $rows, 'total_rows' => $total, 'page' => $page ?? 1, 'pages' => $page === null ? 1 : $pages, 'per_page' => $perPage,
            'truncated' => false, 'mixed_currency' => false, 'breakdown' => $this->breakdown($organization, $customerId, $stores, $to, $balance, $advances)];
    }

    /** @param list<int> $stores */
    private function breakdown(Organization $organization, int $customerId, array $stores, string $to, string $closing, string $advances): array
    {
        $position = $this->receivables->customerPositionsAsOf($organization, [$customerId], $stores, Carbon::parse($to)->startOfDay())[$customerId] ?? null;
        $badCreditNotes = DB::table('credit_notes as c')->join('customer_returns as r', fn ($j) => $j->on('r.id', '=', 'c.customer_return_id')->on('r.organization_id', '=', 'c.organization_id'))
            ->join('sales_orders as s', fn ($j) => $j->on('s.id', '=', 'c.sales_order_id')->on('s.organization_id', '=', 'c.organization_id'))
            ->where('c.organization_id', $organization->getKey())->where('s.customer_id', $customerId)->whereIn('s.store_id', $stores)->where('c.status', 'issued')
            ->where('r.status', '!=', 'received')->count();

        return [
            'commercial_balance' => $closing,
            'invoiced_receivable' => $position['receivable'] ?? '0.0000',
            'unbilled' => $position['unbilled'] ?? '0.0000',
            'advances_available' => Decimal::compare($advances, '0') > 0 ? $advances : '0.0000',
            'refund_obligation' => $position['overcollected'] ?? '0.0000',
            'credit_notes_without_received_return' => $badCreditNotes,
        ];
    }
}
