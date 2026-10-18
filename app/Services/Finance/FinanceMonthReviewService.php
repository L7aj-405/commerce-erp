<?php

namespace App\Services\Finance;

use App\Models\FinanceMonthReview;
use App\Models\FinanceRecurringObligationTemplate;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Store;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FinanceMonthReviewService
{
    public function __construct(
        private readonly FinanceMonthlySituationService $situation,
        private readonly FinanceTreasuryForecastService $treasury,
        private readonly FinancialAccountBalanceService $balances,
    ) {}

    /** @return array<string,mixed> */
    public function state(Organization $organization, FinancePeriod $period, ?Store $store = null): array
    {
        $review = FinanceMonthReview::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereDate('period', $period->start->toDateString())
            ->with(['reviewedBy:id,name', 'closedBy:id,name', 'reopenedBy:id,name'])
            ->first();

        $warnings = $this->warnings($organization, $period);
        $stale = $review?->status === 'closed' && $review->closed_at
            ? $this->changedAfterClose($organization, $period, $review->closed_at)
            : false;

        return [
            'id' => $review?->id,
            'status' => $review?->status ?? 'open',
            'reviewed_at' => $review?->reviewed_at?->toIso8601String(),
            'reviewed_by' => $review?->reviewedBy?->name,
            'closed_at' => $review?->closed_at?->toIso8601String(),
            'closed_by' => $review?->closedBy?->name,
            'reopened_at' => $review?->reopened_at?->toIso8601String(),
            'reopened_by' => $review?->reopenedBy?->name,
            'note' => $review?->note,
            'stale' => $stale,
            'warnings' => $warnings,
            'can_review' => $period->start->lte(now()->startOfMonth()),
            'can_close' => $period->end->lt(now()->startOfDay()),
            'scope_note' => $store ? 'Le suivi de clôture reste organisationnel malgré le filtre boutique.' : null,
        ];
    }

    /** @return list<array{key:string,message:string}> */
    public function warnings(Organization $organization, FinancePeriod $period): array
    {
        $warnings = [];
        $unconfigured = FinancialAccount::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->id)->where('status', 'active')
            ->whereDoesntHave('openingPosition', fn (Builder $query) => $query->whereDate('effective_date', '<=', $period->end->toDateString()))
            ->count();
        if ($unconfigured > 0) {
            $warnings[] = ['key' => 'opening_positions', 'message' => "{$unconfigured} compte(s) financier(s) actif(s) sans solde initial configuré."];
        }
        $position = $this->balances->organizationPosition($organization, $period->end);
        if (! $position['complete'] && $unconfigured === 0) {
            $message = $position['reason'] === 'mixed_currencies'
                ? 'La position de trésorerie agrégée est indisponible car plusieurs devises sont actives.'
                : 'Aucun compte financier actif ne permet de calculer la position de trésorerie.';
            $warnings[] = ['key' => 'forecast_incomplete', 'message' => $message];
        }

        $missingRecurring = FinanceRecurringObligationTemplate::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->id)->where('active', true)
            ->whereDate('start_date', '<=', $period->end->toDateString())
            ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $period->start->toDateString()))
            ->whereDoesntHave('obligations', fn (Builder $query) => $query->whereDate('recurring_period', $period->start->toDateString()))
            ->count();
        if ($missingRecurring > 0) {
            $warnings[] = ['key' => 'recurring_generation', 'message' => "{$missingRecurring} charge(s) récurrente(s) active(s) ne sont pas générées pour ce mois."];
        }

        return $warnings;
    }

    /** @return array<string,mixed> */
    public function snapshot(Organization $organization, FinancePeriod $period): array
    {
        $kpis = $this->situation->kpis($organization, $period, null);
        $treasury = $this->treasury->forecast($organization, $period, null);
        $expected = collect($this->treasury->expectedRows($organization, $period, null, null))
            ->where('status', '!=', 'cancelled')
            ->reduce(fn (string $sum, array $row) => Decimal::add($sum, $row['remaining']), '0.0000');

        return [
            'planned' => $kpis['planned'], 'paid' => $kpis['paid'], 'remaining' => $kpis['remaining'],
            'overdue' => $kpis['overdue'], 'expected_collections' => $expected,
            'available_balance' => $treasury['balance']['complete'] ? $treasury['balance']['total'] : null,
            'projected' => $treasury['projected'], 'shortage' => $treasury['shortage'],
            'currency_code' => $treasury['currency_code'], 'captured_at' => now()->toIso8601String(),
        ];
    }

    private function changedAfterClose(Organization $organization, FinancePeriod $period, mixed $closedAt): bool
    {
        $org = $organization->id;
        $end = $period->end->toDateString();
        $after = $closedAt;

        $checks = [
            DB::table('finance_obligations')->where('organization_id', $org)->whereDate('due_date', '<=', $end)->where('updated_at', '>', $after),
            DB::table('finance_outgoing_payments')->where('organization_id', $org)->whereDate('payment_date', '<=', $end)->where('updated_at', '>', $after),
            DB::table('finance_outgoing_payment_allocations as a')->join('finance_obligations as o', fn ($join) => $join->on('o.id', '=', 'a.obligation_id')->on('o.organization_id', '=', 'a.organization_id'))
                ->where('a.organization_id', $org)->whereDate('o.due_date', '<=', $end)->where('a.updated_at', '>', $after),
            DB::table('finance_payment_instruments')->where('organization_id', $org)->whereDate('maturity_date', '<=', $end)->where('updated_at', '>', $after),
            DB::table('finance_expected_collections')->where('organization_id', $org)->whereDate('expected_date', '<=', $end)->where('updated_at', '>', $after),
            DB::table('finance_expected_collection_matches as m')->join('finance_expected_collections as e', fn ($join) => $join->on('e.id', '=', 'm.expected_collection_id')->on('e.organization_id', '=', 'm.organization_id'))
                ->where('m.organization_id', $org)->whereDate('e.expected_date', '<=', $end)->where('m.updated_at', '>', $after),
            DB::table('financial_account_opening_positions')->where('organization_id', $org)->whereDate('effective_date', '<=', $end)->where('updated_at', '>', $after),
            DB::table('payments')->where('organization_id', $org)->whereDate('payment_date', '<=', $end)->where('updated_at', '>', $after),
            DB::table('payment_allocations as a')->join('payments as p', fn ($join) => $join->on('p.id', '=', 'a.payment_id')->on('p.organization_id', '=', 'a.organization_id'))
                ->where('a.organization_id', $org)->whereDate('p.payment_date', '<=', $end)->where('a.updated_at', '>', $after),
            DB::table('payment_refunds')->where('organization_id', $org)->whereDate('refund_date', '<=', $end)->where('updated_at', '>', $after),
        ];

        foreach ($checks as $query) {
            if ($query->exists()) return true;
        }

        return false;
    }
}
