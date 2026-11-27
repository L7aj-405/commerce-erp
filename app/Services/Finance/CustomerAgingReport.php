<?php

namespace App\Services\Finance;

use App\Models\Organization;
use App\Models\Store;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Receivable aging by customer, as of a date (Finance clients: "Balance âgée", the aging report export and the credit-risk view).
 *
 * Built on FinanceReceivablesService::customerPositionsAsOf(): the SAME order-specific operands as every other receivable
 * figure, so the report reconciles with the account pages and the dashboard. Two disjoint categories are summed per band:
 *  - invoice receivables (issued invoices, due date = invoices.due_date), and
 *  - unbilled commercial exposure (confirmed orders without an issued invoice, due date = sales_orders.due_date).
 * Bands: not due, 1-30, 31-60, 61-90, over 90 days past due, and a SEPARATE "undated" band (no due date is ever invented).
 * Basis date = $asOf. Scope = the whole organization (or one store); only customers linked to a customer record are listed.
 * Amounts are never added across currencies: totals are per currency.
 */
final class CustomerAgingReport
{
    public const BANDS = ['not_due', '1_30', '31_60', '61_90', '90_plus', 'undated'];

    public const PER_PAGE = 50;

    public function __construct(private readonly FinanceReceivablesService $receivables) {}

    /**
     * EVERY customer is evaluated (chunked by id, constant memory per chunk): totals and the row count never depend on the page shown.
     * `$page = null` returns every row (exports); otherwise only that page of rows is returned, with the grand totals of the whole report.
     *
     * @return array{as_of:string, rows:list<array<string,mixed>>, totals:array<string,array<string,string>>, truncated:bool, total_rows:int, page:int, pages:int, per_page:int}
     */
    public function build(Organization $organization, ?Store $store, Carbon $asOf, ?int $page = null, int $perPage = self::PER_PAGE): array
    {
        $organizationId = (int) $organization->getKey();
        $storeIds = $store ? [(int) $store->getKey()] : DB::table('stores')->where('organization_id', $organizationId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $rows = [];
        $totals = [];
        DB::table('customers')->where('organization_id', $organizationId)->orderBy('id')->select(['id', 'display_name', 'company_name'])
            ->chunkById(1000, function ($customers) use ($organization, $storeIds, $asOf, &$rows, &$totals) {
                $this->accumulate($organization, $customers, $storeIds, $asOf, $rows, $totals);
            });
        usort($rows, fn ($a, $b) => Decimal::compare((string) ($b['total'] ?? '0'), (string) ($a['total'] ?? '0')) ?: strcmp((string) $a['customer'], (string) $b['customer']));
        $count = count($rows);
        $perPage = max(1, $perPage);
        $pages = max(1, intdiv($count + $perPage - 1, $perPage));
        $page = $page === null ? null : min(max(1, $page), $pages);

        return ['as_of' => $asOf->toDateString(), 'rows' => $page === null ? $rows : array_slice($rows, ($page - 1) * $perPage, $perPage), 'totals' => $totals, 'truncated' => false,
            'total_rows' => $count, 'page' => $page ?? 1, 'pages' => $page === null ? 1 : $pages, 'per_page' => $perPage];
    }

    /** @param  Collection<int, object>  $customers */
    private function accumulate(Organization $organization, $customers, array $storeIds, Carbon $asOf, array &$rows, array &$totals): void
    {
        $positions = $this->receivables->customerPositionsAsOf($organization, $customers->pluck('id')->all(), $storeIds, $asOf, $asOf);
        foreach ($customers as $customer) {
            $p = $positions[$customer->id] ?? null;
            if ($p === null || $p['mixed_currency']) {
                if ($p !== null) {
                    $rows[] = ['customer_id' => $customer->id, 'customer' => $customer->display_name, 'currency_code' => null, 'mixed_currency' => true, 'receivable' => null, 'unbilled' => null, 'total' => null, 'overdue' => null, 'bands' => null];
                }

                continue;
            }
            $bands = [
                'not_due' => Decimal::add($p['not_due'], $p['unbilled_not_due']),
                '1_30' => Decimal::add($p['band_1_30'], $p['unbilled_band_1_30']),
                '31_60' => Decimal::add($p['band_31_60'], $p['unbilled_band_31_60']),
                '61_90' => Decimal::add($p['band_61_90'], $p['unbilled_band_61_90']),
                '90_plus' => Decimal::add($p['band_90_plus'], $p['unbilled_band_90_plus']),
                'undated' => Decimal::add($p['undated'], $p['unbilled_undated']),
            ];
            $total = Decimal::add($p['receivable'], $p['unbilled']);
            if (Decimal::compare($total, '0') <= 0) {
                continue;
            }
            $currency = (string) $p['currency_code'];
            $overdue = Decimal::add(Decimal::add(Decimal::add($bands['1_30'], $bands['31_60']), $bands['61_90']), $bands['90_plus']);
            $rows[] = ['customer_id' => $customer->id, 'customer' => $customer->display_name, 'currency_code' => $currency, 'mixed_currency' => false,
                'receivable' => $p['receivable'], 'unbilled' => $p['unbilled'], 'total' => $total, 'overdue' => $overdue, 'bands' => $bands];
            $totals[$currency] ??= array_fill_keys(array_merge(['receivable', 'unbilled', 'total', 'overdue'], self::BANDS), '0.0000');
            foreach (['receivable' => $p['receivable'], 'unbilled' => $p['unbilled'], 'total' => $total, 'overdue' => $overdue] + $bands as $key => $value) {
                $totals[$currency][$key] = Decimal::add($totals[$currency][$key], $value);
            }
        }
    }
}
