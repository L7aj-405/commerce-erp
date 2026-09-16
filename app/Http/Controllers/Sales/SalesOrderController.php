<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\CreateSalesOrderAction;
use App\Actions\Sales\UpdateSalesOrderAction;
use App\Enums\CatalogStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderFulfillmentStatus;
use App\Enums\SalesOrderLineType;
use App\Enums\SalesOrderPaymentStatus;
use App\Enums\SalesOrderSource;
use App\Enums\SalesOrderStatus;
use App\Enums\WarehouseStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\FinancialAccount;
use App\Models\InventoryBalance;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\ActiveTenantContext;
use App\Services\PosStockAllocator;
use App\Services\ProductPriceResolver;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
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
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(SalesOrderStatus::class)],
            'fulfillment_status' => ['nullable', Rule::enum(SalesOrderFulfillmentStatus::class)],
            'payment_status' => ['nullable', Rule::enum(SalesOrderPaymentStatus::class)],
            'sale_date' => ['nullable', 'date'],
        ]);

        $scope = fn () => SalesOrder::query()
            ->where('organization_id', $organization->getKey())
            ->where('store_id', $store->getKey());

        $orders = $scope()
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('order_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhere('customer_company', 'like', "%{$search}%")
                ->orWhere('customer_phone', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['fulfillment_status'] ?? null, fn ($query, string $value) => $query->where('fulfillment_status', $value))
            ->when($filters['payment_status'] ?? null, fn ($query, string $value) => $query->where('payment_status', $value))
            ->when($filters['sale_date'] ?? null, fn ($query, string $date) => $query->whereDate('sale_date', $date))
            ->with('store:id,name,code')
            ->withExists(['invoices as has_active_invoice' => fn ($query) => $query
                ->whereIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Issued->value])])
            ->latest('ordered_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Sales/Orders/Index', [
            'orders' => $orders,
            'filters' => $filters,
            'summary' => [
                'total' => $scope()->count(),
                'a_preparer' => $scope()
                    ->where('status', SalesOrderStatus::Confirmed->value)
                    ->where('fulfillment_status', SalesOrderFulfillmentStatus::Unfulfilled->value)->count(),
                'partiellement_payees' => $scope()->where('payment_status', SalesOrderPaymentStatus::PartiallyPaid->value)->count(),
                'payees' => $scope()->where('payment_status', SalesOrderPaymentStatus::Paid->value)->count(),
            ],
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

    public function show(SalesOrder $order, SalesOrderPaymentCalculator $payments, PosStockAllocator $stock): Response
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
                    ->get(['id', 'name', 'code', 'type', 'currency_code', 'accepted_methods'])
                : [],
            'documents' => [
                'invoices' => $order->invoices()->latest('id')->get(['id', 'invoice_number', 'invoice_date', 'status', 'total_incl_tax']),
                'deliveryNotes' => $order->deliveryNotes()->latest('id')->get(['id', 'delivery_note_number', 'delivery_date', 'status']),
            ],
            'awaitingReplenishment' => $order->awaitingReplenishment(),
            'procurement' => $this->procurementPayload($order, $stock),
            'transferRequests' => $order->transferRequests()
                ->with(['sourceWarehouse:id,name', 'destinationWarehouse:id,name', 'lines'])
                ->latest('id')->get()
                ->map(fn ($req) => [
                    'id' => $req->id,
                    'request_number' => $req->request_number,
                    'status' => $req->status->value,
                    'source' => $req->sourceWarehouse?->name,
                    'destination' => $req->destinationWarehouse?->name,
                    'unit_count' => $req->lines->reduce(fn ($c, $l) => $c + (float) $l->quantity, 0.0),
                ]),
            'can' => [
                ...$this->abilities(request(), $order),
                'recordPayment' => $canRecordPayment,
                'backdatePayment' => request()->user()->hasPermission($order->organization_id, 'payments.backdate'),
                'createInvoice' => request()->user()->can('create', [Invoice::class, $order]),
                'createDeliveryNote' => request()->user()->can('create', [DeliveryNote::class, $order]),
            ],
        ]);
    }

    public function edit(Request $request, SalesOrder $order, PosStockAllocator $stock): Response
    {
        $this->authorize('update', $order);

        $order = $this->loadOrder($order);
        $underCovered = 0;
        if ($order->status === SalesOrderStatus::Draft && $request->user()->hasPermission($order->organization_id, 'procurement.manage')
            && $order->source !== SalesOrderSource::Pos) {
            foreach ($order->lines as $line) {
                if ($line->line_type !== SalesOrderLineType::Catalog || ! $line->productVariant) {
                    continue;
                }
                $procured = $order->procurements->where('sales_order_line_id', $line->id)
                    ->reject(fn ($p) => in_array($p->status->value, ['cancelled', 'unavailable'], true))
                    ->reduce(fn (string $c, $p) => Decimal::add($c, $p->quantity), '0.0000');
                $covered = Decimal::add($stock->availableForLine($order, $line->productVariant, $line), $procured);
                if (Decimal::compare($covered, $line->quantity) < 0) {
                    $underCovered++;
                }
            }
        }

        $originatingQuotation = Quotation::query()
            ->where('organization_id', $order->organization_id)
            ->where('converted_sales_order_id', $order->getKey())
            ->first(['id', 'quotation_number', 'status', 'revision_number']);

        return Inertia::render('Sales/Orders/Edit', [
            'order' => $order,
            'taxRates' => $order->organization->taxRates()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'rate']),
            'lineSearchUrl' => route('sales.orders.line-search', $order),
            'customerSearchUrl' => route('sales.orders.customer-search', $order),
            'isEditable' => $order->status === SalesOrderStatus::Draft,
            'procurementUnderCovered' => $underCovered,
            'originatingQuotation' => $originatingQuotation ? [
                'id' => $originatingQuotation->id,
                'number' => $originatingQuotation->quotation_number,
                'status' => $originatingQuotation->status->value,
                'revision_number' => (int) $originatingQuotation->revision_number,
            ] : null,
            'can' => $this->abilities($request, $order),
        ]);
    }

    public function update(Request $request, SalesOrder $order, UpdateSalesOrderAction $action): RedirectResponse
    {
        $this->authorize('update', $order);
        $action->execute($request->user(), $order, $request->validate($this->headerRules($order->organization_id)));

        return back();
    }

    /**
     * Unified server-side catalogue search for the modern line editor: matches a
     * ProductVariant by name / SKU / reference / barcode / brand, same
     * organisation only. Zero-stock variants are still returned (visibility is
     * not the same as fulfilment eligibility).
     */
    public function lineSearch(Request $request, SalesOrder $order, ProductPriceResolver $priceResolver): JsonResponse
    {
        $this->authorize('update', $order);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $search = trim((string) ($filters['search'] ?? ''));

        $variants = ProductVariant::query()
            ->where('organization_id', $order->organization_id)
            ->where('status', CatalogStatus::Active->value)
            ->whereHas('product', fn ($query) => $query->where('status', CatalogStatus::Active->value))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('sku', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")
                ->orWhere('label', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($product) => $product->where('name', 'like', "%{$search}%")
                    ->orWhereHas('brand', fn ($brand) => $brand->where('name', 'like', "%{$search}%")))))
            ->with(['product:id,name,brand_id,default_unit_id', 'product.brand:id,name', 'product.defaultUnit:id,name,symbol', 'taxRate:id,name,rate'])
            ->orderBy('product_id')->orderBy('sku')
            ->limit(15)
            ->get(['id', 'organization_id', 'product_id', 'label', 'sku', 'reference', 'barcode', 'default_sale_price', 'public_price_ttc', 'unit_price_ht', 'tax_rate_id']);

        $defaultTax = $priceResolver->defaultTaxRate($order->store, (int) $order->organization_id);
        $availability = InventoryBalance::query()
            ->where('organization_id', $order->organization_id)
            ->whereIn('product_variant_id', $variants->pluck('id'))
            ->whereHas('warehouse', fn ($query) => $query->where('status', WarehouseStatus::Active->value))
            ->get()
            ->groupBy('product_variant_id')
            ->map(fn ($rows) => $rows->reduce(fn (string $carry, InventoryBalance $balance) => Decimal::add($carry, $balance->available), '0.0000'));

        $data = $variants->map(function (ProductVariant $variant) use ($priceResolver, $defaultTax, $availability) {
            $price = $priceResolver->resolveWith($variant, $defaultTax);

            return [
                'id' => $variant->getKey(),
                'product_name' => $variant->product->name,
                'variant_name' => $variant->label,
                'sku' => $variant->sku,
                'reference' => $variant->reference,
                'barcode' => $variant->barcode,
                'brand' => $variant->product->brand?->name,
                'unit_label' => $variant->product->defaultUnit?->symbol ?? $variant->product->defaultUnit?->name,
                'unit_price_excl_tax' => $price['unit_price_ht'],
                'unit_price_incl_tax' => $price['unit_price_ttc'] ?? $variant->default_sale_price,
                'tax_rate' => $price['tax_rate_value'],
                'tax_name' => $price['tax_name'],
                // No exploitable HT/tax split. The row stays SELECTABLE — the
                // line is added with an explicit unresolved-tax marker and the
                // employee picks the rate on the line (never a silent 0%).
                'tax_unresolved' => $price['config_missing'],
                'stock_available' => $availability->get($variant->getKey(), '0.0000'),
            ];
        });

        return response()->json(['data' => $data->values()]);
    }

    /** Lightweight active-customer typeahead for the order editor's client picker. */
    public function customerSearch(Request $request, SalesOrder $order): JsonResponse
    {
        $this->authorize('update', $order);
        $search = trim((string) $request->query('search', ''));

        $customers = Customer::query()
            ->where('organization_id', $order->organization_id)
            ->where('status', 'active')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('display_name', 'like', "%{$search}%")
                ->orWhere('company_name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('display_name')
            ->limit(15)
            ->get(['id', 'display_name', 'company_name', 'phone', 'email']);

        return response()->json(['data' => $customers]);
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

    /**
     * Everything the "APPROVISIONNEMENT FOURNISSEUR" panel on the order needs:
     * the procurement rows themselves, plus — for a still-draft order — a
     * per-catalogue-line coverage breakdown (Demandé / Stock société / À
     * approvisionner) and the pickers used to raise one.
     *
     * @return array<string, mixed>
     */
    private function procurementPayload(SalesOrder $order, PosStockAllocator $stock): array
    {
        $user = request()->user();
        // POS special orders and manual customer Orders share the same
        // procurement domain — the Show page manages both.
        $canManage = $user->hasPermission($order->organization_id, 'procurement.manage');
        $canReceive = $user->hasPermission($order->organization_id, 'procurement.receive');

        $rows = $order->procurements()
            ->with([
                'supplier:id,name',
                'productVariant:id,label,sku,reference',
                'receivingWarehouse:id,name',
                'transferRequest:id,request_number,status',
                'salesOrderLine:id,product_name',
            ])
            ->latest('id')->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'procurement_number' => $p->procurement_number,
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'supplier_availability_status' => $p->supplier_availability_status->value,
                'supplier_availability_label' => $p->supplier_availability_status->label(),
                'supplier' => $p->supplier?->only(['id', 'name']),
                'sales_order_line_id' => $p->sales_order_line_id,
                'product' => trim(($p->salesOrderLine?->product_name ?? '').' '.($p->productVariant?->label ?? '')),
                'quantity' => $p->quantity,
                'supplier_reference' => $p->supplier_reference,
                'expected_at' => $p->expected_at?->toDateString(),
                'ordered_at' => $p->ordered_at?->toIso8601String(),
                'received_at' => $p->received_at?->toIso8601String(),
                'receiving_warehouse' => $p->receivingWarehouse?->only(['id', 'name']),
                'transfer_request' => $p->transferRequest?->only(['id', 'request_number', 'status']),
                'notes' => $p->notes,
                'cancellation_reason' => $p->cancellation_reason,
            ]);

        $lines = [];
        if ($order->status === SalesOrderStatus::Draft && $canManage) {
            foreach ($order->lines as $line) {
                if ($line->line_type !== SalesOrderLineType::Catalog || ! $line->productVariant) {
                    continue;
                }
                $procured = $order->procurements
                    ->where('sales_order_line_id', $line->id)
                    ->reject(fn ($p) => in_array($p->status->value, ['cancelled', 'unavailable'], true))
                    ->reduce(fn (string $c, $p) => Decimal::add($c, $p->quantity), '0.0000');
                $companyAvailable = $stock->availableForLine($order, $line->productVariant, $line);
                $needed = Decimal::compare(Decimal::add($companyAvailable, $procured), $line->quantity) < 0
                    ? Decimal::subtract($line->quantity, Decimal::add($companyAvailable, $procured))
                    : '0.0000';
                $lines[] = [
                    'id' => $line->id,
                    'product' => trim($line->product_name.' '.($line->variant_name ?? '')),
                    'requested' => $line->quantity,
                    'company_available' => $companyAvailable,
                    'procured' => $procured,
                    'to_procure' => $needed,
                ];
            }
        }

        return [
            'rows' => $rows->values(),
            'awaiting' => $order->awaitingSupplierProcurement(),
            'can' => ['manage' => $canManage, 'receive' => $canReceive],
            'lines' => $lines,
            'suppliers' => $canManage
                ? Supplier::query()->where('organization_id', $order->organization_id)
                    ->where('active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'warehouses' => $canReceive
                ? Warehouse::query()->where('organization_id', $order->organization_id)
                    ->where('status', WarehouseStatus::Active->value)->orderBy('name')->get(['id', 'name', 'code'])
                : [],
        ];
    }

    private function loadOrder(SalesOrder $order): SalesOrder
    {
        return $order->load([
            'store:id,name,code',
            'customer:id,type,display_name,company_name,email,phone,tax_identifier,billing_address',
            'createdBy:id,name',
            'lines.productVariant:id,organization_id,label,sku,status',
            'lines.allocations.warehouse:id,name,code',
            'lines.allocations.inventoryReservation:id,status',
            'lines.outOfStockArticle',
            'procurements',
        ]);
    }

    /** @return array<string, bool> */
    private function abilities(Request $request, SalesOrder $order): array
    {
        return [
            'update' => $request->user()->can('update', $order), 'confirm' => $request->user()->can('confirm', $order),
            'cancel' => $request->user()->can('cancel', $order), 'fulfill' => $request->user()->can('fulfill', $order),
            'overridePrice' => $request->user()->hasPermission($order->organization_id, 'sales_orders.override_price'),
            'applyDiscount' => $request->user()->hasPermission($order->organization_id, 'sales_orders.apply_discount'),
            'reportOutOfStockArticle' => $request->user()->hasPermission($order->organization_id, 'procurement.manage'),
        ];
    }
}
