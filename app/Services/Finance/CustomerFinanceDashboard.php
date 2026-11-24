<?php

namespace App\Services\Finance;

use App\Models\Organization;
use App\Models\Store;
use App\Services\SalesMarginReportService;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finance clients dashboard payload. It computes NOTHING of its own: every figure is the output of the authoritative service the
 * detail pages use, so the dashboard always reconciles with them for the same period, store and date.
 *
 *  PERIOD FLOWS (what happened between the first and last day of the month)
 *   - sales_ht, sales_ttc_net  : SalesMarginReportService net HT of confirmed sales minus received returns / situation `ventes_nettes`
 *   - billing_net_ttc          : situation `facturation` (issued invoices minus credit notes)
 *   - collections_gross / refunds / collections_net : situation `encaissements` / `remboursements` / `net_encaisse`
 *  BALANCES (position at the END of the period, per currency - never added across currencies)
 *   - receivable, overdue, unbilled, undated : CustomerAgingReport totals (same as "Balance âgée")
 *   - refund_obligations : situation `obligations_remboursement`
 *   - advances, pending_instruments : current unallocated customer advances / instruments not yet cleared (not date-based)
 *  FORWARD LOOK
 *   - expected_collections : CustomerCollectionForecast windows w1..w4 (each balance counted once)
 *   - credit alerts : CustomerCreditGuard::exposures() for customers that have financial terms
 *  TRENDS : the same monthly flow services for the last six months (period flows only).
 */
final class CustomerFinanceDashboard
{
    public function __construct(
        private readonly FinanceMonthlyReportService $report,
        private readonly CustomerAgingReport $aging,
        private readonly CustomerCollectionForecast $forecast,
        private readonly CustomerCreditGuard $credit,
        private readonly SalesMarginReportService $margin,
    ) {}

    /** @return array<string, mixed> */
    public function build(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        $orgId = $organization->getKey();
        $situation = $this->report->situation($organization, $period, $store);
        $aging = $this->aging->build($organization, $store, $period->end);
        $forecast = $this->forecast->build($organization, $store, Carbon::now());

        $balances = [];
        foreach ($aging['totals'] as $currency => $t) {
            $balances[$currency] = ['receivable' => $t['receivable'], 'unbilled' => $t['unbilled'], 'overdue' => $t['overdue'], 'undated' => $t['undated'], 'total' => $t['total']];
        }
        $expected = [];
        foreach ($forecast['by_currency'] as $currency => $buckets) {
            $expected[$currency] = Decimal::add(Decimal::add(Decimal::add($buckets['w1'], $buckets['w2']), $buckets['w3']), $buckets['w4']);
        }
        $advances = $this->perCurrency(DB::table('payments as p')->leftJoinSub(
            DB::table('payment_allocations')->where('organization_id', $orgId)->groupBy('payment_id')->select('payment_id')->selectRaw('SUM(amount) as allocated'), 'a', 'a.payment_id', '=', 'p.id')
            ->where('p.organization_id', $orgId)->where('p.status', 'posted')->whereNotNull('p.customer_id')->when($store, fn ($q) => $q->where('p.store_id', $store->getKey()))
            ->groupBy('p.currency_code')->select('p.currency_code')->selectRaw('ROUND(SUM(p.amount - COALESCE(a.allocated, 0)), 4) as total')->get());
        $instruments = $this->perCurrency(DB::table('customer_payment_instruments')->where('organization_id', $orgId)->whereIn('state', ['received', 'in_portfolio', 'submitted'])
            ->when($store, fn ($q) => $q->where('store_id', $store->getKey()))->groupBy('currency_code')->select('currency_code')->selectRaw('ROUND(SUM(amount), 4) as total')->get());

        $termCustomers = DB::table('customer_financial_terms')->where('organization_id', $orgId)->where(fn ($q) => $q->where('credit_enabled', true)->orWhere('financial_status', 'blocked'))
            ->limit(500)->get(['customer_id', 'credit_enabled', 'credit_limit', 'financial_status']);
        $exposures = $this->credit->exposures($organization, $termCustomers->pluck('customer_id')->map(fn ($id) => (int) $id)->all());
        $over = 0;
        $blocked = 0;
        $withOverdue = 0;
        foreach ($termCustomers as $t) {
            $e = $exposures[$t->customer_id];
            $blocked += $t->financial_status === 'blocked' ? 1 : 0;
            $over += ($t->credit_enabled && $t->credit_limit !== null && Decimal::compare($e['exposure'], Decimal::normalize((string) $t->credit_limit)) > 0) ? 1 : 0;
            $withOverdue += Decimal::compare($e['overdue'], '0') > 0 ? 1 : 0;
        }

        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $p = FinancePeriod::fromMonth($period->start->copy()->subMonthsNoOverflow($i)->format('Y-m'));
            $trend[] = ['month' => $p->month, 'sales' => $this->report->ventesTotal($organization, $p, $store), 'collections' => Decimal::subtract($this->report->encaissementsTotal($organization, $p, $store), $this->report->refundsTotal($organization, $p, $store))];
        }

        return [
            'situation' => $situation,
            'flows' => [
                'sales_ht' => $this->margin->forPeriod($organization, $period->start, $period->end, $store)['net_revenue_excl_tax'],
                'sales_ttc_net' => $situation['ventes_nettes'], 'billing_net_ttc' => $situation['facturation'], 'collections_gross' => $situation['encaissements'],
                'refunds' => $situation['remboursements'], 'collections_net' => $situation['net_encaisse'],
            ],
            'balances' => ['as_of' => $period->end->toDateString(), 'by_currency' => $balances, 'refund_obligations' => $situation['obligations_remboursement'], 'advances' => $advances, 'pending_instruments' => $instruments],
            'expected_collections' => ['by_currency' => $expected, 'window' => '28 jours'],
            'credit_alerts' => ['over_limit' => $over, 'blocked' => $blocked, 'with_overdue' => $withOverdue],
            'trend' => $trend,
            'truncated' => $aging['truncated'] || $forecast['truncated'],
        ];
    }

    /** @param Collection<int, object> $rows @return array<string, string> */
    private function perCurrency($rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->currency_code] = Decimal::normalize((string) $row->total);
        }

        return $out;
    }
}
