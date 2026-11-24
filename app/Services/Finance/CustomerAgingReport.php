<?php

namespace App\Services\Finance;

use App\Models\Organization;
use App\Models\Store;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
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

    private const CAP = 5000;

    public function __construct(private readonly FinanceReceivablesService $receivables) {}

    /**
     * @return array{as_of:string, rows:list<array<string,mixed>>, totals:array<string,array<string,string>>, truncated:bool}
     */
    public function build(Organization $organization, ?Store $store, Carbon $asOf): array
    {
        $organizationId = (int) $organization->getKey();
        $storeIds = $store ? [(int) $store->getKey()] : DB::table('stores')->where('organization_id', $organizationId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $customers = DB::table('customers')->where('organization_id', $organizationId)->orderBy('display_name')->orderBy('id')->limit(self::CAP + 1)->get(['id', 'display_name', 'company_name']);
        $truncated = $customers->count() > self::CAP;
        $customers = $customers->take(self::CAP);

        $positions = [];
        foreach ($customers->pluck('id')->chunk(1000) as $chunk) {
            $positions += $this->receivables->customerPositionsAsOf($organization, $chunk->all(), $storeIds, $asOf, $asOf);
        }
        $rows = [];
        $totals = [];
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
        usort($rows, fn ($a, $b) => Decimal::compare((string) ($b['total'] ?? '0'), (string) ($a['total'] ?? '0')) ?: strcmp((string) $a['customer'], (string) $b['customer']));

        return ['as_of' => $asOf->toDateString(), 'rows' => $rows, 'totals' => $totals, 'truncated' => $truncated];
    }
}
