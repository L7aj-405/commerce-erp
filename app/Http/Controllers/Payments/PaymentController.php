<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\RecordSalesOrderPaymentsAction;
use App\Actions\Payments\ReversePaymentAction;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\RecordSalesOrderPaymentsRequest;
use App\Models\FinancialAccount;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Services\ActiveTenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $store = $context->storeOrFail();
        $this->authorize('viewAny', [Payment::class, $organization, $store]);
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'account' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where(fn ($query) => $query->where('organization_id', $organization->getKey()))],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $payments = Payment::query()
            ->where('organization_id', $organization->getKey())
            ->where('store_id', $store->getKey())
            ->when($filters['date'] ?? null, fn ($query, string $date) => $query->whereDate('payment_date', $date))
            ->when($filters['method'] ?? null, fn ($query, string $method) => $query->where('method', $method))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['account'] ?? null, fn ($query, int $account) => $query->where('financial_account_id', $account))
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('payment_number', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")
                ->orWhereHas('allocations.salesOrder', fn ($query) => $query
                    ->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_company', 'like', "%{$search}%"))))
            ->with([
                'financialAccount:id,name,code',
                'allocations.salesOrder:id,order_number,customer_name,customer_company',
                'receivedBy:id,name',
            ])
            ->latest('payment_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Payments/Index', [
            'payments' => $payments,
            'filters' => $filters,
            'accounts' => FinancialAccount::query()->where('organization_id', $organization->getKey())->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function show(Payment $payment): Response
    {
        $this->authorize('view', $payment);

        return Inertia::render('Payments/Show', [
            'payment' => $payment->load([
                'store:id,name,code',
                'financialAccount:id,name,code,type',
                'allocations.salesOrder:id,order_number,customer_name,customer_company,total_incl_tax,currency_code',
                'receivedBy:id,name',
                'reversedBy:id,name',
            ]),
            'can' => ['reverse' => request()->user()->can('reverse', $payment)],
        ]);
    }

    public function store(RecordSalesOrderPaymentsRequest $request, SalesOrder $order, RecordSalesOrderPaymentsAction $action): RedirectResponse
    {
        $this->authorize('create', [Payment::class, $order]);
        $data = $request->validated();
        $action->execute($request->user(), $order, $data['payments'], $data['client_operation_id']);

        return redirect()->route('sales.orders.show', $order);
    }

    public function reverse(Request $request, Payment $payment, ReversePaymentAction $action): RedirectResponse
    {
        $this->authorize('reverse', $payment);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $action->execute($request->user(), $payment, $data['reason']);

        return back();
    }
}
