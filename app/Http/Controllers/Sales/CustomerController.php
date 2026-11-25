<?php

namespace App\Http\Controllers\Sales;

use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Services\ActiveTenantContext;
use App\Services\Contacts\LegacyPartyResolver;
use App\Services\CustomerFinancialSummary;
use App\Services\CustomerManager;
use App\Services\DocumentVisibility;
use App\Services\FollowUps\FollowUpQuery;
use App\Services\WhatsAppContactLinkResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response|RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [Customer::class, $organization]);
        // The customer directory is the Clients view of the unified Contacts module. Users without contacts access keep this page.
        if ($request->user()->hasPermission($organization, 'contacts.view')) {
            return redirect()->route('contacts.index', array_filter(['role' => 'client', 'search' => $request->query('search')]));
        }
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::enum(CustomerStatus::class)]]);
        $customers = Customer::query()->where('organization_id', $organization->getKey())
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('display_name', 'like', "%{$search}%")->orWhere('company_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('whatsapp_username', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('display_name')->paginate(20)->withQueryString();

        return Inertia::render('Sales/Customers/Index', ['customers' => $customers, 'filters' => $filters]);
    }

    /**
     * Operational customer profile: identity, a cheap financial summary (a
     * handful of scoped aggregate SUM/COUNT queries — never one per row),
     * and the customer's most recent documents. Never a per-row payment
     * calculator: the paid/outstanding figures are single aggregate queries
     * over payment_allocations, the same pattern FinanceReceivablesService
     * already uses for the exact same "paid so far" question.
     *
     * W5.1: seeing a customer (organization-wide) never implies seeing all of
     * its documents. Every list, count and figure is scoped in SQL by
     * DocumentVisibility (view permission + the viewer's active stores), so a
     * document of another store is never serialized, counted or summed.
     * Documents of the viewer's other stores open through the safe
     * cross-store opener (/documents/open/{type}/{id}).
     */
    public function show(Customer $customer, WhatsAppContactLinkResolver $whatsapp, CustomerFinancialSummary $financialSummary, FollowUpQuery $followUps, DocumentVisibility $visibility): Response
    {
        $this->authorize('view', $customer);
        $user = request()->user();
        $organization = $customer->organization;
        $customerId = $customer->getKey();
        $stores = $visibility->storeIds($user, $organization) ?: [0];
        $visible = fn (string $model) => $visibility->visible($user, $organization, $model);
        $orders = fn () => $visible(SalesOrder::class)->where('customer_id', $customerId);
        $invoices = fn () => $visible(Invoice::class)->where('customer_id', $customerId)->where('status', '!=', InvoiceStatus::Superseded->value);
        $payments = fn () => $visible(Payment::class)
            ->whereHas('allocations.salesOrder', fn ($query) => $query->where('customer_id', $customerId)->whereIn('store_id', $stores));
        $quotations = fn () => $visible(Quotation::class)->where('customer_id', $customerId);
        $deliveryNotes = fn () => $visible(DeliveryNote::class)
            ->whereHas('salesOrder', fn ($query) => $query->where('customer_id', $customerId)->whereIn('store_id', $stores));

        $recentLimit = 15;

        return Inertia::render('Sales/Customers/Show', [
            'customer' => $customer,
            'whatsappDestination' => $whatsapp->resolve($customer->whatsapp_username, $customer->phone),
            'summary' => $financialSummary->forCustomer($customer, $user),
            'orders' => $orders()->orderByDesc('sale_date')->limit($recentLimit)
                ->get(['id', 'order_number', 'sale_date', 'status', 'payment_status', 'total_incl_tax']),
            'ordersCount' => $orders()->count(),
            'invoices' => $invoices()->orderByDesc('invoice_date')->limit($recentLimit)
                ->get(['id', 'invoice_number', 'version', 'invoice_date', 'status', 'total_incl_tax']),
            'invoicesCount' => $invoices()->count(),
            'payments' => $payments()->orderByDesc('payment_date')->limit($recentLimit)
                ->get(['id', 'payment_number', 'payment_date', 'method', 'status', 'amount']),
            'paymentsCount' => $payments()->count(),
            'quotations' => $quotations()->orderByDesc('quotation_date')->limit($recentLimit)
                ->get(['id', 'quotation_number', 'quotation_date', 'status', 'total_incl_tax']),
            'quotationsCount' => $quotations()->count(),
            'deliveryNotes' => $deliveryNotes()->orderByDesc('delivery_date')->limit($recentLimit)
                ->get(['id', 'delivery_note_number', 'delivery_date', 'status']),
            'deliveryNotesCount' => $deliveryNotes()->count(),
            'followUps' => $followUps->panel($user, $organization, 'customer_id', (int) $customerId, ['customer_id' => (int) $customerId, 'quotation_id' => null]),
            'can' => ['update' => $user->can('update', $customer)],
        ]);
    }

    public function create(ActiveTenantContext $context): Response|RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [Customer::class, $organization]);
        if (request()->user()->hasPermission($organization, 'contacts.create')) {
            return redirect()->route('contacts.create', ['role' => ['client']]);
        }

        return Inertia::render('Sales/Customers/Form', ['customer' => null]);
    }

    public function store(Request $request, ActiveTenantContext $context, CustomerManager $manager): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [Customer::class, $organization]);
        $customer = $manager->create($request->user(), $organization, $request->validate(CustomerManager::rules()));

        return redirect()->route('sales.customers.show', $customer);
    }

    public function edit(Customer $customer, LegacyPartyResolver $resolver): Response|RedirectResponse
    {
        $this->authorize('update', $customer);
        if (request()->user()->hasPermission($customer->organization_id, 'contacts.update')) {
            return redirect()->route('contacts.parties.edit', $resolver->for($customer));
        }

        return Inertia::render('Sales/Customers/Form', ['customer' => $customer]);
    }

    public function update(Request $request, Customer $customer, CustomerManager $manager): RedirectResponse
    {
        $this->authorize('update', $customer);
        $manager->update($request->user(), $customer, $request->validate(CustomerManager::rules()));

        return back();
    }
}
