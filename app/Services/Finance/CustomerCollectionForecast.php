<?php

namespace App\Services\Finance;

use App\Models\Organization;
use App\Models\Store;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customer collection forecast and schedule view (Finance clients "Échéances" / "Prévisions").
 *
 * Source of every amount: the order balance (FinanceReceivablesService::orderExposureQuery, the order-specific receivable
 * rule). Each open order's remaining balance is counted EXACTLY ONCE, by this precedence:
 *   1. its active payment-schedule installments (the oldest installments absorb what the order has received since the
 *      schedule was made; what is still outstanding sits on each installment's own due date);
 *   2. else its open payment promises (capped at the remaining balance, on the promised dates);
 *   3. else the order due date stamped at confirmation (an order with no due date is "undated", never dated by guess).
 * Whatever is not covered by (1) or (2) falls back to (3). Pending incoming instruments and the manual expected collections of
 * the month-end situation are NOT added: they describe the SAME money and would double count it. Instruments are reported
 * separately for information. Buckets are relative to `$today`: overdue (before today), w1..w4 (the next four 7-day windows),
 * later, undated. Amounts per currency, exact decimals.
 */
final class CustomerCollectionForecast
{
    public const BUCKETS = ['overdue', 'w1', 'w2', 'w3', 'w4', 'later', 'undated'];

    private const CAP = 5000;

    public function __construct(private readonly FinanceReceivablesService $receivables) {}

    /**
     * @return array{today:string, by_currency:array<string,array<string,string>>, installments:list<array<string,mixed>>, instruments:list<array<string,mixed>>, truncated:bool}
     */
    public function build(Organization $organization, ?Store $store, Carbon $today, ?int $customerId = null): array
    {
        $organizationId = (int) $organization->getKey();
        $query = $this->receivables->orderExposureQuery($organization, Carbon::parse('2999-12-31'), $store)->reorder()->orderBy('sales_orders.id');
        if ($customerId !== null) {
            $query->where('sales_orders.customer_id', $customerId);
        }
        $orders = $query->limit(self::CAP + 1)->get();
        $truncated = $orders->count() > self::CAP;
        $orders = $orders->take(self::CAP);
        $ids = $orders->pluck('id')->all();

        $installments = $ids === [] ? collect() : DB::table('customer_payment_installments')->where('organization_id', $organizationId)->whereIn('sales_order_id', $ids)->whereNull('cancelled_at')
            ->orderBy('due_date')->orderBy('sequence')->get()->groupBy('sales_order_id');
        $promises = $ids === [] ? collect() : DB::table('customer_payment_promises')->where('organization_id', $organizationId)->whereIn('sales_order_id', $ids)->where('status', 'open')
            ->orderBy('promised_date')->get()->groupBy('sales_order_id');
        $names = $ids === [] ? [] : DB::table('customers')->where('organization_id', $organizationId)->whereIn('id', $orders->pluck('customer_id')->filter()->unique()->all())->pluck('display_name', 'id')->all();

        $by = [];
        $rows = [];
        foreach ($orders as $order) {
            $currency = (string) $order->currency_code;
            $remaining = $this->money($order->outstanding_raw);
            if (Decimal::compare($remaining, '0') <= 0) {
                continue;
            }
            $by[$currency] ??= array_fill_keys(self::BUCKETS, '0.0000');
            $left = $remaining;
            $schedule = $installments->get($order->id, collect());
            if ($schedule->isNotEmpty()) {
                $covered = $schedule->reduce(fn (string $s, $i) => Decimal::add($s, Decimal::normalize((string) $i->amount)), '0.0000');
                $received = Decimal::compare($covered, $remaining) > 0 ? Decimal::subtract($covered, $remaining) : '0.0000';
                foreach ($schedule as $installment) {
                    $amount = Decimal::normalize((string) $installment->amount);
                    $absorbed = Decimal::compare($received, $amount) >= 0 ? $amount : $received;
                    $received = Decimal::subtract($received, $absorbed);
                    $outstanding = Decimal::subtract($amount, $absorbed);
                    if (Decimal::compare($outstanding, '0') > 0) {
                        $date = substr((string) $installment->due_date, 0, 10);
                        $by[$currency][$this->bucket($date, $today)] = Decimal::add($by[$currency][$this->bucket($date, $today)], $outstanding);
                        $left = Decimal::subtract($left, $outstanding);
                        $rows[] = ['customer_id' => $order->customer_id, 'customer' => $names[$order->customer_id] ?? '—', 'order_id' => $order->id, 'order_number' => $order->order_number,
                            'sequence' => (int) $installment->sequence, 'due_date' => $date, 'amount' => $amount, 'outstanding' => $outstanding, 'currency_code' => $currency, 'is_overdue' => $date < $today->toDateString()];
                    }
                }
            } else {
                foreach ($promises->get($order->id, collect()) as $promise) {
                    if (Decimal::compare($left, '0') <= 0) {
                        break;
                    }
                    $take = Decimal::compare(Decimal::normalize((string) $promise->amount), $left) < 0 ? Decimal::normalize((string) $promise->amount) : $left;
                    $date = substr((string) $promise->promised_date, 0, 10);
                    $by[$currency][$this->bucket($date, $today)] = Decimal::add($by[$currency][$this->bucket($date, $today)], $take);
                    $left = Decimal::subtract($left, $take);
                }
            }
            if (Decimal::compare($left, '0') > 0) {
                $date = $order->due_date === null ? null : substr((string) $order->due_date, 0, 10);
                $bucket = $date === null ? 'undated' : $this->bucket($date, $today);
                $by[$currency][$bucket] = Decimal::add($by[$currency][$bucket], $left);
            }
        }

        $instrumentQuery = DB::table('customer_payment_instruments as i')->join('customers as c', 'c.id', '=', 'i.customer_id')->where('i.organization_id', $organizationId)
            ->whereIn('i.state', ['received', 'in_portfolio', 'submitted'])->when($store, fn ($q) => $q->where('i.store_id', $store->getKey()))
            ->when($customerId, fn ($q) => $q->where('i.customer_id', $customerId))->orderBy('i.expected_collection_date')->limit(200)
            ->get(['i.id', 'i.type', 'i.instrument_number', 'i.amount', 'i.currency_code', 'i.expected_collection_date', 'i.maturity_date', 'i.state', 'c.display_name']);

        return [
            'today' => $today->toDateString(), 'by_currency' => $by, 'truncated' => $truncated,
            'installments' => $rows,
            'instruments' => $instrumentQuery->map(fn ($i) => ['id' => $i->id, 'customer' => $i->display_name, 'type' => $i->type, 'number' => $i->instrument_number, 'amount' => Decimal::normalize((string) $i->amount),
                'currency_code' => $i->currency_code, 'expected_date' => $i->expected_collection_date, 'maturity_date' => $i->maturity_date, 'state' => $i->state])->all(),
        ];
    }

    private function bucket(string $date, Carbon $today): string
    {
        $day = $today->toDateString();
        if ($date < $day) {
            return 'overdue';
        }
        foreach (['w1' => 7, 'w2' => 14, 'w3' => 21, 'w4' => 28] as $bucket => $days) {
            if ($date <= $today->copy()->addDays($days - 1)->toDateString()) {
                return $bucket;
            }
        }

        return 'later';
    }

    private function money(mixed $value): string
    {
        if (is_float($value)) {
            return Decimal::normalize(sprintf('%.4F', $value));
        }

        return Decimal::normalize((string) bcadd((string) $value, '0', 4));
    }
}
