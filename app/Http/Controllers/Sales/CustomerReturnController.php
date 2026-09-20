<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Returns\CancelCustomerReturnAction;
use App\Actions\Returns\CreateCustomerReturnAction;
use App\Actions\Returns\ReceiveCustomerReturnAction;
use App\Actions\Returns\RefundCustomerReturnAction;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnLine;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Services\ActiveTenantContext;
use App\Services\ReturnPolicyService;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerReturnController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail(); $store = $context->storeOrFail();
        $this->authorize('viewAny', [CustomerReturn::class, $organization, $store]);
        return Inertia::render('Sales/Returns/Index', [
            'returns' => CustomerReturn::query()->where('organization_id', $organization->id)->where('store_id', $store->id)
                ->with('salesOrder:id,order_number,customer_name')->latest('id')->paginate(25),
            'searchUrl' => route('sales.returns.search'),
        ]);
    }

    public function search(Request $request, ActiveTenantContext $context, ReturnPolicyService $policies): JsonResponse
    {
        $organization = $context->organizationOrFail(); $store = $context->storeOrFail();
        $this->authorize('viewAny', [CustomerReturn::class, $organization, $store]);
        $search = trim((string) $request->validate(['search' => ['nullable', 'string', 'max:255']])['search'] ?? '');
        if ($search === '') return response()->json(['data' => []]);
        $orders = SalesOrder::query()->where('organization_id', $organization->id)->where('store_id', $store->id)->where('fulfillment_status', 'fulfilled')
            ->where(fn ($query) => $query->where('order_number', 'like', "%{$search}%")->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhereHas('invoices', fn ($invoice) => $invoice->where('invoice_number', 'like', "%{$search}%")))
            ->with(['organization', 'store', 'invoices' => fn ($query) => $query->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Superseded->value])->select('id', 'sales_order_id', 'invoice_number')])
            ->latest('fulfilled_at')->limit(15)->get();
        return response()->json(['data' => $orders->map(function (SalesOrder $order) use ($policies) {
            $policy = $policies->evaluate($order);
            return ['id' => $order->id, 'order_number' => $order->order_number, 'customer_name' => $order->customer_name, 'fulfilled_at' => $order->fulfilled_at?->toIso8601String(), 'deadline' => $policy['deadline'], 'eligible' => $policy['within_policy'], 'returns_enabled' => $policy['enabled'], 'invoice_numbers' => $order->invoices->pluck('invoice_number')->filter()->values()];
        })->values()]);
    }

    public function create(Request $request, SalesOrder $order, ReturnPolicyService $policies): Response
    {
        $this->authorize('view', $order);
        abort_unless($request->user()->hasPermission($order->organization_id, 'sales_returns.create'), 403);
        $order->load(['organization', 'store', 'lines']);
        $accepted = CustomerReturnLine::query()->where('organization_id', $order->organization_id)->whereIn('sales_order_line_id', $order->lines->pluck('id'))
            ->whereHas('customerReturn', fn ($query) => $query->whereIn('status', ['draft', 'received']))->selectRaw('sales_order_line_id, SUM(quantity) as quantity')->groupBy('sales_order_line_id')->pluck('quantity', 'sales_order_line_id');
        return Inertia::render('Sales/Returns/Create', [
            'order' => [...$order->only(['id', 'order_number', 'customer_name', 'fulfilled_at', 'currency_code']), 'invoices' => $order->invoices()->whereIn('status', ['issued', 'superseded'])->get(['id', 'invoice_number', 'status'])],
            'policy' => $policies->evaluate($order),
            'lines' => $order->lines->whereNotNull('product_variant_id')->map(fn ($line) => [...$line->only(['id', 'product_name', 'variant_name', 'sku', 'reference', 'unit_label', 'quantity', 'unit_price_incl_tax', 'total_incl_tax']), 'already_returned' => (string) ($accepted[$line->id] ?? '0.0000'), 'returnable' => Decimal::subtract($line->quantity, (string) ($accepted[$line->id] ?? '0.0000'))])->values(),
            'canOverride' => $request->user()->hasPermission($order->organization_id, 'sales_returns.override_policy'),
            'submitUrl' => route('sales.orders.returns.store', $order),
        ]);
    }

    public function store(Request $request, SalesOrder $order, CreateCustomerReturnAction $action): RedirectResponse
    {
        $data = $request->validate([
            'client_operation_id' => ['required', 'uuid'], 'reason' => ['nullable', 'string', 'max:2000'], 'disposition' => ['required', 'in:restock,damaged'],
            'override_policy' => ['sometimes', 'boolean'], 'override_reason' => ['nullable', 'string', 'max:2000'], 'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
        $return = $action->execute($request->user(), $order, $data['lines'], (string) ($data['reason'] ?? ''), $data['disposition'], $data['client_operation_id'], (bool) ($data['override_policy'] ?? false), $data['override_reason'] ?? null);
        return redirect()->route('sales.returns.show', $return)->with('success', 'Retour créé. Le stock ne changera qu’à la réception physique.');
    }

    public function show(Request $request, CustomerReturn $customerReturn): Response
    {
        $this->authorize('view', $customerReturn);
        $customerReturn->load(['salesOrder:id,order_number,customer_name,total_incl_tax', 'warehouse:id,name,code', 'lines', 'creditNotes.invoice:id,invoice_number,version,total_incl_tax', 'refunds.payment:id,payment_number', 'createdBy:id,name', 'receivedBy:id,name']);
        $refunded = $customerReturn->refunds->where('status', 'posted')->reduce(fn ($sum, $refund) => Decimal::add($sum, $refund->amount), '0.0000');
        $remainingRefund = Decimal::subtract($customerReturn->total_incl_tax, $refunded);
        if (Decimal::compare($remainingRefund, '0.0000') < 0) {
            $remainingRefund = '0.0000';
        }
        $credited = $customerReturn->creditNotes->where('status', 'issued')->reduce(fn (string $sum, $note) => Decimal::add($sum, $note->total_incl_tax), '0.0000');
        $refundsByPayment = $customerReturn->salesOrder->paymentRefunds()
            ->where('organization_id', $customerReturn->organization_id)
            ->where('store_id', $customerReturn->store_id)
            ->where('status', 'posted')
            ->selectRaw('payment_id, SUM(amount) as refunded_amount')
            ->groupBy('payment_id')
            ->pluck('refunded_amount', 'payment_id');
        $payments = $customerReturn->salesOrder->paymentAllocations()
            ->where('organization_id', $customerReturn->organization_id)
            ->whereHas('payment', fn ($query) => $query
                ->where('organization_id', $customerReturn->organization_id)
                ->where('store_id', $customerReturn->store_id)
                ->where('status', 'posted'))
            ->with('payment:id,organization_id,store_id,payment_number,method,amount,financial_account_id', 'payment.financialAccount:id,name,code,type')
            ->orderBy('payment_allocations.id')
            ->get()
            ->map(function ($allocation) use ($refundsByPayment, $remainingRefund) {
                $payment = $allocation->payment;
                $refundable = Decimal::subtract($allocation->amount, $refundsByPayment[$payment->id] ?? '0.0000');

                return [...$payment->only(['id', 'payment_number', 'method', 'amount']),
                    'allocated_amount' => $allocation->amount,
                    'refundable_amount' => Decimal::compare($refundable, '0.0000') < 0 ? '0.0000' : $refundable,
                    'suggested_amount' => Decimal::compare($refundable, $remainingRefund) < 0 ? $refundable : $remainingRefund,
                    'financial_account' => $payment->financialAccount?->only(['id', 'name', 'code', 'type']),
                ];
            })->filter(fn (array $payment) => Decimal::compare($payment['refundable_amount'], '0.0000') > 0)->values();
        return Inertia::render('Sales/Returns/Show', [
            'customerReturn' => $customerReturn,
            'creditSummary' => ['issued' => $credited],
            'refundSummary' => ['refunded' => $refunded, 'remaining' => $remainingRefund],
            'payments' => $payments,
            'can' => ['receive' => $request->user()->can('receive', $customerReturn), 'cancel' => $request->user()->can('cancel', $customerReturn), 'refund' => $request->user()->can('refund', $customerReturn)],
        ]);
    }

    public function receive(Request $request, CustomerReturn $customerReturn, ReceiveCustomerReturnAction $action): RedirectResponse
    {
        $this->authorize('receive', $customerReturn); $action->execute($request->user(), $customerReturn);
        return back()->with('success', 'Retour réceptionné. Les mouvements de stock et avoirs ont été comptabilisés.');
    }

    public function cancel(Request $request, CustomerReturn $customerReturn, CancelCustomerReturnAction $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]); $this->authorize('cancel', $customerReturn); $action->execute($request->user(), $customerReturn, $data['reason']);
        return back()->with('success', 'Retour annulé.');
    }

    public function refund(Request $request, CustomerReturn $customerReturn, RefundCustomerReturnAction $action): RedirectResponse
    {
        $data = $request->validate(['payment_id' => ['required', 'integer'], 'amount' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:2000'], 'client_operation_id' => ['required', 'uuid']]);
        $this->authorize('refund', $customerReturn); $payment = Payment::query()->findOrFail($data['payment_id']);
        $action->execute($request->user(), $customerReturn, $payment, (string) $data['amount'], $data['reason'], $data['client_operation_id']);
        return back()->with('success', 'Remboursement enregistré.');
    }
}
