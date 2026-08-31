<?php

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\CancelInvoiceDraftAction;
use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Documents\UpdateInvoiceDraftAction;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\CreateInvoiceRequest;
use App\Http\Requests\Documents\UpdateInvoiceDraftRequest;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Services\ActiveTenantContext;
use App\Services\SalesOrderPaymentCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $store = $context->storeOrFail();
        $this->authorize('viewAny', [Invoice::class, $organization, $store]);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
            'invoice_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $invoices = Invoice::query()->where('organization_id', $organization->getKey())->where('store_id', $store->getKey())
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('invoice_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhere('customer_company', 'like', "%{$search}%")
                ->orWhereHas('salesOrder', fn ($query) => $query->where('order_number', 'like', "%{$search}%"))))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['invoice_date'] ?? null, fn ($query, string $date) => $query->whereDate('invoice_date', $date))
            ->with(['store:id,name,code', 'salesOrder:id,order_number'])
            ->latest('invoice_date')->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('Documents/Invoices/Index', ['invoices' => $invoices, 'filters' => $filters]);
    }

    public function store(CreateInvoiceRequest $request, SalesOrder $order, CreateFullInvoiceFromSalesOrderAction $action): RedirectResponse
    {
        $this->authorize('create', [Invoice::class, $order]);
        $invoice = $action->execute($request->user(), $order, $request->validated());

        return redirect()->route('invoices.show', $invoice);
    }

    public function show(Request $request, Invoice $invoice, SalesOrderPaymentCalculator $payments): Response
    {
        $this->authorize('view', $invoice);
        $invoice->load(['store:id,name,code', 'salesOrder:id,organization_id,store_id,order_number,payment_status,total_incl_tax', 'lines', 'issuedBy:id,name', 'cancelledBy:id,name']);

        return Inertia::render('Documents/Invoices/Show', [
            'invoice' => $invoice,
            'relatedOrderPaymentSummary' => $payments->summary($invoice->salesOrder),
            'can' => [
                'updateDraft' => $request->user()->can('updateDraft', $invoice),
                'issue' => $request->user()->can('issue', $invoice),
                'backdate' => $request->user()->hasPermission($invoice->organization_id, 'invoices.backdate'),
                'email' => $request->user()->can('email', $invoice),
            ],
        ]);
    }

    public function update(UpdateInvoiceDraftRequest $request, Invoice $invoice, UpdateInvoiceDraftAction $action): RedirectResponse
    {
        $this->authorize('updateDraft', $invoice);
        $action->execute($request->user(), $invoice, $request->validated());

        return back();
    }

    public function issue(Request $request, Invoice $invoice, IssueInvoiceAction $action): RedirectResponse
    {
        $this->authorize('issue', $invoice);
        $action->execute($request->user(), $invoice);

        return back();
    }

    public function cancel(Request $request, Invoice $invoice, CancelInvoiceDraftAction $action): RedirectResponse
    {
        $this->authorize('updateDraft', $invoice);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $action->execute($request->user(), $invoice, $data['reason'] ?? null);

        return back();
    }
}
