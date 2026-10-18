<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\ManageFinanceSupplierInstallmentPlanAction;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\FinanceObligation;
use App\Models\FinanceSupplierInstallmentPlan;
use App\Models\FinancialAccount;
use App\Models\SalesOrderProcurement;
use App\Models\Store;
use App\Models\Supplier;
use App\Services\ActiveTenantContext;
use App\Services\Finance\FinanceAccessGuard;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FinanceSupplierInstallmentController extends Controller
{
    private const PAID = "COALESCE((SELECT SUM(a.amount) FROM finance_outgoing_payment_allocations a INNER JOIN finance_outgoing_payments p ON p.id=a.outgoing_payment_id AND p.organization_id=a.organization_id INNER JOIN finance_obligations o ON o.id=a.obligation_id AND o.organization_id=a.organization_id WHERE o.supplier_installment_plan_id=finance_supplier_installment_plans.id AND p.status='posted'),0)";
    private const COMMITTED = "COALESCE((SELECT SUM(i.amount) FROM finance_payment_instruments i INNER JOIN finance_obligations o ON o.id=i.obligation_id AND o.organization_id=i.organization_id WHERE o.supplier_installment_plan_id=finance_supplier_installment_plans.id AND i.state IN ('issued','presented')),0)";

    public function index(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard): Response
    {
        $organization = $context->organizationOrFail(); $guard->authorizeMonthlySituation($request->user(), $organization);
        $filters = $request->validate(['supplier_id' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:255']]);
        $plans = FinanceSupplierInstallmentPlan::query()->where('organization_id', $organization->id)
            ->when($filters['supplier_id'] ?? null, fn (Builder $query, $id) => $query->where('supplier_id', $id))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) { $like = '%'.addcslashes(trim($search), '%_\\').'%'; $query->where(fn ($q) => $q->where('plan_number', 'like', $like)->orWhere('reference', 'like', $like)->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $like))); })
            ->with(['supplier:id,name', 'procurement:id,procurement_number', 'createdBy:id,name'])->withCount('obligations')
            ->select('finance_supplier_installment_plans.*')->selectRaw(self::PAID.' as paid_amount')->selectRaw(self::COMMITTED.' as committed_amount')
            ->selectRaw("COALESCE((SELECT SUM(o.planned_amount-COALESCE((SELECT SUM(a.amount) FROM finance_outgoing_payment_allocations a INNER JOIN finance_outgoing_payments p ON p.id=a.outgoing_payment_id AND p.organization_id=a.organization_id WHERE a.obligation_id=o.id AND p.status='posted'),0)) FROM finance_obligations o WHERE o.organization_id=finance_supplier_installment_plans.organization_id AND o.supplier_installment_plan_id=finance_supplier_installment_plans.id AND o.cancelled_at IS NULL AND o.due_date < ? AND COALESCE((SELECT SUM(a2.amount) FROM finance_outgoing_payment_allocations a2 INNER JOIN finance_outgoing_payments p2 ON p2.id=a2.outgoing_payment_id AND p2.organization_id=a2.organization_id WHERE a2.obligation_id=o.id AND p2.status='posted'),0) < o.planned_amount),0) as overdue_amount", [now()->toDateString()])
            ->selectRaw("(SELECT MIN(o.due_date) FROM finance_obligations o WHERE o.organization_id=finance_supplier_installment_plans.organization_id AND o.supplier_installment_plan_id=finance_supplier_installment_plans.id AND o.cancelled_at IS NULL AND COALESCE((SELECT SUM(a.amount) FROM finance_outgoing_payment_allocations a INNER JOIN finance_outgoing_payments p ON p.id=a.outgoing_payment_id AND p.organization_id=a.organization_id WHERE a.obligation_id=o.id AND p.status='posted'),0) < o.planned_amount) as next_due_date")
            ->orderByDesc('created_at')->paginate(20)->withQueryString();
        $plans->through(fn ($plan) => ['id' => $plan->id, 'number' => $plan->plan_number, 'supplier' => $plan->supplier?->only(['id','name']),
            'procurement' => $plan->procurement?->only(['id','procurement_number']), 'reference' => $plan->reference,
            'total' => (string) $plan->agreed_total, 'paid' => Decimal::normalize((string) $plan->paid_amount),
            'remaining' => Decimal::subtract((string) $plan->agreed_total, (string) $plan->paid_amount), 'committed' => Decimal::normalize((string) $plan->committed_amount),
            'overdue' => Decimal::normalize((string) $plan->overdue_amount), 'currency_code' => $plan->currency_code, 'installment_count' => $plan->obligations_count, 'next_due_date' => $plan->next_due_date]);
        return Inertia::render('Finance/SupplierInstallments', $this->props($organization, $request) + ['plans' => $plans, 'filters' => $filters]);
    }

    public function show(Request $request, FinanceSupplierInstallmentPlan $installmentPlan, FinanceAccessGuard $guard): Response
    {
        $guard->authorizeMonthlySituation($request->user(), $installmentPlan->organization);
        $paid = "COALESCE((SELECT SUM(a.amount) FROM finance_outgoing_payment_allocations a INNER JOIN finance_outgoing_payments p ON p.id=a.outgoing_payment_id AND p.organization_id=a.organization_id WHERE a.obligation_id=finance_obligations.id AND p.status='posted'),0)";
        $rows = FinanceObligation::query()->where('organization_id', $installmentPlan->organization_id)->where('supplier_installment_plan_id', $installmentPlan->id)
            ->select('finance_obligations.*')->selectRaw($paid.' as paid_amount')->with('paymentInstruments')->orderBy('due_date')->paginate(25)->withQueryString()
            ->through(fn ($row) => ['id' => $row->id, 'number' => $row->obligation_number, 'due_date' => $row->due_date->toDateString(), 'amount' => (string) $row->planned_amount,
                'paid' => Decimal::normalize((string) $row->paid_amount), 'remaining' => Decimal::subtract((string) $row->planned_amount, (string) $row->paid_amount),
                'cancelled' => (bool) $row->cancelled_at, 'instrument_count' => $row->paymentInstruments->count()]);
        return Inertia::render('Finance/SupplierInstallmentShow', $this->props($installmentPlan->organization, $request) + ['plan' => [
            'id' => $installmentPlan->id, 'number' => $installmentPlan->plan_number, 'supplier' => $installmentPlan->supplier()->firstOrFail()->only(['id','name']),
            'reference' => $installmentPlan->reference, 'total' => (string) $installmentPlan->agreed_total, 'currency_code' => $installmentPlan->currency_code,
            'notes' => $installmentPlan->notes, 'procurement' => $installmentPlan->procurement?->only(['id','procurement_number']), 'obligations' => $rows,
        ]]);
    }

    public function store(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, ManageFinanceSupplierInstallmentPlanAction $action): RedirectResponse
    { $organization = $context->organizationOrFail(); $guard->authorizeMonthlySituationManage($request->user(), $organization); $action->create($request->user(), $organization, $request->validate($this->rules())); return back()->with('success', 'Échéancier fournisseur créé.'); }
    public function update(Request $request, FinanceSupplierInstallmentPlan $installmentPlan, FinanceAccessGuard $guard, ManageFinanceSupplierInstallmentPlanAction $action): RedirectResponse
    { $guard->authorizeMonthlySituationManage($request->user(), $installmentPlan->organization); $action->update($request->user(), $installmentPlan, $request->validate($this->rules())); return back()->with('success', 'Échéancier fournisseur mis à jour.'); }

    private function rules(): array
    { return ['supplier_id' => ['required','integer'], 'sales_order_procurement_id' => ['nullable','integer'], 'store_id' => ['nullable','integer'], 'label' => ['nullable','string','max:255'],
        'reference' => ['nullable','string','max:255'], 'agreed_total' => ['required','decimal:0,4','gt:0'], 'currency_code' => ['required','string','size:3'],
        'planned_payment_method' => ['nullable', Rule::enum(PaymentMethod::class)], 'preferred_financial_account_id' => ['nullable','integer'], 'notes' => ['nullable','string','max:5000'],
        'installments' => ['required','array','min:1','max:100'], 'installments.*.due_date' => ['required','date'], 'installments.*.amount' => ['required','decimal:0,4','gt:0']]; }

    private function props($organization, Request $request): array
    { return ['organization' => $organization->only(['id','name']), 'suppliers' => Supplier::query()->where('organization_id',$organization->id)->where('active',true)->orderBy('name')->get(['id','name']),
        'stores' => Store::query()->where('organization_id',$organization->id)->where('status','active')->orderBy('name')->get(['id','name','code']),
        'accounts' => FinancialAccount::query()->where('organization_id',$organization->id)->where('status','active')->orderBy('name')->get(['id','name','code','currency_code']),
        'procurements' => SalesOrderProcurement::query()->where('organization_id',$organization->id)->orderByDesc('id')->limit(250)->get(['id','procurement_number','supplier_id']),
        'currencyCode' => config('platform.currency_code','MAD'), 'canManage' => $request->user()->hasPermission($organization,'finance.monthly_situation.manage')]; }
}
