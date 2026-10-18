<?php

namespace App\Actions\Finance;

use App\Models\FinanceObligation;
use App\Models\FinanceSupplierInstallmentPlan;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\SalesOrderProcurement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceNumberGenerator;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageFinanceSupplierInstallmentPlanAction
{
    public function __construct(private readonly FinanceNumberGenerator $numbers, private readonly AuditLogger $audit) {}

    /** @param array<string,mixed> $data */
    public function create(User $actor, Organization $organization, array $data): FinanceSupplierInstallmentPlan
    {
        $this->authorize($actor, $organization); $validated = $this->validated($organization, $data);
        return DB::transaction(function () use ($actor, $organization, $data, $validated) {
            $plan = new FinanceSupplierInstallmentPlan; $plan->organization_id = $organization->id;
            $plan->plan_number = $this->numbers->nextSupplierInstallmentPlan($organization, (int) Carbon::parse($validated['first_due'])->format('Y'));
            $this->applyPlan($plan, $actor, $data, $validated['total']); $plan->save();
            $this->writeObligations($plan, $actor, $data, $validated['rows']);
            $this->audit->record('finance_supplier_schedule.created', $actor, $organization, null, $plan, newValues: $this->auditValues($plan));
            return $plan->load('obligations');
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function update(User $actor, FinanceSupplierInstallmentPlan $plan, array $data): FinanceSupplierInstallmentPlan
    {
        $this->authorize($actor, $plan->organization); $validated = $this->validated($plan->organization, $data);
        return DB::transaction(function () use ($actor, $plan, $data, $validated) {
            $plan = FinanceSupplierInstallmentPlan::query()->where('organization_id', $plan->organization_id)->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $obligations = FinanceObligation::query()->where('organization_id', $plan->organization_id)->where('supplier_installment_plan_id', $plan->id)->orderBy('id')->lockForUpdate()->get();
            $hasPaid = DB::table('finance_outgoing_payment_allocations as a')->join('finance_outgoing_payments as p', fn ($join) => $join->on('p.id', 'a.outgoing_payment_id')->on('p.organization_id', 'a.organization_id'))
                ->where('a.organization_id', $plan->organization_id)->whereIn('a.obligation_id', $obligations->pluck('id'))->where('p.status', 'posted')->exists();
            $hasInstruments = DB::table('finance_payment_instruments')->where('organization_id', $plan->organization_id)->whereIn('obligation_id', $obligations->pluck('id'))->exists();
            if ($hasPaid || $hasInstruments) throw ValidationException::withMessages(['installments' => 'Un échéancier engagé ne peut plus être restructuré. Modifiez chaque obligation future selon les règles Finance ou annulez d’abord les instruments.']);
            $old = $this->auditValues($plan); $this->applyPlan($plan, $actor, $data, $validated['total'], false); $plan->save();
            foreach ($validated['rows'] as $index => $row) {
                $obligation = $obligations->get($index);
                if (! $obligation) {
                    $this->writeObligations($plan, $actor, $data, [$row], $index, count($validated['rows']));
                    continue;
                }
                $this->applyObligation($obligation, $plan, $actor, $data, $row, $index, count($validated['rows']));
                $obligation->cancelled_at = null; $obligation->cancelled_by_user_id = null; $obligation->cancellation_reason = null; $obligation->save();
            }
            foreach ($obligations->slice(count($validated['rows'])) as $removed) {
                $removed->cancelled_at = now(); $removed->cancelled_by_user_id = $actor->id;
                $removed->cancellation_reason = 'Retirée lors de la modification de l’échéancier '.$plan->plan_number; $removed->save();
            }
            $this->audit->record('finance_supplier_schedule.updated', $actor, $plan->organization, null, $plan, $old, $this->auditValues($plan));
            return $plan->load('obligations');
        }, 3);
    }

    private function authorize(User $actor, Organization $organization): void
    { abort_unless($organization->status === 'active' && $actor->active_organization_id === $organization->id && $actor->hasPermission($organization, 'finance.monthly_situation.manage'), 403); }

    /** @return array{total:string,rows:array<int,array{due_date:string,amount:string}>,first_due:string} */
    private function validated(Organization $organization, array $data): array
    {
        $supplier = Supplier::query()->where('organization_id', $organization->id)->whereKey($data['supplier_id'])->first();
        if (! $supplier) throw ValidationException::withMessages(['supplier_id' => 'Le fournisseur sélectionné est invalide.']);
        foreach ([Store::class => 'store_id', FinancialAccount::class => 'preferred_financial_account_id'] as $model => $key) {
            if (($data[$key] ?? null) && ! $model::query()->where('organization_id', $organization->id)->whereKey($data[$key])->exists()) throw ValidationException::withMessages([$key => 'La référence sélectionnée est invalide.']);
        }
        if ($procurementId = ($data['sales_order_procurement_id'] ?? null)) {
            $procurement = SalesOrderProcurement::query()->where('organization_id', $organization->id)->whereKey($procurementId)->first();
            if (! $procurement || $procurement->supplier_id !== $supplier->id) throw ValidationException::withMessages(['sales_order_procurement_id' => 'L’approvisionnement doit appartenir à cette organisation et à ce fournisseur.']);
        }
        $total = Decimal::positive($data['agreed_total'], 'agreed_total'); $sum = '0.0000'; $rows = [];
        foreach ($data['installments'] as $row) { $amount = Decimal::positive($row['amount'], 'installments'); $date = Carbon::parse($row['due_date'])->toDateString(); $sum = Decimal::add($sum, $amount); $rows[] = ['due_date' => $date, 'amount' => $amount]; }
        if (Decimal::compare($sum, $total) !== 0) throw ValidationException::withMessages(['installments' => 'La somme des échéances doit correspondre exactement au total convenu.']);
        usort($rows, fn ($a, $b) => strcmp($a['due_date'], $b['due_date']));
        return ['total' => $total, 'rows' => $rows, 'first_due' => $rows[0]['due_date']];
    }

    private function applyPlan(FinanceSupplierInstallmentPlan $plan, User $actor, array $data, string $total, bool $creating = true): void
    {
        $plan->supplier_id = $data['supplier_id']; $plan->sales_order_procurement_id = $data['sales_order_procurement_id'] ?? null;
        $plan->reference = ($data['reference'] ?? null) ?: null; $plan->agreed_total = $total; $plan->currency_code = strtoupper($data['currency_code']);
        $plan->notes = ($data['notes'] ?? null) ?: null; if ($creating) $plan->created_by_user_id = $actor->id;
    }

    private function writeObligations(FinanceSupplierInstallmentPlan $plan, User $actor, array $data, array $rows, int $offset = 0, ?int $total = null): void
    {
        foreach ($rows as $index => $row) {
            $obligation = new FinanceObligation; $obligation->organization_id = $plan->organization_id; $obligation->supplier_installment_plan_id = $plan->id;
            $obligation->sales_order_procurement_id = $plan->sales_order_procurement_id; $obligation->obligation_number = $this->numbers->nextObligation($plan->organization, (int) substr($row['due_date'], 0, 4));
            $this->applyObligation($obligation, $plan, $actor, $data, $row, $offset + $index, $total ?? count($rows)); $obligation->save();
        }
    }

    private function applyObligation(FinanceObligation $obligation, FinanceSupplierInstallmentPlan $plan, User $actor, array $data, array $row, int $index, int $total): void
    {
        $obligation->organization_id = $plan->organization_id; $obligation->supplier_installment_plan_id = $plan->id;
        $obligation->sales_order_procurement_id = $plan->sales_order_procurement_id; $obligation->store_id = $data['store_id'] ?? null;
        $obligation->category = 'supplier'; $obligation->label = ($data['label'] ?? null) ?: 'Échéance fournisseur '.$plan->supplier->name.' '.($index + 1).'/'.$total;
        $obligation->supplier_id = $plan->supplier_id; $obligation->beneficiary_name = null; $obligation->reference = $plan->reference;
        $obligation->due_date = $row['due_date']; $obligation->planned_amount = $row['amount']; $obligation->currency_code = $plan->currency_code;
        $obligation->planned_payment_method = $data['planned_payment_method'] ?? null; $obligation->preferred_financial_account_id = $data['preferred_financial_account_id'] ?? null;
        $obligation->notes = $plan->notes; if (! $obligation->exists) $obligation->created_by_user_id = $actor->id;
    }

    private function auditValues(FinanceSupplierInstallmentPlan $plan): array
    { return ['plan_number' => $plan->plan_number, 'supplier_id' => $plan->supplier_id, 'procurement_id' => $plan->sales_order_procurement_id, 'reference' => $plan->reference, 'agreed_total' => $plan->agreed_total, 'currency_code' => $plan->currency_code, 'installment_count' => $plan->obligations()->count()]; }
}
