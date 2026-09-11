<?php

namespace App\Http\Controllers;

use App\Actions\Pos\CreatePosSaleAction;
use App\Actions\Sales\CancelSalesOrderAction;
use App\Actions\Sales\CreateSalesOrderAction;
use App\Actions\Sales\RemoveSalesOrderLineAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Actions\Sales\UpdateSalesOrderAction;
use App\Enums\CatalogStatus;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\SalesOrderDiscountType;
use App\Enums\SalesOrderSource;
use App\Enums\SalesOrderStatus;
use App\Enums\WarehouseStatus;
use App\Http\Requests\Pos\CompletePosSaleRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\InventoryBalance;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\CustomerManager;
use App\Services\PosDraftCheckoutCalculator;
use App\Services\ProductPriceResolver;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function index(
        Request $request,
        ActiveTenantContext $context,
        SalesOrderPaymentCalculator $paymentCalculator,
        PosDraftCheckoutCalculator $posSummary,
    ): Response {
        $organization = $context->organizationOrFail();
        $this->authorizePosAccess($request, $organization);
        $store = $context->store();

        $warehouses = $store
            ? Warehouse::query()
                ->where('organization_id', $organization->getKey())
                ->where('status', WarehouseStatus::Active->value)
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
            : collect();

        $activeSaleModel = $store ? $this->activeDraftQuery($organization, $store)
            ->with($this->draftRelations())
            ->latest('updated_at')
            ->first() : null;

        $heldSaleModels = $store ? $this->heldDraftQuery($organization, $store)
            ->with(['customer:id,display_name', 'posWarehouse:id,name,code', 'lines'])
            ->latest('pos_held_at')
            ->limit(20)
            ->get() : collect();

        $completedOrderModel = $store && $request->session()->has('pos.completed_order_id')
            ? SalesOrder::query()
                ->where('organization_id', $organization->getKey())
                ->where('store_id', $store->getKey())
                ->where('source', SalesOrderSource::Pos->value)
                ->whereKey($request->session()->get('pos.completed_order_id'))
                ->with(['paymentAllocations.payment.financialAccount:id,name,code,type', 'lines.allocations'])
                ->first(['id', 'organization_id', 'order_number', 'client_operation_id', 'customer_name', 'ordered_at', 'subtotal_excl_tax', 'discount_total', 'tax_total', 'total_incl_tax', 'currency_code', 'status', 'fulfillment_status', 'payment_status', 'pos_warehouse_id'])
            : null;

        $canCreatePayment = $request->user()->hasPermission($organization, 'payments.create');
        $financialAccounts = $store && $canCreatePayment
            ? FinancialAccount::query()
                ->where('organization_id', $organization->getKey())
                ->where('status', 'active')
                ->where('currency_code', config('platform.currency_code', 'MAD'))
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'type', 'currency_code'])
            : collect();

        return Inertia::render('Pos/Index', [
            'store' => $store?->only(['id', 'name', 'code']),
            'warehouses' => $warehouses,
            'currencyCode' => config('platform.currency_code', 'MAD'),
            'defaultWarehouseId' => $activeSaleModel?->pos_warehouse_id ?? $warehouses->first()?->id,
            'activeSale' => $activeSaleModel ? $this->draftPayload($activeSaleModel, $posSummary) : null,
            'heldSales' => $heldSaleModels->map(fn (SalesOrder $order) => $this->heldSalePayload($order))->values(),
            'completedOrder' => $completedOrderModel ? $this->completedOrder($completedOrderModel, $paymentCalculator, $request->session()->get('pos.completed_tenders', [])) : null,
            'createdCustomer' => $request->session()->get('pos.created_customer'),
            'financialAccounts' => $financialAccounts,
            'brands' => Brand::query()->where('organization_id', $organization->getKey())->where('status', CatalogStatus::Active->value)->orderBy('name')->get(['id', 'name']),
            'categories' => Category::query()->where('organization_id', $organization->getKey())->where('status', CatalogStatus::Active->value)->orderBy('name')->get(['id', 'name']),
            'suppliers' => $request->user()->hasPermission($organization, 'procurement.manage')
                ? Supplier::query()->where('organization_id', $organization->getKey())->where('active', true)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'can' => [
                'createCustomer' => $request->user()->hasPermission($organization, 'customers.create'),
                'updateCustomer' => $request->user()->hasPermission($organization, 'customers.update'),
                'overridePrice' => $request->user()->hasPermission($organization, 'sales_orders.override_price'),
                'applyDiscount' => $request->user()->hasPermission($organization, 'sales_orders.apply_discount'),
                'createPayment' => $canCreatePayment,
                'createDraft' => $request->user()->hasPermission($organization, 'sales_orders.create'),
                'updateDraft' => $request->user()->hasPermission($organization, 'sales_orders.update'),
                'holdDraft' => $request->user()->hasPermission($organization, 'sales_orders.update'),
                'cancelDraft' => $request->user()->hasPermission($organization, 'sales_orders.cancel'),
                'manageProcurement' => $request->user()->hasPermission($organization, 'procurement.manage'),
            ],
        ]);
    }

    public function products(Request $request, ActiveTenantContext $context, ProductPriceResolver $priceResolver): JsonResponse
    {
        [$organization] = $this->operationalContext($request, $context);
        $store = $context->store();
        abort_unless(
            $request->user()->hasPermission($organization, 'products.view')
            && $request->user()->hasPermission($organization, 'inventory.view'),
            403,
        );

        $filters = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'search' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'brand_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'availability' => ['nullable', 'in:all,in_stock,out_of_stock'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $warehouse = $this->warehouse($organization, (int) $filters['warehouse_id']);

        $variants = ProductVariant::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', CatalogStatus::Active->value)
            ->whereHas('product', function ($query) use ($filters) {
                $query->where('status', CatalogStatus::Active->value)
                    ->when($filters['brand_id'] ?? null, fn ($q, $brandId) => $q->where('brand_id', $brandId))
                    ->when($filters['category_id'] ?? null, fn ($q, $categoryId) => $q->where('default_category_id', $categoryId));
            })
            ->when($filters['barcode'] ?? null, fn ($query, string $barcode) => $query->where('barcode', trim($barcode)))
            ->when(isset($filters['price_min']), fn ($query) => $query->where('default_sale_price', '>=', (string) $filters['price_min']))
            ->when(isset($filters['price_max']), fn ($query) => $query->where('default_sale_price', '<=', (string) $filters['price_max']))
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('sku', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhereHas('brand', fn ($brand) => $brand->where('name', 'like', "%{$search}%")))))
            ->with([
                'product:id,name,image_url,brand_id,default_category_id',
                'product.brand:id,name',
                'taxRate:id,name,rate',
                'inventoryBalances' => fn ($query) => $query->where('warehouse_id', $warehouse->getKey()),
            ])
            ->orderBy('product_id')
            ->orderByRaw('CASE WHEN sku IS NULL OR sku = ? THEN 1 ELSE 0 END', [''])
            ->orderBy('sku')
            ->paginate(($filters['barcode'] ?? null) ? 1 : 24, ['id', 'organization_id', 'product_id', 'label', 'sku', 'reference', 'barcode', 'default_sale_price', 'public_price_ttc', 'unit_price_ht', 'tax_rate_id'])
            ->withQueryString();

        $defaultTaxRate = $priceResolver->defaultTaxRate($store, $organization->getKey());

        $totalAvailability = InventoryBalance::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('product_variant_id', $variants->getCollection()->pluck('id'))
            ->whereHas('warehouse', fn ($query) => $query->where('status', WarehouseStatus::Active->value))
            ->get()
            ->groupBy('product_variant_id')
            ->map(fn ($rows) => $rows->reduce(fn (string $carry, InventoryBalance $row) => Decimal::add($carry, $row->available), '0.0000'));

        $items = $variants->getCollection()->map(function (ProductVariant $variant) use ($totalAvailability, $priceResolver, $defaultTaxRate) {
            $balance = $variant->inventoryBalances->first();
            $price = $priceResolver->resolveWith($variant, $defaultTaxRate);

            return [
                'id' => $variant->getKey(),
                'product_name' => $variant->product->name,
                'variant_name' => $variant->label,
                'sku' => $variant->sku,
                'reference' => $variant->reference,
                'barcode' => $variant->barcode,
                'image_url' => $variant->product->image_url,
                'brand' => $variant->product->brand?->only(['id', 'name']),
                'unit_price_excl_tax' => $price['unit_price_ht'] ?? '0.0000',
                'unit_price_incl_tax' => $price['unit_price_ttc'] ?? $variant->default_sale_price,
                'default_sale_price' => $variant->default_sale_price,
                'tax_rate' => $price['tax_rate_id'] ? ['id' => $price['tax_rate_id'], 'name' => $price['tax_name'], 'rate' => $price['tax_rate_value']] : null,
                'ht_source' => $price['ht_source'],
                'tax_config_missing' => $price['config_missing'],
                'stock' => [
                    'on_hand' => $balance?->on_hand ?? '0.0000',
                    'reserved' => $balance?->reserved ?? '0.0000',
                    'available' => $balance?->available ?? '0.0000',
                    'total_available' => $totalAvailability->get($variant->getKey(), '0.0000'),
                ],
            ];
        })->filter(function (array $row) use ($filters) {
            return match ($filters['availability'] ?? 'all') {
                'in_stock' => Decimal::compare($row['stock']['total_available'], '0.0000') > 0,
                'out_of_stock' => Decimal::compare($row['stock']['total_available'], '0.0000') <= 0,
                default => true,
            };
        })->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $variants->currentPage(),
                'has_more' => $variants->hasMorePages(),
                'next_page' => $variants->hasMorePages() ? $variants->currentPage() + 1 : null,
            ],
        ]);
    }

    public function customers(Request $request, ActiveTenantContext $context): JsonResponse
    {
        [$organization] = $this->operationalContext($request, $context);
        $this->authorize('viewAny', [Customer::class, $organization]);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $search = trim((string) ($filters['search'] ?? ''));

        $customers = Customer::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', CustomerStatus::Active->value)
            ->when($search !== '', fn ($query) => $query
                ->where(fn ($inner) => $inner
                    ->where('display_name', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")))
            ->when($search !== '', fn ($query) => $query->orderBy('display_name'), fn ($query) => $query->latest('updated_at'))
            ->limit(20)
            ->get(['id', 'type', 'display_name', 'company_name', 'phone', 'email', 'tax_identifier', 'billing_address']);

        return response()->json(['data' => $customers]);
    }

    public function storeCustomer(Request $request, ActiveTenantContext $context, CustomerManager $manager): JsonResponse|RedirectResponse
    {
        [$organization] = $this->operationalContext($request, $context);
        $this->authorize('create', [Customer::class, $organization]);

        $data = $request->validate([
            'type' => ['required', 'in:individual,business'],
            'display_name' => ['nullable', 'string', 'max:255', 'required_if:type,individual'],
            'company_name' => ['nullable', 'string', 'max:255', 'required_if:type,business'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_identifier' => ['nullable', 'string', 'max:128'],
            'billing_address' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $customer = $manager->create($request->user(), $organization, [
            'type' => $data['type'] === 'business' ? CustomerType::Business->value : CustomerType::Individual->value,
            'display_name' => $data['type'] === 'business'
                ? trim((string) ($data['company_name'] ?? $data['contact_name']))
                : trim((string) $data['display_name']),
            'company_name' => $data['type'] === 'business' ? trim((string) $data['company_name']) : null,
            'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
            'email' => filled($data['email'] ?? null) ? trim((string) $data['email']) : null,
            'tax_identifier' => filled($data['tax_identifier'] ?? null) ? trim((string) $data['tax_identifier']) : null,
            'billing_address' => filled($data['billing_address'] ?? null) ? trim((string) $data['billing_address']) : null,
            'notes' => $data['notes'] ?? null,
            'status' => CustomerStatus::Active->value,
        ]);

        $payload = $customer->only([
            'id', 'type', 'display_name', 'company_name', 'phone', 'email', 'tax_identifier', 'billing_address',
        ]);

        if ($request->expectsJson()) {
            return response()->json(['data' => $payload], 201);
        }

        return redirect()->route('pos.index')->with('pos.created_customer', $payload);
    }

    public function updateCustomer(Request $request, ActiveTenantContext $context, Customer $customer, CustomerManager $manager): JsonResponse
    {
        [$organization] = $this->operationalContext($request, $context);
        abort_unless($customer->organization_id === $organization->getKey(), 404);
        $this->authorize('update', $customer);

        $data = $request->validate([
            'type' => ['required', 'in:individual,business'],
            'display_name' => ['nullable', 'string', 'max:255', 'required_if:type,individual'],
            'company_name' => ['nullable', 'string', 'max:255', 'required_if:type,business'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_identifier' => ['nullable', 'string', 'max:128'],
            'billing_address' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $customer = $manager->update($request->user(), $customer, [
            'type' => $data['type'] === 'business' ? CustomerType::Business->value : CustomerType::Individual->value,
            'display_name' => $data['type'] === 'business'
                ? trim((string) ($data['company_name'] ?? $data['contact_name']))
                : trim((string) $data['display_name']),
            'company_name' => $data['type'] === 'business' ? trim((string) $data['company_name']) : null,
            'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
            'email' => filled($data['email'] ?? null) ? trim((string) $data['email']) : null,
            'tax_identifier' => filled($data['tax_identifier'] ?? null) ? trim((string) $data['tax_identifier']) : null,
            'billing_address' => filled($data['billing_address'] ?? null) ? trim((string) $data['billing_address']) : null,
            'notes' => $data['notes'] ?? null,
            'status' => $customer->status->value,
        ]);

        return response()->json([
            'data' => $customer->only([
                'id', 'type', 'display_name', 'company_name', 'phone', 'email', 'tax_identifier', 'billing_address',
            ]),
        ]);
    }

    public function storeDraft(Request $request, ActiveTenantContext $context, CreateSalesOrderAction $createOrder, PosDraftCheckoutCalculator $posSummary): JsonResponse
    {
        [$organization, $store] = $this->operationalContext($request, $context);
        $this->authorize('create', [SalesOrder::class, $organization, $store]);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'customer_id' => ['nullable', 'integer'],
        ]);

        $warehouse = $this->warehouse($organization, (int) $data['warehouse_id']);
        $active = $this->activeDraftQuery($organization, $store)->with($this->draftRelations())->latest('updated_at')->first();

        if (! $active) {
            $active = $createOrder->execute($request->user(), $organization, $store, [
                'customer_id' => $data['customer_id'] ?? null,
                'sale_date' => now()->toDateString(),
                'currency_code' => config('platform.currency_code', 'MAD'),
                'notes' => null,
            ], SalesOrderSource::Pos);
            $active->pos_warehouse_id = $warehouse->getKey();
            $active->pos_global_discount_type = SalesOrderDiscountType::None->value;
            $active->pos_global_discount_value = '0.0000';
            $active->pos_fulfillment_mode = 'pickup';
            $active->pos_shipping_fee = '0.0000';
            $active->save();
            $active->load($this->draftRelations());
        } elseif (! $active->lines()->exists()) {
            $this->syncDraftHeader($request, $active, $data);
        }

        return $this->draftStateResponse($organization, $store, $active, $posSummary);
    }

    public function updateDraft(Request $request, ActiveTenantContext $context, SalesOrder $order, UpdateSalesOrderAction $updateOrder, PosDraftCheckoutCalculator $posSummary): JsonResponse
    {
        [$organization, $store] = $this->operationalContext($request, $context);
        $this->authorize('update', $order);
        $this->ensurePosDraft($order);
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'global_discount_type' => ['nullable', 'in:none,fixed,percentage'],
            'global_discount_value' => ['nullable', 'decimal:0,4', 'gte:0'],
            'fulfillment_mode' => ['nullable', 'in:pickup,delivery'],
            'shipping_fee' => ['nullable', 'decimal:0,4', 'gte:0'],
            'delivery_address' => ['nullable', 'string', 'max:5000'],
            'delivery_phone' => ['nullable', 'string', 'max:64'],
            'delivery_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if (array_key_exists('customer_id', $data)) {
            $updateOrder->execute($request->user(), $order, [
                'customer_id' => $data['customer_id'],
                'sale_date' => $order->sale_date->toDateString(),
                'currency_code' => $order->currency_code,
                'notes' => $order->notes,
            ]);
        }

        if (array_key_exists('warehouse_id', $data) && $data['warehouse_id'] !== null) {
            $warehouse = $this->warehouse($organization, (int) $data['warehouse_id']);
            $order = $order->fresh(['lines.allocations']);
            abort_if($order->lines->isNotEmpty(), 409, 'Changez l’entrepôt uniquement avant d’ajouter des articles.');
            $order->pos_warehouse_id = $warehouse->getKey();
        }

        if (array_key_exists('global_discount_type', $data)) {
            $order->pos_global_discount_type = $data['global_discount_type'] ?? SalesOrderDiscountType::None->value;
            $order->pos_global_discount_value = Decimal::normalize((string) ($data['global_discount_value'] ?? '0'));
        }

        if (array_key_exists('fulfillment_mode', $data)) {
            $order->pos_fulfillment_mode = $data['fulfillment_mode'] ?? 'pickup';
        }

        if (array_key_exists('shipping_fee', $data)) {
            $order->pos_shipping_fee = Decimal::normalize((string) ($data['shipping_fee'] ?? '0'));
        }

        if (array_key_exists('delivery_address', $data)) {
            $order->pos_delivery_address = filled($data['delivery_address'] ?? null) ? trim((string) $data['delivery_address']) : null;
        }

        if (array_key_exists('delivery_phone', $data)) {
            $order->pos_delivery_phone = filled($data['delivery_phone'] ?? null) ? trim((string) $data['delivery_phone']) : null;
        }

        if (array_key_exists('delivery_notes', $data)) {
            $order->pos_delivery_notes = filled($data['delivery_notes'] ?? null) ? trim((string) $data['delivery_notes']) : null;
        }

        if (($order->pos_fulfillment_mode ?? 'pickup') !== 'delivery') {
            $order->pos_shipping_fee = '0.0000';
            $order->pos_delivery_address = null;
            $order->pos_delivery_phone = null;
            $order->pos_delivery_notes = null;
        }

        $order->save();

        return $this->draftStateResponse($organization, $store, $order->fresh($this->draftRelations()), $posSummary);
    }

    public function storeDraftLine(Request $request, ActiveTenantContext $context, SalesOrder $order, SaveSalesOrderLineAction $saveLine, PosDraftCheckoutCalculator $posSummary): JsonResponse
    {
        [$organization, $store] = $this->operationalContext($request, $context);
        $this->authorize('update', $order);
        $this->ensurePosDraft($order);
        $data = $this->validateDraftLine($request);

        if ($data['line_type'] === 'catalog') {
            $warehouse = $this->draftWarehouseOrFail($organization, $order, $data['warehouse_id'] ?? null);
            $variant = $this->catalogVariantForPos($organization, (int) $data['product_variant_id']);
            $line = $this->findIncrementableCatalogLine($order, $variant->getKey());
            $quantity = $line ? Decimal::add($line->quantity, $data['quantity']) : $data['quantity'];
            $this->assertCatalogAvailability($organization, $warehouse, $variant);
            $payload = array_replace($data, ['warehouse_id' => $warehouse->getKey(), 'quantity' => $quantity]);
            $saveLine->execute($request->user(), $order, $payload, $line);
        } else {
            $saveLine->execute($request->user(), $order, $data);
        }

        return $this->draftStateResponse($organization, $store, $order->fresh($this->draftRelations()), $posSummary);
    }

    public function updateDraftLine(Request $request, ActiveTenantContext $context, SalesOrder $order, SalesOrderLine $line, SaveSalesOrderLineAction $saveLine, PosDraftCheckoutCalculator $posSummary): JsonResponse
    {
        [$organization, $store] = $this->operationalContext($request, $context);
        $this->authorize('update', $order);
        $this->ensurePosDraft($order);
        abort_unless($line->sales_order_id === $order->getKey(), 404);
        $data = $this->validateDraftLine($request);

        if ($data['line_type'] === 'catalog') {
            $warehouse = $this->draftWarehouseOrFail($organization, $order, $data['warehouse_id'] ?? $order->pos_warehouse_id);
            $variant = $this->catalogVariantForPos($organization, (int) $data['product_variant_id']);
            $this->assertCatalogAvailability($organization, $warehouse, $variant);
            $data['warehouse_id'] = $warehouse->getKey();
        }

        $saveLine->execute($request->user(), $order, $data, $line);

        return $this->draftStateResponse($organization, $store, $order->fresh($this->draftRelations()), $posSummary);
    }

    public function destroyDraftLine(Request $request, ActiveTenantContext $context, SalesOrder $order, SalesOrderLine $line, RemoveSalesOrderLineAction $removeLine, PosDraftCheckoutCalculator $posSummary): JsonResponse
    {
        [$organization, $store] = $this->operationalContext($request, $context);
        $this->authorize('update', $order);
        $this->ensurePosDraft($order);
        abort_unless($line->sales_order_id === $order->getKey(), 404);
        $removeLine->execute($request->user(), $order, $line);

        return $this->draftStateResponse($organization, $store, $order->fresh($this->draftRelations()), $posSummary);
    }

    public function holdDraft(Request $request, ActiveTenantContext $context, SalesOrder $order, CreateSalesOrderAction $createOrder, AuditLogger $audit, PosDraftCheckoutCalculator $posSummary): JsonResponse
    {
        [$organization, $store] = $this->operationalContext($request, $context);
        $this->authorize('update', $order);
        $this->ensurePosDraft($order);
        $data = $request->validate([
            'start_new_sale' => ['nullable', 'boolean'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);

        $order->pos_held_at = now();
        $order->save();
        $audit->record('sales_order.pos_held', $request->user(), $organization, $store, $order, newValues: [
            'order_number' => $order->order_number,
            'pos_warehouse_id' => $order->pos_warehouse_id,
        ]);

        $active = null;
        if (($data['start_new_sale'] ?? false) === true) {
            $warehouse = $this->warehouse($organization, (int) ($data['warehouse_id'] ?? $this->defaultWarehouseId($organization)));
            $active = $createOrder->execute($request->user(), $organization, $store, [
                'customer_id' => null,
                'sale_date' => now()->toDateString(),
                'currency_code' => config('platform.currency_code', 'MAD'),
                'notes' => null,
            ], SalesOrderSource::Pos);
            $active->pos_warehouse_id = $warehouse->getKey();
            $active->pos_global_discount_type = SalesOrderDiscountType::None->value;
            $active->pos_global_discount_value = '0.0000';
            $active->pos_fulfillment_mode = 'pickup';
            $active->pos_shipping_fee = '0.0000';
            $active->save();
            $active->load($this->draftRelations());
        }

        return $this->draftStateResponse($organization, $store, $active, $posSummary);
    }

    public function resumeDraft(Request $request, ActiveTenantContext $context, SalesOrder $order, AuditLogger $audit, PosDraftCheckoutCalculator $posSummary): JsonResponse
    {
        [$organization, $store] = $this->operationalContext($request, $context);
        $this->authorize('update', $order);
        $this->ensurePosDraft($order);

        $current = $this->activeDraftQuery($organization, $store)->where('id', '!=', $order->getKey())->first();
        if ($current) {
            $current->pos_held_at = now();
            $current->save();
            $audit->record('sales_order.pos_held', $request->user(), $organization, $store, $current, newValues: [
                'order_number' => $current->order_number,
                'pos_warehouse_id' => $current->pos_warehouse_id,
            ]);
        }

        $order->pos_held_at = null;
        $order->touch();
        $order->save();
        $audit->record('sales_order.pos_resumed', $request->user(), $organization, $store, $order, newValues: [
            'order_number' => $order->order_number,
            'pos_warehouse_id' => $order->pos_warehouse_id,
        ]);

        return $this->draftStateResponse($organization, $store, $order->fresh($this->draftRelations()), $posSummary);
    }

    public function destroyDraft(Request $request, ActiveTenantContext $context, SalesOrder $order, CancelSalesOrderAction $cancelOrder, PosDraftCheckoutCalculator $posSummary): JsonResponse
    {
        [$organization, $store] = $this->operationalContext($request, $context);
        $this->authorize('cancel', $order);
        $this->ensurePosDraft($order);
        $cancelOrder->execute($request->user(), $order, 'POS hold discarded');

        return $this->draftStateResponse($organization, $store, $this->activeDraftQuery($organization, $store)->with($this->draftRelations())->latest('updated_at')->first(), $posSummary);
    }

    public function complete(CompletePosSaleRequest $request, ActiveTenantContext $context, CreatePosSaleAction $action): RedirectResponse
    {
        [$organization, $store] = $this->operationalContext($request, $context);
        $data = $request->validated();

        foreach (['sales_orders.create', 'sales_orders.update', 'sales_orders.confirm', 'payments.create'] as $permission) {
            abort_unless($request->user()->hasPermission($organization, $permission), 403);
        }

        $order = $action->execute($request->user(), $organization, $store, $data);

        return redirect()->route('pos.index')->with([
            'pos.completed_order_id' => $order->getKey(),
            'pos.completed_tenders' => $this->tenderSummary($data['payments']),
        ]);
    }

    /** @return array{Organization, Store} */
    private function operationalContext(Request $request, ActiveTenantContext $context): array
    {
        $organization = $context->organizationOrFail();
        $this->authorizePosAccess($request, $organization);

        return [$organization, $context->storeOrFail()];
    }

    private function authorizePosAccess(Request $request, Organization $organization): void
    {
        abort_unless($request->user()->hasPermission($organization, 'pos.access'), 403);
    }

    private function warehouse(Organization $organization, int $warehouseId): Warehouse
    {
        return Warehouse::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', WarehouseStatus::Active->value)
            ->whereKey($warehouseId)
            ->firstOrFail();
    }

    private function draftWarehouseOrFail(Organization $organization, SalesOrder $order, mixed $warehouseId): Warehouse
    {
        $warehouseId = $warehouseId ? (int) $warehouseId : (int) $order->pos_warehouse_id;
        if ($warehouseId <= 0) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'Sélectionnez un entrepôt opérationnel avant d’ajouter des produits.',
            ]);
        }

        return $this->warehouse($organization, $warehouseId);
    }

    private function catalogVariantForPos(Organization $organization, int $variantId): ProductVariant
    {
        $variant = ProductVariant::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', CatalogStatus::Active->value)
            ->whereKey($variantId)
            ->with('product')
            ->firstOrFail();

        abort_unless($variant->product->status === CatalogStatus::Active, 404);

        return $variant;
    }

    private function ensurePosDraft(SalesOrder $order): void
    {
        abort_unless(
            $order->source === SalesOrderSource::Pos
            && $order->status === SalesOrderStatus::Draft,
            404,
        );
    }

    /** @return array<string, mixed> */
    private function validateDraftLine(Request $request): array
    {
        $data = $request->validate([
            'line_type' => ['required', 'in:catalog,custom'],
            'product_variant_id' => ['nullable', 'required_if:line_type,catalog', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'required_if:line_type,custom', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'unit_label' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'regex:/^[1-9]\d*$/'],
            'unit_price_excl_tax' => ['nullable', 'required_if:line_type,custom', 'decimal:0,4', 'gte:0'],
            'tax_rate_id' => ['nullable', 'integer'],
            'discount_type' => ['required', 'in:none,fixed,percentage'],
            'discount_value' => ['nullable', 'decimal:0,4', 'gte:0'],
        ]);

        $data['quantity'] = Decimal::normalize($data['quantity']);
        $data['reference'] = filled($data['reference'] ?? null) ? trim((string) $data['reference']) : null;
        $data['unit_label'] = filled($data['unit_label'] ?? null) ? trim((string) $data['unit_label']) : null;
        $data['description'] = filled($data['description'] ?? null) ? trim((string) $data['description']) : null;
        $data['discount_value'] = Decimal::normalize($data['discount_value'] ?? '0');

        return $data;
    }

    private function findIncrementableCatalogLine(SalesOrder $order, int $variantId): ?SalesOrderLine
    {
        return $order->lines()
            ->where('line_type', 'catalog')
            ->where('product_variant_id', $variantId)
            ->where('discount_type', 'none')
            ->where('discount_value', '0.0000')
            ->whereHas('allocations', fn ($query) => $query->where('warehouse_id', $order->pos_warehouse_id))
            ->first();
    }

    /**
     * Tenant-identity guard for a catalogue line. A company-stock shortfall is
     * NO LONGER a reason to reject the line: the missing quantity becomes a
     * supplier special-order requirement, surfaced in the cart as "À
     * approvisionner". The authoritative company-stock check happens at
     * confirmation (ConfirmSalesOrderAction) / POS checkout.
     */
    private function assertCatalogAvailability(Organization $organization, Warehouse $warehouse, ProductVariant $variant): void
    {
        abort_unless(
            $warehouse->organization_id === $organization->getKey()
            && $variant->organization_id === $organization->getKey(),
            404,
        );
    }

    private function syncDraftHeader(Request $request, SalesOrder $order, array $data): void
    {
        $order->pos_warehouse_id = (int) $data['warehouse_id'];
        if (array_key_exists('customer_id', $data)) {
            app(UpdateSalesOrderAction::class)->execute($request->user(), $order, [
                'customer_id' => $data['customer_id'],
                'sale_date' => $order->sale_date->toDateString(),
                'currency_code' => $order->currency_code,
                'notes' => $order->notes,
            ]);
        } else {
            $order->save();
        }
    }

    private function defaultWarehouseId(Organization $organization): int
    {
        return (int) Warehouse::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', WarehouseStatus::Active->value)
            ->orderBy('name')
            ->value('id');
    }

    private function activeDraftQuery(Organization $organization, Store $store)
    {
        return SalesOrder::query()
            ->where('organization_id', $organization->getKey())
            ->where('store_id', $store->getKey())
            ->where('source', SalesOrderSource::Pos->value)
            ->where('status', SalesOrderStatus::Draft->value)
            ->whereNull('pos_held_at');
    }

    private function heldDraftQuery(Organization $organization, Store $store)
    {
        return SalesOrder::query()
            ->where('organization_id', $organization->getKey())
            ->where('store_id', $store->getKey())
            ->where('source', SalesOrderSource::Pos->value)
            ->where('status', SalesOrderStatus::Draft->value)
            ->whereNotNull('pos_held_at');
    }

    /** @return list<string> */
    private function draftRelations(): array
    {
        return [
            'customer:id,type,display_name,company_name,phone,email,tax_identifier,billing_address',
            'posWarehouse:id,name,code',
            'lines.allocations.warehouse:id,name,code',
            'lines.productVariant.product.brand:id,name',
            'lines.productVariant.product:id,name,image_url,brand_id',
            'lines.procurements.supplier:id,name',
        ];
    }

    private function draftStateResponse(Organization $organization, Store $store, ?SalesOrder $active, PosDraftCheckoutCalculator $posSummary): JsonResponse
    {
        $heldSales = $this->heldDraftQuery($organization, $store)
            ->with(['customer:id,display_name', 'posWarehouse:id,name,code', 'lines'])
            ->latest('pos_held_at')
            ->limit(20)
            ->get()
            ->map(fn (SalesOrder $order) => $this->heldSalePayload($order))
            ->values();

        return response()->json([
            'active_sale' => $active ? $this->draftPayload($active, $posSummary) : null,
            'held_sales' => $heldSales,
        ]);
    }

    /** @return array<string, mixed> */
    private function draftPayload(SalesOrder $order, PosDraftCheckoutCalculator $posSummary): array
    {
        $order->loadMissing($this->draftRelations());
        $summary = $posSummary->summary($order);
        $warnings = [];
        $variantIds = $order->lines->pluck('product_variant_id')->filter()->unique()->values();
        $balances = InventoryBalance::query()
            ->where('organization_id', $order->organization_id)
            ->when($variantIds->isNotEmpty(), fn ($query) => $query->whereIn('product_variant_id', $variantIds))
            ->whereHas('warehouse', fn ($query) => $query->where('status', WarehouseStatus::Active->value))
            ->get()
            ->keyBy(fn (InventoryBalance $balance) => $balance->product_variant_id.':'.$balance->warehouse_id);
        $organizationAvailability = $balances->groupBy('product_variant_id')->map(
            fn ($rows) => $rows->reduce(fn (string $total, InventoryBalance $balance) => Decimal::add($total, $balance->available), '0.0000'),
        );
        $orderDemand = $order->lines->whereNotNull('product_variant_id')->groupBy('product_variant_id')->map(
            fn ($lines) => $lines->reduce(fn (string $total, SalesOrderLine $line) => Decimal::add($total, $line->quantity), '0.0000'),
        );

        $lines = $order->lines->map(function (SalesOrderLine $line) use (&$warnings, $balances, $organizationAvailability, $orderDemand, $order) {
            $isCatalog = $line->line_type->value === 'catalog';
            $localBalance = $line->product_variant_id && $order->pos_warehouse_id
                ? $balances->get($line->product_variant_id.':'.$order->pos_warehouse_id)
                : null;
            $localAvailable = $localBalance?->available ?? '0.0000';
            $totalAvailable = $line->product_variant_id
                ? $organizationAvailability->get($line->product_variant_id, '0.0000')
                : '0.0000';
            $remoteRequired = $line->allocations
                ->where('warehouse_id', '!=', $order->pos_warehouse_id)
                ->reduce(fn (string $total, $allocation) => Decimal::add($total, $allocation->quantity), '0.0000');
            $insufficient = $isCatalog
                && Decimal::compare($orderDemand->get($line->product_variant_id, $line->quantity), $totalAvailable) > 0;

            // Supplier special-order sourcing for this line. A ProductVariant is
            // never duplicated — the procured quantity is the SUPPLIER_ORDER
            // portion of THIS line.
            $activeProcs = $isCatalog
                ? $line->procurements->reject(fn ($p) => in_array($p->status->value, ['cancelled', 'unavailable'], true))
                : collect();
            $procConfirmed = $activeProcs
                ->filter(fn ($p) => in_array($p->status->value, ['supplier_confirmed', 'ordered', 'received', 'completed'], true))
                ->reduce(fn (string $t, $p) => Decimal::add($t, $p->quantity), '0.0000');
            $companyCovered = Decimal::compare($totalAvailable, $line->quantity) >= 0 ? Decimal::normalize($line->quantity) : $totalAvailable;
            $toProcure = Decimal::compare($line->quantity, $totalAvailable) > 0
                ? Decimal::subtract($line->quantity, $totalAvailable)
                : '0.0000';
            $needsProcurement = $isCatalog && Decimal::compare($toProcure, $procConfirmed) > 0;
            $procRow = $activeProcs->sortByDesc('id')->first();

            if ($needsProcurement) {
                $warnings[] = [
                    'line_id' => $line->getKey(),
                    'product_name' => $line->product_name,
                    'requested' => $line->quantity,
                    'available' => $totalAvailable,
                ];
            }

            return [
                'id' => $line->getKey(),
                'line_type' => $line->line_type->value,
                'description' => $line->product_name,
                'variant_name' => $line->variant_name,
                'sku' => $line->sku,
                'reference' => $line->reference,
                'unit_label' => $line->unit_label,
                'quantity' => $line->quantity,
                'unit_price_excl_tax' => $line->unit_price_excl_tax,
                'unit_price_incl_tax' => $line->unit_price_incl_tax,
                'tax_rate' => $line->tax_rate,
                'tax_name' => $line->tax_name,
                'discount_type' => $line->discount_type->value,
                'discount_value' => $line->discount_value,
                'line_subtotal' => $line->subtotal_excl_tax,
                'line_discount' => $line->discount_amount,
                'line_taxable' => $line->taxable_amount,
                'line_tax_amount' => $line->tax_amount,
                'line_total' => $line->total_incl_tax,
                'product_variant_id' => $line->product_variant_id,
                'image_url' => $line->productVariant?->product?->image_url,
                'brand' => $line->productVariant?->product?->brand?->only(['id', 'name']),
                'warehouse' => $order->posWarehouse?->only(['id', 'name', 'code']),
                'remote_required' => $remoteRequired,
                ...($isCatalog ? [
                    'available' => $localAvailable,
                    'local_available' => $localAvailable,
                    'total_available' => $totalAvailable,
                    'requires_replenishment' => Decimal::compare($remoteRequired, '0.0000') > 0,
                    'insufficient' => $insufficient,
                    'company_covered' => $companyCovered,
                    'to_procure' => $toProcure,
                    'procurement_confirmed_qty' => $procConfirmed,
                    'needs_procurement' => $needsProcurement,
                    'procurement' => $procRow ? [
                        'id' => $procRow->id,
                        'procurement_number' => $procRow->procurement_number,
                        'status' => $procRow->status->value,
                        'status_label' => $procRow->status->label(),
                        'supplier_availability_status' => $procRow->supplier_availability_status->value,
                        'supplier' => $procRow->supplier?->only(['id', 'name']),
                        'quantity' => $procRow->quantity,
                    ] : null,
                ] : []),
            ];
        })->values();
        $remoteRequired = $lines->reduce(fn (string $total, array $line) => Decimal::add($total, $line['remote_required']), '0.0000');

        return [
            'id' => $order->getKey(),
            'order_number' => $order->order_number,
            'customer' => $order->customer?->only(['id', 'type', 'display_name', 'company_name', 'phone', 'email', 'tax_identifier', 'billing_address']),
            'warehouse' => $order->posWarehouse?->only(['id', 'name', 'code']),
            'sale_date' => $order->sale_date->toDateString(),
            'currency_code' => $order->currency_code,
            'held_at' => $order->pos_held_at?->toIso8601String(),
            'lines' => $lines,
            'availability_warnings' => array_values($warnings),
            'requires_replenishment' => Decimal::compare($remoteRequired, '0.0000') > 0,
            'remote_required' => $remoteRequired,
            'procurement_deficit' => $warnings !== [],
            'awaiting_supplier_procurement' => $order->awaitingSupplierProcurement(),
            'procurements_count' => $order->lines->sum(fn (SalesOrderLine $line) => $line->procurements
                ->reject(fn ($p) => in_array($p->status->value, ['cancelled', 'unavailable'], true))->count()),
            'checkout' => [
                'global_discount_type' => $summary['global_discount_type'],
                'global_discount_value' => $summary['global_discount_value'],
                'global_discount_amount' => $summary['global_discount_amount'],
                'fulfillment_mode' => $order->pos_fulfillment_mode ?? 'pickup',
                'shipping_fee' => $summary['shipping_fee'],
                'delivery_address' => $order->pos_delivery_address,
                'delivery_phone' => $order->pos_delivery_phone,
                'delivery_notes' => $order->pos_delivery_notes,
            ],
            'summary' => [
                'merchandise_total' => $summary['merchandise_total'],
                'subtotal_excl_tax' => $summary['subtotal_excl_tax'],
                'line_discount_total' => $summary['line_discount_total'],
                'global_discount_amount' => $summary['global_discount_amount'],
                'net_excl_tax' => $summary['net_excl_tax'],
                'tax_total' => $summary['tax_total'],
                'shipping_fee' => $summary['shipping_fee'],
                'total' => $summary['total'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function heldSalePayload(SalesOrder $order): array
    {
        $productCount = $order->lines->reduce(fn (int $carry, SalesOrderLine $line) => $carry + (int) $line->quantity, 0);

        return [
            'id' => $order->getKey(),
            'order_number' => $order->order_number,
            'held_at' => $order->pos_held_at?->toIso8601String(),
            'customer_name' => $order->customer_name,
            'warehouse' => $order->posWarehouse?->only(['id', 'name', 'code']),
            'product_count' => $productCount,
            'line_count' => $order->lines->count(),
            'subtotal' => $order->total_incl_tax,
            'currency_code' => $order->currency_code,
        ];
    }

    /** @param list<array<string, mixed>> $tenders
     * @return array<string, mixed>
     */
    private function completedOrder(SalesOrder $order, SalesOrderPaymentCalculator $calculator, array $tenders): array
    {
        $summary = $calculator->summary($order);
        $payments = $order->paymentAllocations
            ->filter(fn ($allocation) => $allocation->payment->client_operation_id === $order->client_operation_id)
            ->sortBy(fn ($allocation) => $allocation->payment->operation_sequence)
            ->values()
            ->map(function ($allocation, int $index) use ($tenders) {
                $payment = $allocation->payment;

                return [
                    'id' => $payment->getKey(),
                    'payment_number' => $payment->payment_number,
                    'method' => $payment->method->value,
                    'amount' => $allocation->amount,
                    'reference' => $payment->reference,
                    'account' => $payment->financialAccount->only(['id', 'name', 'code', 'type']),
                    'cash_received' => $tenders[$index]['cash_received'] ?? null,
                    'change' => $tenders[$index]['change'] ?? null,
                ];
            });

        $remoteRequired = $order->lines->flatMap->allocations
            ->where('warehouse_id', '!=', $order->pos_warehouse_id)
            ->reduce(fn (string $total, $allocation) => Decimal::add($total, $allocation->quantity), '0.0000');

        return [
            ...$order->only(['id', 'order_number', 'customer_name', 'ordered_at', 'subtotal_excl_tax', 'discount_total', 'tax_total', 'total_incl_tax', 'currency_code', 'status', 'fulfillment_status', 'payment_status']),
            'paid' => $summary['paid'],
            'remaining' => $summary['remaining'],
            'requires_replenishment' => Decimal::compare($remoteRequired, '0.0000') > 0,
            'remote_required' => $remoteRequired,
            'payments' => $payments,
            'lines' => $order->lines->map(fn (SalesOrderLine $line) => [
                'id' => $line->getKey(),
                'description' => $line->product_name,
                'quantity' => $line->quantity,
                'unit_price_incl_tax' => $line->unit_price_incl_tax,
                'line_total' => $line->total_incl_tax,
            ])->values(),
        ];
    }

    /** @param list<array<string, mixed>> $payments
     * @return list<array{cash_received: ?string, change: ?string}>
     */
    private function tenderSummary(array $payments): array
    {
        return array_map(function (array $payment) {
            if ($payment['method'] !== 'cash') {
                return ['cash_received' => null, 'change' => null];
            }

            $amount = Decimal::normalize($payment['amount']);
            $received = Decimal::normalize($payment['cash_received']);

            return ['cash_received' => $received, 'change' => Decimal::subtract($received, $amount)];
        }, array_values($payments));
    }
}
