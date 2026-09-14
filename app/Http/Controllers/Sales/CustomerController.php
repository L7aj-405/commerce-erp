<?php

namespace App\Http\Controllers\Sales;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SalesOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Services\ActiveTenantContext;
use App\Services\CustomerManager;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [Customer::class, $organization]);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::enum(CustomerStatus::class)]]);
        $customers = Customer::query()->where('organization_id', $organization->getKey())
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('display_name', 'like', "%{$search}%")->orWhere('company_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('display_name')->paginate(20)->withQueryString();

        return Inertia::render('Sales/Customers/Index', ['customers' => $customers, 'filters' => $filters]);
    }

    /**
     * Operational customer profile: identity, a cheap financial summary (a
     * handful of scoped aggregate SUM/COUNT queries — never one per row),
     * and the customer's most recent documents across every store, each
     * linking to its own existing detail page. Never a per-row payment
     * calculator: the paid/outstanding figures are single aggregate queries
     * over payment_allocations, the same pattern FinanceReceivablesService
     * already uses for the exact same "paid so far" question.
     */
    public function show(Customer $customer): Response
    {
        $this->authorize('view', $customer);
        $organizationId = $customer->organization_id;
        $customerId = $customer->getKey();

        $salesTotal = (string) SalesOrder::query()
            ->where('organization_id', $organizationId)
            ->where('customer_id', $customerId)
            ->where('status', SalesOrderStatus::Confirmed->value)
            ->sum('total_incl_tax');

        $invoicedTotal = (string) Invoice::query()
            ->where('organization_id', $organizationId)
            ->where('customer_id', $customerId)
            ->where('status', InvoiceStatus::Issued->value)
            ->sum('total_incl_tax');

        $paidTotal = (string) DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'payment_allocations.sales_order_id')
            ->where('sales_orders.organization_id', $organizationId)
            ->where('sales_orders.customer_id', $customerId)
            ->where('payments.status', PaymentStatus::Posted->value)
            ->sum('payment_allocations.amount');

        $outstanding = Decimal::compare($invoicedTotal, $paidTotal) > 0
            ? Decimal::subtract($invoicedTotal, $paidTotal)
            : '0.0000';

        $lastActivity = SalesOrder::query()
            ->where('organization_id', $organizationId)
            ->where('customer_id', $customerId)
            ->max('sale_date');

        $recentLimit = 15;

        return Inertia::render('Sales/Customers/Show', [
            'customer' => $customer,
            'summary' => [
                'sales_total' => $salesTotal,
                'invoiced_total' => $invoicedTotal,
                'paid_total' => $paidTotal,
                'outstanding' => $outstanding,
                'last_activity' => $lastActivity,
            ],
            'orders' => SalesOrder::query()
                ->where('organization_id', $organizationId)->where('customer_id', $customerId)
                ->orderByDesc('sale_date')->limit($recentLimit)
                ->get(['id', 'order_number', 'sale_date', 'status', 'payment_status', 'total_incl_tax']),
            'ordersCount' => SalesOrder::query()->where('organization_id', $organizationId)->where('customer_id', $customerId)->count(),
            'invoices' => Invoice::query()
                ->where('organization_id', $organizationId)->where('customer_id', $customerId)
                ->orderByDesc('invoice_date')->limit($recentLimit)
                ->get(['id', 'invoice_number', 'invoice_date', 'status', 'total_incl_tax']),
            'invoicesCount' => Invoice::query()->where('organization_id', $organizationId)->where('customer_id', $customerId)->count(),
            'payments' => Payment::query()
                ->where('organization_id', $organizationId)
                ->whereHas('allocations.salesOrder', fn ($query) => $query->where('customer_id', $customerId))
                ->orderByDesc('payment_date')->limit($recentLimit)
                ->get(['id', 'payment_number', 'payment_date', 'method', 'status', 'amount']),
            'paymentsCount' => Payment::query()
                ->where('organization_id', $organizationId)
                ->whereHas('allocations.salesOrder', fn ($query) => $query->where('customer_id', $customerId))
                ->count(),
            'quotations' => Quotation::query()
                ->where('organization_id', $organizationId)->where('customer_id', $customerId)
                ->orderByDesc('quotation_date')->limit($recentLimit)
                ->get(['id', 'quotation_number', 'quotation_date', 'status', 'total_incl_tax']),
            'quotationsCount' => Quotation::query()->where('organization_id', $organizationId)->where('customer_id', $customerId)->count(),
            'deliveryNotes' => DeliveryNote::query()
                ->where('organization_id', $organizationId)
                ->whereHas('salesOrder', fn ($query) => $query->where('customer_id', $customerId))
                ->orderByDesc('delivery_date')->limit($recentLimit)
                ->get(['id', 'delivery_note_number', 'delivery_date', 'status']),
            'deliveryNotesCount' => DeliveryNote::query()
                ->where('organization_id', $organizationId)
                ->whereHas('salesOrder', fn ($query) => $query->where('customer_id', $customerId))
                ->count(),
            'can' => ['update' => request()->user()->can('update', $customer)],
        ]);
    }

    public function create(ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [Customer::class, $organization]);

        return Inertia::render('Sales/Customers/Form', ['customer' => null]);
    }

    public function store(Request $request, ActiveTenantContext $context, CustomerManager $manager): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [Customer::class, $organization]);
        $customer = $manager->create($request->user(), $organization, $request->validate($this->rules()));

        return redirect()->route('sales.customers.show', $customer);
    }

    public function edit(Customer $customer): Response
    {
        $this->authorize('update', $customer);

        return Inertia::render('Sales/Customers/Form', ['customer' => $customer]);
    }

    public function update(Request $request, Customer $customer, CustomerManager $manager): RedirectResponse
    {
        $this->authorize('update', $customer);
        $manager->update($request->user(), $customer, $request->validate($this->rules()));

        return back();
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CustomerType::class)], 'display_name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'], 'tax_identifier' => ['nullable', 'string', 'max:128'],
            'billing_address' => ['nullable', 'string', 'max:5000'], 'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::enum(CustomerStatus::class)],
        ];
    }
}
