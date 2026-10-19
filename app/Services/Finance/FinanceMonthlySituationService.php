<?php

namespace App\Services\Finance;

use App\Enums\FinanceObligationCategory;
use App\Enums\FinancePaymentInstrumentState;
use App\Models\FinanceObligation;
use App\Models\FinanceOutgoingPaymentAllocation;
use App\Models\Organization;
use App\Models\Store;
use App\Support\Decimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;

class FinanceMonthlySituationService
{
    private const PAID_SQL = "COALESCE((SELECT SUM(foa.amount) FROM finance_outgoing_payment_allocations foa INNER JOIN finance_outgoing_payments fop ON fop.id = foa.outgoing_payment_id AND fop.organization_id = foa.organization_id WHERE foa.organization_id = finance_obligations.organization_id AND foa.obligation_id = finance_obligations.id AND fop.status = 'posted'), 0)";

    /** @param array<string, mixed> $filters */
    public function rows(Organization $organization, FinancePeriod $period, ?Store $store, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $cutoff = $this->cutoff($period);
        $query = FinanceObligation::query()
            ->where('finance_obligations.organization_id', $organization->getKey())
            ->when($store, fn (Builder $query) => $query->where('finance_obligations.store_id', $store->getKey()))
            ->where(function (Builder $query) use ($period, $cutoff) {
                $query->whereBetween('finance_obligations.due_date', [$period->start->toDateString(), $period->end->toDateString()])
                    ->orWhere(function (Builder $query) use ($period, $cutoff) {
                        $query->whereDate('finance_obligations.due_date', '<', $period->start->toDateString())
                            ->whereDate('finance_obligations.due_date', '<', $cutoff->toDateString())
                            ->whereNull('finance_obligations.cancelled_at')
                            ->whereRaw(self::PAID_SQL.' < finance_obligations.planned_amount');
                    });
            })
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('finance_obligations.category', $category))
            ->when($filters['supplier_id'] ?? null, fn (Builder $query, mixed $supplier) => $query->where('finance_obligations.supplier_id', $supplier))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';
                $query->where(function (Builder $query) use ($like) {
                    $query->where('finance_obligations.obligation_number', 'like', $like)
                        ->orWhere('finance_obligations.label', 'like', $like)
                        ->orWhere('finance_obligations.reference', 'like', $like)
                        ->orWhere('finance_obligations.beneficiary_name', 'like', $like)
                        ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', $like));
                });
            });

        $this->applyStatusFilter($query, $filters['status'] ?? null, $cutoff);
        $paginator = $query
            ->select('finance_obligations.*')
            ->selectRaw(self::PAID_SQL.' as paid_amount')
            ->with(['store:id,name,code', 'supplier:id,name', 'preferredFinancialAccount:id,name,code', 'createdBy:id,name', 'cancelledBy:id,name', 'paymentInstruments.financialAccount:id,name,code'])
            ->orderByRaw('CASE WHEN due_date < ? AND cancelled_at IS NULL AND '.self::PAID_SQL.' < planned_amount THEN 0 ELSE 1 END', [$period->start->toDateString()])
            ->orderBy('due_date')->orderBy('id')
            ->paginate($perPage)->withQueryString();

        $ids = $paginator->getCollection()->pluck('id');
        $payments = FinanceOutgoingPaymentAllocation::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('obligation_id', $ids)
            ->with(['outgoingPayment:id,organization_id,store_id,payment_number,financial_account_id,payment_method,amount,currency_code,payment_date,reference,status,reversed_at,reversal_reason', 'outgoingPayment.financialAccount:id,name,code'])
            ->orderBy('id')->get()->groupBy('obligation_id');

        return $paginator->through(fn (FinanceObligation $obligation) => $this->present($obligation, $cutoff, $period, $payments->get($obligation->id, collect())));
    }

    /** @param array<string,mixed> $filters @return LazyCollection<int,array<string,mixed>> */
    public function exportRows(Organization $organization, FinancePeriod $period, ?Store $store, array $filters): LazyCollection
    {
        $cutoff = $this->cutoff($period);
        $query = FinanceObligation::query()->withoutGlobalScopes()
            ->where('finance_obligations.organization_id', $organization->id)
            ->when($store, fn (Builder $query) => $query->where('finance_obligations.store_id', $store->id))
            ->where(function (Builder $query) use ($period, $cutoff) {
                $query->whereBetween('finance_obligations.due_date', [$period->start->toDateString(), $period->end->toDateString()])
                    ->orWhere(fn (Builder $query) => $query->whereDate('finance_obligations.due_date', '<', $period->start->toDateString())
                        ->whereDate('finance_obligations.due_date', '<', $cutoff->toDateString())->whereNull('finance_obligations.cancelled_at')
                        ->whereRaw(self::PAID_SQL.' < finance_obligations.planned_amount'));
            })
            ->when($filters['category'] ?? null, fn (Builder $query, string $value) => $query->where('finance_obligations.category', $value))
            ->when($filters['supplier_id'] ?? null, fn (Builder $query, mixed $value) => $query->where('finance_obligations.supplier_id', $value))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';
                $query->where(fn (Builder $query) => $query->where('obligation_number', 'like', $like)->orWhere('label', 'like', $like)
                    ->orWhere('reference', 'like', $like)->orWhere('beneficiary_name', 'like', $like)
                    ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', $like)));
            });
        $this->applyStatusFilter($query, $filters['status'] ?? null, $cutoff);

        return $query->select('finance_obligations.*')->selectRaw(self::PAID_SQL.' as paid_amount')
            ->with(['store:id,name,code', 'supplier:id,name'])->lazyById(500)
            ->map(fn (FinanceObligation $row) => $this->presentExport($row, $cutoff));
    }

    /** @return array<int,array<string,mixed>> */
    public function futureSupplierObligations(Organization $organization, FinancePeriod $period, ?Store $store, ?int $limit = 10): array
    {
        $query = FinanceObligation::query()->where('organization_id', $organization->id)
            ->whereNull('cancelled_at')->whereDate('due_date', '>', $period->end->toDateString())
            ->when($store, fn (Builder $query) => $query->where('store_id', $store->id))
            ->whereRaw(self::PAID_SQL.' < planned_amount')->with(['supplier:id,name', 'procurement:id,procurement_number'])
            ->select('finance_obligations.*')->selectRaw(self::PAID_SQL.' as paid_amount')->orderBy('due_date');
        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()
            ->map(fn (FinanceObligation $row) => ['id' => $row->id, 'number' => $row->obligation_number,
                'due_date' => $row->due_date->toDateString(), 'supplier' => $row->supplier?->name ?: $row->beneficiary_name ?: '—',
                'reference' => $row->reference, 'amount' => (string) $row->planned_amount, 'currency_code' => $row->currency_code,
                'category' => $row->category->label(), 'status' => 'À venir',
                'source' => $row->procurement?->procurement_number ?: ($row->supplier_installment_plan_id ? 'Échéancier fournisseur' : ($row->recurring_template_id ? 'Charge récurrente' : 'Obligation manuelle'))])->all();
    }

    /** @return array<string, string|int> */
    public function kpis(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        $scope = fn () => FinanceObligation::query()
            ->where('organization_id', $organization->getKey())
            ->when($store, fn (Builder $query) => $query->where('store_id', $store->getKey()));
        $selected = $scope()->whereBetween('due_date', [$period->start->toDateString(), $period->end->toDateString()]);
        $totals = (clone $selected)->whereNull('cancelled_at')->selectRaw('COALESCE(SUM(planned_amount), 0) as planned')->selectRaw('COALESCE(SUM('.self::PAID_SQL.'), 0) as paid')->first();
        $planned = Decimal::normalize((string) $totals->planned);
        $paid = Decimal::normalize((string) $totals->paid);
        $remaining = Decimal::subtract($planned, $paid);

        $cutoff = $this->cutoff($period);
        $overdue = $scope()->whereNull('cancelled_at')
            ->whereDate('due_date', '<', $period->start->toDateString())
            ->whereDate('due_date', '<', $cutoff->toDateString())
            ->whereRaw(self::PAID_SQL.' < planned_amount')
            ->selectRaw('COALESCE(SUM(planned_amount - '.self::PAID_SQL.'), 0) as amount')
            ->selectRaw('COUNT(*) as aggregate_count')->first();

        return [
            'planned' => $planned,
            'paid' => $paid,
            'remaining' => Decimal::compare($remaining, '0.0000') < 0 ? '0.0000' : $remaining,
            'overdue' => Decimal::normalize((string) $overdue->amount),
            'count' => (int) (clone $selected)->count(),
            'overdue_count' => (int) $overdue->aggregate_count,
        ];
    }

    /** @return array<int,array{category:string,label:string,amount:string}> */
    public function remainingByCategory(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        return FinanceObligation::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->whereNull('cancelled_at')
            ->whereDate('due_date', '<=', $period->end->toDateString())
            ->when($store, fn (Builder $query) => $query->where('store_id', $store->getKey()))
            ->whereRaw(self::PAID_SQL.' < planned_amount')
            ->groupBy('category')
            ->select('category')
            ->selectRaw('SUM(planned_amount - '.self::PAID_SQL.') as amount')
            ->orderByDesc('amount')->get()
            ->map(fn ($row) => [
                'category' => (string) $row->getRawOriginal('category'),
                'label' => FinanceObligationCategory::from((string) $row->getRawOriginal('category'))->label(),
                'amount' => Decimal::normalize((string) $row->amount),
            ])->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function supplierSummary(Organization $organization, FinancePeriod $period, ?Store $store): array
    {
        $cutoff = $this->cutoff($period);
        $committedSql = "COALESCE((SELECT SUM(i.amount) FROM finance_payment_instruments i WHERE i.organization_id=finance_obligations.organization_id AND i.obligation_id=finance_obligations.id AND i.state IN ('issued','presented')),0)";
        $query = FinanceObligation::query()->withoutGlobalScopes()
            ->leftJoin('suppliers', function ($join) {
                $join->on('suppliers.id', '=', 'finance_obligations.supplier_id')
                    ->on('suppliers.organization_id', '=', 'finance_obligations.organization_id');
            })
            ->where('finance_obligations.organization_id', $organization->id)
            ->when($store, fn (Builder $query) => $query->where('finance_obligations.store_id', $store->id))
            ->where(function (Builder $query) use ($period, $cutoff) {
                $query->whereBetween('finance_obligations.due_date', [$period->start->toDateString(), $period->end->toDateString()])
                    ->orWhere(fn (Builder $query) => $query->whereDate('finance_obligations.due_date', '<', $period->start->toDateString())
                        ->whereDate('finance_obligations.due_date', '<', $cutoff->toDateString())->whereNull('finance_obligations.cancelled_at')
                        ->whereRaw(self::PAID_SQL.' < finance_obligations.planned_amount'));
            })
            ->groupBy(['finance_obligations.supplier_id', 'finance_obligations.beneficiary_name', 'suppliers.name'])
            ->selectRaw("COALESCE(suppliers.name, finance_obligations.beneficiary_name, '—') as beneficiary")
            ->selectRaw('COALESCE(SUM(CASE WHEN finance_obligations.cancelled_at IS NULL THEN finance_obligations.planned_amount ELSE 0 END),0) as planned')
            ->selectRaw('COALESCE(SUM(CASE WHEN finance_obligations.cancelled_at IS NULL THEN '.self::PAID_SQL.' ELSE 0 END),0) as paid')
            ->selectRaw('COALESCE(SUM(CASE WHEN finance_obligations.cancelled_at IS NULL THEN finance_obligations.planned_amount - '.self::PAID_SQL.' ELSE 0 END),0) as remaining')
            ->selectRaw('COALESCE(SUM(CASE WHEN finance_obligations.cancelled_at IS NULL AND finance_obligations.due_date < ? AND '.self::PAID_SQL.' < finance_obligations.planned_amount THEN finance_obligations.planned_amount - '.self::PAID_SQL.' ELSE 0 END),0) as overdue', [$cutoff->toDateString()])
            ->selectRaw('COALESCE(SUM(CASE WHEN finance_obligations.cancelled_at IS NULL THEN '.$committedSql.' ELSE 0 END),0) as committed')
            ->selectRaw('MIN(CASE WHEN finance_obligations.cancelled_at IS NULL THEN finance_obligations.due_date ELSE NULL END) as next_due_date')
            ->selectRaw('SUM(CASE WHEN finance_obligations.cancelled_at IS NULL THEN 1 ELSE 0 END) as obligation_count')
            ->orderBy('beneficiary')
            ->get();

        return $query->map(fn ($row) => [
            'beneficiary' => $row->beneficiary,
            'planned' => Decimal::normalize((string) $row->planned), 'paid' => Decimal::normalize((string) $row->paid),
            'remaining' => Decimal::normalize((string) $row->remaining), 'overdue' => Decimal::normalize((string) $row->overdue),
            'committed' => Decimal::normalize((string) $row->committed), 'next_due_date' => $row->next_due_date ? substr((string) $row->next_due_date, 0, 10) : null,
            'obligation_count' => (int) $row->obligation_count,
        ])->all();
    }

    private function cutoff(FinancePeriod $period): Carbon
    {
        $today = now()->startOfDay();

        return $today->lt($period->end) ? $today : $period->end->copy()->startOfDay();
    }

    private function applyStatusFilter(Builder $query, ?string $status, Carbon $cutoff): void
    {
        match ($status) {
            'cancelled' => $query->whereNotNull('cancelled_at'),
            'paid' => $query->whereNull('cancelled_at')->whereRaw(self::PAID_SQL.' >= planned_amount'),
            'overdue' => $query->whereNull('cancelled_at')->whereDate('due_date', '<', $cutoff->toDateString())->whereRaw(self::PAID_SQL.' < planned_amount'),
            'partial' => $query->whereNull('cancelled_at')->whereDate('due_date', '>=', $cutoff->toDateString())->whereRaw(self::PAID_SQL.' > 0')->whereRaw(self::PAID_SQL.' < planned_amount'),
            'upcoming' => $query->whereNull('cancelled_at')->whereDate('due_date', '>=', $cutoff->toDateString())->whereRaw(self::PAID_SQL.' = 0'),
            default => null,
        };
    }

    private function present(FinanceObligation $obligation, Carbon $cutoff, FinancePeriod $period, $payments): array
    {
        $planned = Decimal::normalize((string) $obligation->planned_amount);
        $paid = Decimal::normalize((string) ($obligation->getAttribute('paid_amount') ?? '0'));
        $remaining = Decimal::subtract($planned, $paid);
        if (Decimal::compare($remaining, '0.0000') < 0) {
            $remaining = '0.0000';
        }
        if ($obligation->cancelled_at) {
            [$status, $label] = ['cancelled', 'Annulée'];
        } elseif (Decimal::compare($remaining, '0.0000') === 0) {
            [$status, $label] = ['paid', 'Payée'];
        } elseif ($obligation->due_date->lt($cutoff)) {
            [$status, $label] = ['overdue', 'En retard'];
        } elseif (Decimal::compare($paid, '0.0000') > 0) {
            [$status, $label] = ['partial', 'Partiellement payée'];
        } else {
            [$status, $label] = ['upcoming', 'À venir'];
        }

        return [
            'id' => $obligation->id,
            'number' => $obligation->obligation_number,
            'category' => $obligation->category->value,
            'category_label' => $obligation->category->label(),
            'label' => $obligation->label,
            'store' => $obligation->store?->only(['id', 'name', 'code']),
            'supplier' => $obligation->supplier?->only(['id', 'name']),
            'supplier_id' => $obligation->supplier_id,
            'beneficiary_name' => $obligation->beneficiary_name,
            'beneficiary' => $obligation->supplier?->name ?: $obligation->beneficiary_name ?: '—',
            'reference' => $obligation->reference,
            'recurring_template_id' => $obligation->recurring_template_id,
            'recurring_period' => $obligation->recurring_period?->format('Y-m'),
            'due_date' => $obligation->due_date->toDateString(),
            'planned_amount' => $planned,
            'paid_amount' => $paid,
            'remaining_amount' => $remaining,
            'currency_code' => $obligation->currency_code,
            'planned_payment_method' => $obligation->planned_payment_method?->value,
            'preferred_financial_account_id' => $obligation->preferred_financial_account_id,
            'preferred_financial_account' => $obligation->preferredFinancialAccount?->only(['id', 'name', 'code']),
            'notes' => $obligation->notes,
            'status' => $status,
            'status_label' => $label,
            'carry_forward' => $obligation->due_date->lt($period->start),
            'created_by' => $obligation->createdBy?->name,
            'created_at' => $obligation->created_at?->toIso8601String(),
            'cancelled_at' => $obligation->cancelled_at?->toIso8601String(),
            'cancelled_by' => $obligation->cancelledBy?->name,
            'cancellation_reason' => $obligation->cancellation_reason,
            'payments' => $payments->map(fn ($allocation) => [
                'allocation_id' => $allocation->id,
                'amount' => (string) $allocation->amount,
                'number' => $allocation->outgoingPayment->payment_number,
                'date' => $allocation->outgoingPayment->payment_date->toDateString(),
                'method' => $allocation->outgoingPayment->payment_method->value,
                'status' => $allocation->outgoingPayment->status->value,
                'reference' => $allocation->outgoingPayment->reference,
                'account' => $allocation->outgoingPayment->financialAccount?->only(['id', 'name', 'code']),
                'payment_id' => $allocation->outgoingPayment->id,
                'reversal_reason' => $allocation->outgoingPayment->reversal_reason,
            ])->values()->all(),
            'committed_amount' => $obligation->paymentInstruments->whereIn('state', [FinancePaymentInstrumentState::Issued, FinancePaymentInstrumentState::Presented])->reduce(fn (string $sum, $row) => Decimal::add($sum, (string) $row->amount), '0.0000'),
            'instruments' => $obligation->paymentInstruments->map(function ($instrument) use ($cutoff) {
                $status = match ($instrument->state) {
                    FinancePaymentInstrumentState::Cleared => ['cleared', 'Payé / Compensé'],
                    FinancePaymentInstrumentState::Cancelled => ['cancelled', 'Annulé'],
                    FinancePaymentInstrumentState::Returned => ['returned', 'Retourné'],
                    FinancePaymentInstrumentState::Presented => ['presented', 'Présenté'],
                    default => $instrument->maturity_date->lt($cutoff) ? ['overdue', 'En retard'] : ($instrument->maturity_date->isSameDay($cutoff) ? ['due', 'À échéance'] : ['upcoming', 'À venir']),
                };

                return ['id' => $instrument->id, 'number' => $instrument->instrument_number, 'type' => $instrument->type->value,
                    'external_reference' => $instrument->external_reference, 'amount' => (string) $instrument->amount,
                    'issue_date' => $instrument->issue_date->toDateString(), 'maturity_date' => $instrument->maturity_date->toDateString(),
                    'state' => $instrument->state->value, 'status' => $status[0], 'status_label' => $status[1],
                    'account' => $instrument->financialAccount?->only(['id', 'name', 'code'])];
            })->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function presentExport(FinanceObligation $row, Carbon $cutoff): array
    {
        $planned = Decimal::normalize((string) $row->planned_amount);
        $paid = Decimal::normalize((string) ($row->getAttribute('paid_amount') ?? '0'));
        $remaining = Decimal::subtract($planned, $paid);
        if (Decimal::compare($remaining, '0.0000') < 0) {
            $remaining = '0.0000';
        }
        $status = $row->cancelled_at ? 'Annulée' : (Decimal::compare($remaining, '0.0000') === 0 ? 'Payée'
            : ($row->due_date->lt($cutoff) ? 'En retard' : (Decimal::compare($paid, '0.0000') > 0 ? 'Partiellement payée' : 'À venir')));

        return ['id' => $row->id, 'due_date' => $row->due_date->toDateString(), 'number' => $row->obligation_number, 'label' => $row->label,
            'category' => $row->category->label(), 'beneficiary' => $row->supplier?->name ?: $row->beneficiary_name ?: '—',
            'supplier_id' => $row->supplier_id, 'reference' => $row->reference, 'planned' => $planned, 'paid' => $paid,
            'remaining' => $remaining, 'status' => $status, 'store' => $row->store?->name ?? 'Organisation entière'];
    }
}
