<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\ManageFinanceObligationAction;
use App\Actions\Finance\RecordFinanceOutgoingPaymentAction;
use App\Actions\Finance\ReverseFinanceOutgoingPaymentAction;
use App\Enums\FinanceObligationCategory;
use App\Enums\PaymentMethod;
use App\Enums\FinancePaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\FinanceObligation;
use App\Models\FinanceOutgoingPayment;
use App\Models\FinanceRecurringObligationTemplate;
use App\Models\FinancialAccount;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SalesOrder;
use App\Models\PaymentAllocation;
use App\Services\ActiveTenantContext;
use App\Services\Finance\FinanceAccessGuard;
use App\Services\Finance\FinanceMonthlySituationService;
use App\Services\Finance\FinanceMonthReviewService;
use App\Services\Finance\FinancePeriod;
use App\Services\Finance\FinanceTreasuryForecastService;
use App\Support\Decimal;
use App\Services\Security\FreshAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FinanceMonthlySituationController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, FinanceMonthlySituationService $situation, FinanceTreasuryForecastService $treasury, FinanceMonthReviewService $reviews): Response
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeMonthlySituation($request->user(), $organization);
        $period = $request->query('month') ? FinancePeriod::fromMonth($request->query('month')) : FinancePeriod::current();
        $store = $guard->resolveStore($organization, $request->query('store_id'));
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'store_id' => ['nullable', 'integer'],
            'category' => ['nullable', Rule::enum(FinanceObligationCategory::class)],
            'status' => ['nullable', Rule::in(['upcoming', 'partial', 'paid', 'overdue', 'cancelled'])],
            'supplier_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $expectedRows = $treasury->expectedRows($organization, $period, $store);
        $orderIds = collect($expectedRows)->pluck('sales_order_id')->unique();
        $allocationOptions = PaymentAllocation::query()->where('organization_id', $organization->id)->whereIn('sales_order_id', $orderIds)
            ->whereHas('payment', fn ($query) => $query->where('status', 'posted'))
            ->with(['payment:id,organization_id,payment_number,payment_date,status'])
            ->withSum('expectedCollectionMatches as matched_amount', 'amount')->get()
            ->map(function ($allocation) {
                $available = Decimal::subtract((string) $allocation->amount, (string) ($allocation->matched_amount ?? '0'));
                return ['id' => $allocation->id, 'sales_order_id' => $allocation->sales_order_id, 'number' => $allocation->payment->payment_number,
                    'date' => $allocation->payment->payment_date->toDateString(), 'available' => $available];
            })->filter(fn ($row) => Decimal::compare($row['available'], '0.0000') > 0)->values();

        $paidSql = "COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa INNER JOIN payments p ON p.id=pa.payment_id AND p.organization_id=pa.organization_id WHERE pa.sales_order_id=sales_orders.id AND pa.organization_id=sales_orders.organization_id AND p.status='posted'),0)";
        $refundSql = "COALESCE((SELECT SUM(pr.amount) FROM payment_refunds pr WHERE pr.sales_order_id=sales_orders.id AND pr.organization_id=sales_orders.organization_id AND pr.status='posted'),0)";
        $receivableOrders = SalesOrder::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->whereNotIn('status', ['draft','cancelled'])
            ->select(['id','store_id','order_number','customer_id','total_incl_tax','currency_code'])->selectRaw("total_incl_tax - ({$paidSql} - {$refundSql}) as outstanding")
            ->whereRaw("total_incl_tax - ({$paidSql} - {$refundSql}) > 0")->with('customer:id,display_name,company_name')->orderByDesc('id')->limit(250)->get()
            ->map(fn ($order) => ['id' => $order->id, 'number' => $order->order_number, 'customer' => $order->customer?->company_name ?: $order->customer?->display_name ?: '—',
                'outstanding' => Decimal::normalize((string) $order->outstanding), 'currency_code' => $order->currency_code]);

        return Inertia::render('Finance/MonthlySituation', [
            'organization' => $organization->only(['id', 'name']),
            'period' => $period->month,
            'periodLabel' => $period->label(),
            'storeId' => $store?->id,
            'filters' => $filters,
            'stores' => Store::query()->where('organization_id', $organization->id)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'suppliers' => Supplier::query()->where('organization_id', $organization->id)->where('active', true)->orderBy('name')->get(['id', 'name']),
            'financialAccounts' => FinancialAccount::query()->where('organization_id', $organization->id)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code', 'currency_code']),
            'categories' => collect(FinanceObligationCategory::cases())->map(fn ($case) => ['value' => $case->value, 'label' => $case->label()]),
            'paymentMethods' => collect(FinancePaymentMethod::cases())->map(fn ($case) => ['value' => $case->value, 'label' => $case->label()]),
            'currencyCode' => config('platform.currency_code', 'MAD'),
            'kpis' => $situation->kpis($organization, $period, $store),
            'obligations' => $situation->rows($organization, $period, $store, $filters),
            'futureObligations' => $situation->futureSupplierObligations($organization, $period, $store),
            'treasury' => $treasury->forecast($organization, $period, $store),
            'expectedCollections' => $expectedRows,
            'receivableOrders' => $receivableOrders,
            'collectionAllocationOptions' => $allocationOptions,
            'recurringTemplatesCount' => FinanceRecurringObligationTemplate::query()->where('organization_id', $organization->id)->where('active', true)->count(),
            'monthReview' => $reviews->state($organization, $period, $store),
            'can' => [
                'manage' => $request->user()->hasPermission($organization, 'finance.monthly_situation.manage'),
                'pay' => $request->user()->hasPermission($organization, 'finance.outgoing_payments.manage'),
                'export' => $request->user()->hasPermission($organization, 'finance.monthly_situation.export'),
            ],
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, ManageFinanceObligationAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeMonthlySituationManage($request->user(), $organization);
        $action->create($request->user(), $organization, $request->validate($this->obligationRules()));
        return back()->with('success', 'Obligation ajoutée.');
    }

    public function update(Request $request, FinanceObligation $obligation, FinanceAccessGuard $guard, ManageFinanceObligationAction $action): RedirectResponse
    {
        $guard->authorizeMonthlySituationManage($request->user(), $obligation->organization);
        $action->update($request->user(), $obligation, $request->validate($this->obligationRules()));
        return back()->with('success', 'Obligation mise à jour.');
    }

    public function cancel(Request $request, FinanceObligation $obligation, FinanceAccessGuard $guard, ManageFinanceObligationAction $action): RedirectResponse
    {
        $guard->authorizeMonthlySituationManage($request->user(), $obligation->organization);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $action->cancel($request->user(), $obligation, $data['reason']);
        return back()->with('success', 'Obligation annulée.');
    }

    public function payment(Request $request, ActiveTenantContext $context, FinanceAccessGuard $guard, FreshAuthentication $fresh, RecordFinanceOutgoingPaymentAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $guard->authorizeOutgoingPaymentManage($request->user(), $organization);
        $fresh->ensure($request, FreshAuthentication::LEVEL_ACCOUNT);
        $data = $request->validate([
            'store_id' => ['nullable', 'integer'],
            'financial_account_id' => ['required', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'beneficiary_name' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', Rule::enum(FinancePaymentMethod::class)],
            'amount' => ['required', 'decimal:0,4', 'gt:0'],
            'currency_code' => ['required', 'string', 'size:3'],
            'payment_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => ['required', 'uuid'],
            'allocations' => ['required', 'array', 'min:1', 'max:100'],
            'allocations.*.obligation_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'decimal:0,4', 'gt:0'],
        ]);
        $action->execute($request->user(), $organization, $data);
        return back()->with('success', 'Paiement sortant enregistré.');
    }

    public function reverse(Request $request, FinanceOutgoingPayment $outgoingPayment, FinanceAccessGuard $guard, FreshAuthentication $fresh, ReverseFinanceOutgoingPaymentAction $action): RedirectResponse
    {
        $guard->authorizeOutgoingPaymentManage($request->user(), $outgoingPayment->organization);
        $fresh->ensure($request, FreshAuthentication::LEVEL_ACCOUNT);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $action->execute($request->user(), $outgoingPayment, $data['reason']);
        return back()->with('success', 'Paiement sortant inversé.');
    }

    private function obligationRules(): array
    {
        return [
            'store_id' => ['nullable', 'integer'],
            'category' => ['required', Rule::enum(FinanceObligationCategory::class)],
            'label' => ['required', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'integer'],
            'beneficiary_name' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'due_date' => ['required', 'date'],
            'planned_amount' => ['required', 'decimal:0,4', 'gt:0'],
            'currency_code' => ['required', 'string', 'size:3'],
            'planned_payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'preferred_financial_account_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
