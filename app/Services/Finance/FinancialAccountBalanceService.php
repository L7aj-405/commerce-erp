<?php

namespace App\Services\Finance;

use App\Models\Organization;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class FinancialAccountBalanceService
{
    /**
     * Opening positions are start-of-day balances. Posted movements whose
     * business date equals the effective date are therefore included.
     *
     * @return array{as_of:string,currency_code:string,configured_count:int,unconfigured_count:int,complete:bool,total:?string,reason:?string,accounts:array<int,array<string,mixed>>}
     */
    public function organizationPosition(Organization $organization, CarbonInterface $asOf, ?int $accountId = null): array
    {
        $date = $asOf->toDateString();
        $accounts = DB::table('financial_accounts as fa')
            ->leftJoin('financial_account_opening_positions as op', function ($join) {
                $join->on('op.organization_id', '=', 'fa.organization_id')->on('op.financial_account_id', '=', 'fa.id');
            })
            ->where('fa.organization_id', $organization->getKey())
            ->when($accountId, fn ($query) => $query->where('fa.id', $accountId), fn ($query) => $query->where('fa.status', 'active'))
            ->select(['fa.id','fa.name','fa.code','fa.status','fa.currency_code','op.effective_date','op.amount as opening_amount'])
            ->selectRaw("COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.organization_id=fa.organization_id AND p.financial_account_id=fa.id AND p.status='posted' AND DATE(p.payment_date) BETWEEN DATE(op.effective_date) AND ?),0) as incoming", [$date])
            ->selectRaw("COALESCE((SELECT SUM(r.amount) FROM payment_refunds r WHERE r.organization_id=fa.organization_id AND r.financial_account_id=fa.id AND r.status='posted' AND DATE(r.refund_date) BETWEEN DATE(op.effective_date) AND ?),0) as refunds", [$date])
            ->selectRaw("COALESCE((SELECT SUM(o.amount) FROM finance_outgoing_payments o WHERE o.organization_id=fa.organization_id AND o.financial_account_id=fa.id AND o.status='posted' AND DATE(o.payment_date) BETWEEN DATE(op.effective_date) AND ?),0) as outgoing", [$date])
            ->orderBy('fa.name')->get();

        $rows = $accounts->map(function ($account) use ($date) {
            $effectiveDate = $account->effective_date === null ? null : substr((string) $account->effective_date, 0, 10);
            $configured = $effectiveDate !== null && $effectiveDate <= $date;
            $balance = $configured ? Decimal::subtract(Decimal::subtract(Decimal::add((string) $account->opening_amount, (string) $account->incoming), (string) $account->refunds), (string) $account->outgoing) : null;
            return ['id' => $account->id, 'name' => $account->name, 'code' => $account->code, 'status' => $account->status,
                'currency_code' => $account->currency_code, 'configured' => $configured, 'effective_date' => $effectiveDate,
                'opening_amount' => $account->opening_amount === null ? null : Decimal::normalize((string) $account->opening_amount),
                'incoming' => $configured ? Decimal::normalize((string) $account->incoming) : null,
                'refunds' => $configured ? Decimal::normalize((string) $account->refunds) : null,
                'outgoing' => $configured ? Decimal::normalize((string) $account->outgoing) : null,
                'balance' => $balance, 'last_activity' => null];
        });
        $configured = $rows->where('configured', true);
        $currencies = $configured->pluck('currency_code')->unique()->values();
        $complete = $accounts->isNotEmpty() && $configured->count() === $accounts->count() && $currencies->count() <= 1;
        $reason = $accounts->isEmpty() ? 'no_accounts' : ($configured->count() !== $accounts->count() ? 'unconfigured_accounts' : ($currencies->count() > 1 ? 'mixed_currencies' : null));

        return [
            'as_of' => $date,
            'currency_code' => (string) ($currencies->first() ?: config('platform.currency_code', 'MAD')),
            'configured_count' => $configured->count(),
            'unconfigured_count' => $accounts->count() - $configured->count(),
            'complete' => $complete,
            'total' => $complete ? $configured->reduce(fn (string $sum, array $row) => Decimal::add($sum, $row['balance']), '0.0000') : null,
            'reason' => $reason,
            'accounts' => $rows->values()->all(),
        ];
    }

}
