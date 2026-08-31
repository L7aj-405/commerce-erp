<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\CreateSalesOrderAction;
use App\Actions\Sales\UpdateSalesOrderAction;
use App\Enums\SalesOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Services\ActiveTenantContext;
use App\Services\SalesOrderPaymentCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SalesOrderController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $store = $context->storeOrFail();
        $this->authorize('viewAny', [SalesOrder::class, $organization, $store]);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::enum(SalesOrderStatus::class)],
            'sale_date' => ['nullable', 'date'],
        ]);
        $orders = SalesOrder::query()->where('organization_id', $organization->getKey())->where('store_id', $store->getKey())
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('order_number', 'like', "%{$search}%")->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhere('customer_company', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['sale_date'] ?? null, fn ($query, string $date) => $query->whereDate('sale_date', $date))
            ->with('store:id,name,code')->latest('ordered_at')->paginate(20)->withQueryString();

        return Inertia::render('Sales/Orders/Index', ['orders' => $orders, 'filters' => $filters]);
    }

    public function create(ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $store = $context->storeOrFail();
        $this->authorize('create', [SalesOrder::class, $organization, $store]);

        return Inertia::render('Sales/Orders/Create', [
            'customers' => $this->customers($organization->getKey()),
            'currencyCode' => config('platform.currency_code', 'MAD'),
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, CreateSalesOrderAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $store = $context->storeOrFail();
        $this->authorize('create', [SalesOrder::class, $organization, $store]);
        $order = $action->execute($request->user(), $organization, $store, $request->validate($this->headerRules($organization->getKey())));

        return redirect()->route('sales.orders.edit', $order);
    }

    public function show(SalesOrder $order, SalesOrderPaymentCalculator $payments): Response
    {
        $this->authorize('view', $order);

        $canRecordPayment = request()->user()->can('create', [Payment::class, $order]);

        return Inertia::render('Sales/Orders/Show', [
            'order' => $this->loadOrder($order),
            'paymentSummary' => $payments->summary($order),
            'payments' => $order->paymentAllocations()
                ->with(['payment.financialAccount:id,name,code,type', 'payment.receivedBy:id,name'])
                ->latest('id')
                ->get()
                ->map(fn ($allocation) => [
                    ...$allocation->payment->only(['id', 'payment_number', 'method', 'status', 'amount', 'currency_code', 'payment_date', 'reference']),
                    'allocated_amount' => $allocation->amount,
                    'financial_account' => $allocation->payment->financialAccount,
                    'received_by' => $allocation->payment->receivedBy,
                ]),
            'financialAccounts' => $canRecordPayment
                ? FinancialAccount::query()
                    ->where('organization_id', $order->organization_id)
                    ->where('status', 'active')
                    ->where('currency_code', $order->currency_code)
                    ->orderBy('name')
                    ->get(['id', 'name', 'code', 'type', 'currency_code'])
                : [],
            'documents' => [
                'invoices' => $order->invoices()->latest('id')->get(['id', 'invoice_number', 'invoice_date', 'status', 'total_incl_tax']),
                'deliveryNotes' => $order->deliveryNotes()->latest('id')->get(['id', 'delivery_note_number', 'delivery_date', 'status']),
            ],
            'can' => [
                ...$this->abilities(request(), $order),
                'recordPayment' => $canRecordPayment,
                'backdatePayment' => request()->user()->hasPermission($order->organization_id, 'payments.backdate'),
                'createInvoice' => request()->user()->can('create', [Invoice::class, $order]),
                'createDeliveryNote' => request()->user()->can('create', [DeliveryNote::class, $order]),
            ],
        ]);
    }

    public function edit(Request $request, SalesOrder $order): Response
    {
        $this->authorize('update', $order);
        $filters = $request->validate(['catalog_search' => ['nullable', 'string', 'max:255']]);

        return Inertia::render('Sales/Orders/Edit', [
            'order' => $this->loadOrder($order), 'customers' => $this->customers($order->organization_id),
            'warehouses' => $order->organization->warehouses()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'taxRates' => $order->organization->taxRates()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'rate']),
            'catalogResults' => $this->catalog($order->organization_id, $filters['catalog_search'] ?? null),
            'filters' => $filters, 'can' => $this->abilities($request, $order),
        ]);
    }

    public function update(Request $request, SalesOrder $order, UpdateSalesOrderAction $action): RedirectResponse
    {
        $this->authorize('update', $order);
        $action->execute($request->user(), $order, $request->validate($this->headerRules($order->organization_id)));

        return back();
    }

    /** @return array<string, mixed> */
    private function headerRules(int $organizationId): array
    {
        return [
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)->where('status', 'active'))],
            'sale_date' => ['required', 'date'], 'currency_code' => ['required', 'string', 'size:3', 'uppercase'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function customers(int $organizationId)
    {
        return Customer::query()->where('organization_id', $organizationId)->where('status', 'active')->orderBy('display_name')->limit(500)->get(['id', 'display_name', 'company_name']);
    }

    private function catalog(int $organizationId, ?string $search)
    {
        if (! $search) {
            return [];
        }

        return ProductVariant::query()->where('organization_id', $organizationId)->where('status', 'active')
            ->whereHas('product', fn ($query) => $query->where('status', 'active'))
            ->where(fn ($query) => $query->where('sku', 'like', "%{$search}%")->orWhere('reference', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")->orWhereHas('product', fn ($query) => $query->where('name', 'like', "%{$search}%")))
            ->with(['product:id,name,default_unit_id', 'product.defaultUnit:id,name,symbol', 'taxRate:id,name,rate'])
            ->orderBy('sku')->limit(20)->get(['id', 'product_id', 'label', 'sku', 'reference', 'barcode', 'default_sale_price', 'tax_rate_id']);
    }

    private function loadOrder(SalesOrder $order): SalesOrder
    {
        return $order->load(['store:id,name,code', 'customer:id,display_name', 'lines.allocations.warehouse:id,name,code', 'lines.allocations.inventoryReservation:id,status']);
    }

    /** @return array<string, bool> */
    private function abilities(Request $request, SalesOrder $order): array
    {
        return [
            'update' => $request->user()->can('update', $order), 'confirm' => $request->user()->can('confirm', $order),
            'cancel' => $request->user()->can('cancel', $order), 'fulfill' => $request->user()->can('fulfill', $order),
            'overridePrice' => $request->user()->hasPermission($order->organization_id, 'sales_orders.override_price'),
            'applyDiscount' => $request->user()->hasPermission($order->organization_id, 'sales_orders.apply_discount'),
        ];
    }
}
