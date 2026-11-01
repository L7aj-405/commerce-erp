<?php

namespace App\Http\Controllers\Procurement;

use App\Actions\Pos\CancelCartSupplierRequestAction;
use App\Actions\Pos\RecordCartSupplierAvailabilityAction;
use App\Enums\QuotationStatus;
use App\Enums\QuotationSupplierAvailabilityStatus as Status;
use App\Enums\SalesOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Services\ActiveTenantContext;
use App\Support\BusinessTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Achats › Demandes fournisseur — ONE inbox over the canonical
 * `quotation_supplier_availability_requests` (pre-sale availability checks),
 * whatever their origin: a Devis line or a POS cart line ("Articles à
 * sourcer"). Each row states its source.
 *
 * Mutations always go through the canonical actions of the request's own
 * context: Devis rows keep using the QuotationSourcingController routes
 * (unchanged); cart rows use `record` / `cancel` below, which delegate to the
 * same cart actions as the POS modal — so an answer recorded here is the very
 * row the POS sourcing modal shows.
 *
 * Distinct from Achats › Approvisionnements (Sales Order procurement).
 *
 * Scoped to the active store, exactly like the Devis list: the canonical
 * sourcing actions only accept a Devis / cart of the actor's active store, so a
 * row shown here is always one the operator can act on after switching stores.
 */
class QuotationSupplierRequestController extends Controller
{
    /** Default inbox: unresolved supplier work. */
    private const OPEN = 'open';

    private const SOURCES = ['quotation', 'pos'];

    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $store = $context->storeOrFail();
        $user = $request->user();

        // Devis visibility in this store + purchasing visibility of supplier data.
        $this->authorize('viewAny', [Quotation::class, $organization, $store]);
        abort_unless(
            $user->hasPermission($organization, 'procurement.view')
            && $user->hasPermission($organization, 'suppliers.view'),
            403,
        );

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in([self::OPEN, 'all', ...array_column(Status::cases(), 'value')])],
            'source' => ['nullable', Rule::in(self::SOURCES)],
            'supplier_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $status = $filters['status'] ?? self::OPEN;
        $filters['status'] = $status;

        $base = QuotationSupplierAvailabilityRequest::query()
            ->where('organization_id', $organization->getKey())
            ->where('store_id', $store->getKey());

        $requests = (clone $base)
            ->when($status === self::OPEN, fn ($q) => $q->whereIn('status', [Status::Pending->value, Status::Partial->value]))
            ->when(! in_array($status, [self::OPEN, 'all'], true), fn ($q) => $q->where('status', $status))
            ->when(($filters['source'] ?? null) === 'quotation', fn ($q) => $q->whereNotNull('quotation_id'))
            ->when(($filters['source'] ?? null) === 'pos', fn ($q) => $q->whereNotNull('sales_order_id'))
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($filters['from'] ?? null, fn ($q, string $d) => $q->where('requested_at', '>=', BusinessTime::localToUtc($organization, $d)))
            ->when($filters['to'] ?? null, fn ($q, string $d) => $q->where('requested_at', '<', BusinessTime::localToUtc($organization, $d)->addDay()))
            ->when(trim((string) ($filters['search'] ?? '')), fn ($q, string $s) => $q->where(fn ($q) => $q
                ->whereHas('quotationLine', fn ($l) => $l->where(fn ($l) => $l
                    ->where('product_name', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%")
                    ->orWhere('sku', 'like', "%{$s}%")
                    ->orWhere('reference', 'like', "%{$s}%")))
                ->orWhereHas('quotation', fn ($d) => $d->where(fn ($d) => $d
                    ->where('quotation_number', 'like', "%{$s}%")
                    ->orWhere('customer_name', 'like', "%{$s}%")
                    ->orWhere('customer_company', 'like', "%{$s}%")))
                ->orWhereHas('salesOrderLine', fn ($l) => $l->where(fn ($l) => $l
                    ->where('product_name', 'like', "%{$s}%")
                    ->orWhere('sku', 'like', "%{$s}%")
                    ->orWhere('reference', 'like', "%{$s}%")))
                ->orWhereHas('salesOrder', fn ($o) => $o->where(fn ($o) => $o
                    ->where('order_number', 'like', "%{$s}%")
                    ->orWhere('customer_name', 'like', "%{$s}%")))
                ->orWhereHas('supplier', fn ($sup) => $sup->where('name', 'like', "%{$s}%"))))
            ->with([
                'quotation:id,organization_id,store_id,quotation_number,status,customer_id,customer_name,customer_company',
                'quotationLine:id,line_type,product_name,variant_name,description,sku,reference',
                'salesOrder:id,organization_id,store_id,order_number,status,source,customer_id,customer_name,pos_converted_at',
                'salesOrderLine:id,line_type,product_name,variant_name,sku,reference',
                'supplier:id,name',
                'requestedBy:id,name',
                'resolvedBy:id,name',
            ])
            ->latest('requested_at')->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (QuotationSupplierAvailabilityRequest $r) => $this->row($r));

        // Supplier filter options: only suppliers that appear in this store's requests (bounded).
        $suppliers = Supplier::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('id', (clone $base)->whereNotNull('supplier_id')->select('supplier_id'))
            ->orderBy('name')->limit(200)->get(['id', 'name']);

        return Inertia::render('Procurement/SupplierRequests/Index', [
            'requests' => $requests,
            'filters' => $filters,
            'suppliers' => $suppliers,
            'can' => [
                // Same permissions the canonical actions enforce server-side.
                'record' => $user->hasPermission($organization, 'procurement.manage'),
                'cancel' => $user->hasPermission($organization, 'quotations.update')
                    || $user->hasPermission($organization, 'procurement.manage'),
                // A cart request is withdrawn by the cart editor or the supplier owner.
                'cancelCart' => $user->hasPermission($organization, 'sales_orders.update')
                    || $user->hasPermission($organization, 'procurement.manage'),
                'viewCustomers' => $user->hasPermission($organization, 'customers.view'),
                'viewSalesOrders' => $user->hasPermission($organization, 'sales_orders.view'),
            ],
        ]);
    }

    /** Record the supplier's answer to a POS cart request (canonical cart action). */
    public function record(Request $request, ActiveTenantContext $context, QuotationSupplierAvailabilityRequest $supplierRequest, RecordCartSupplierAvailabilityAction $action): RedirectResponse
    {
        $cart = $this->cartOf($context, $supplierRequest);
        $data = $request->validate([
            'outcome' => ['required', 'in:available,unavailable'],
            'confirmed_quantity' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $action->execute($request->user(), $cart, $supplierRequest, $data);

        return back();
    }

    /** Withdraw a POS cart request (canonical cart action). */
    public function cancel(Request $request, ActiveTenantContext $context, QuotationSupplierAvailabilityRequest $supplierRequest, CancelCartSupplierRequestAction $action): RedirectResponse
    {
        $action->execute($request->user(), $this->cartOf($context, $supplierRequest), $supplierRequest);

        return back();
    }

    /**
     * The cart a request belongs to, in the trusted active organisation and
     * store. Devis requests keep their own routes: 404 here.
     */
    private function cartOf(ActiveTenantContext $context, QuotationSupplierAvailabilityRequest $supplierRequest): SalesOrder
    {
        $organization = $context->organizationOrFail();
        $store = $context->storeOrFail();
        abort_unless(
            (int) $supplierRequest->organization_id === (int) $organization->getKey()
            && (int) $supplierRequest->store_id === (int) $store->getKey()
            && $supplierRequest->sales_order_id !== null,
            404,
        );

        return SalesOrder::query()
            ->where('organization_id', $organization->getKey())
            ->where('store_id', $store->getKey())
            ->whereKey($supplierRequest->sales_order_id)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function row(QuotationSupplierAvailabilityRequest $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status->value,
            ...($r->sales_order_id !== null ? $this->cartContext($r) : $this->quotationContext($r)),
            'supplier_name' => $r->supplier?->name,
            'requested_quantity' => $r->requested_quantity,
            'confirmed_quantity' => $r->confirmed_quantity,
            'note' => $r->note,
            'requested_by' => $r->requestedBy?->name,
            'requested_at' => $r->requested_at?->toIso8601String(),
            'resolved_by' => $r->resolvedBy?->name,
            'resolved_at' => $r->resolved_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function quotationContext(QuotationSupplierAvailabilityRequest $r): array
    {
        $quotation = $r->quotation;
        $line = $r->quotationLine;

        return [
            'source' => 'quotation',
            'quotation' => [
                'id' => $quotation->id,
                'number' => $quotation->quotation_number,
                'is_draft' => $quotation->status === QuotationStatus::Draft,
            ],
            'cart' => null,
            'customer' => [
                'id' => $quotation->customer_id,
                'name' => $quotation->customer_company ?: $quotation->customer_name,
            ],
            'article' => [
                'line_type' => $line?->line_type->value,
                'name' => $line ? ($line->product_name ?: $line->description) : null,
                'variant' => $line?->variant_name,
                'reference' => $line ? ($line->reference ?: $line->sku) : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function cartContext(QuotationSupplierAvailabilityRequest $r): array
    {
        $order = $r->salesOrder;
        $line = $r->salesOrderLine;
        $open = $order->status === SalesOrderStatus::Draft && $order->pos_converted_at === null;

        return [
            'source' => 'pos',
            'quotation' => null,
            'cart' => [
                'id' => $order->id,
                'number' => $order->order_number,
                // Still an open POS cart: answers can be recorded / withdrawn.
                'is_open' => $open,
                // pos_cart | order_draft (handed over) | closed (sold / cancelled / confirmed)
                'stage' => $open ? 'pos_cart' : ($order->status === SalesOrderStatus::Draft ? 'order_draft' : 'closed'),
            ],
            'customer' => [
                'id' => $order->customer_id,
                'name' => $order->customer_name,
            ],
            'article' => [
                'line_type' => $line ? 'catalog' : null,
                'name' => $line?->product_name,
                'variant' => $line?->variant_name,
                'reference' => $line ? ($line->reference ?: $line->sku) : null,
            ],
        ];
    }
}
