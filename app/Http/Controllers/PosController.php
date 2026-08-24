<?php

namespace App\Http\Controllers;

use App\Actions\Pos\CreatePosSaleAction;
use App\Enums\CatalogStatus;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\SalesOrderSource;
use App\Enums\WarehouseStatus;
use App\Http\Requests\Pos\CompletePosSaleRequest;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\TaxRate;
use App\Models\Warehouse;
use App\Services\ActiveTenantContext;
use App\Services\CustomerManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
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

        $taxRates = $store
            ? TaxRate::query()
                ->where('organization_id', $organization->getKey())
                ->where('status', CatalogStatus::Active->value)
                ->orderBy('name')
                ->get(['id', 'name', 'rate'])
            : collect();

        $completedOrder = $store && $request->session()->has('pos.completed_order_id')
            ? SalesOrder::query()
                ->where('organization_id', $organization->getKey())
                ->where('store_id', $store->getKey())
                ->where('source', SalesOrderSource::Pos->value)
                ->whereKey($request->session()->get('pos.completed_order_id'))
                ->first(['id', 'order_number', 'total_incl_tax', 'currency_code', 'status', 'fulfillment_status', 'payment_status'])
            : null;

        $recentSales = $store && $request->user()->hasPermission($organization, 'sales_orders.view')
            ? SalesOrder::query()
                ->where('organization_id', $organization->getKey())
                ->where('store_id', $store->getKey())
                ->where('source', SalesOrderSource::Pos->value)
                ->latest('ordered_at')
                ->limit(10)
                ->get(['id', 'order_number', 'customer_name', 'total_incl_tax', 'currency_code', 'payment_status', 'ordered_at'])
            : collect();

        return Inertia::render('Pos/Index', [
            'store' => $store?->only(['id', 'name', 'code']),
            'warehouses' => $warehouses,
            'taxRates' => $taxRates,
            'currencyCode' => config('platform.currency_code', 'MAD'),
            'can' => [
                'createCustomer' => $request->user()->hasPermission($organization, 'customers.create'),
                'overridePrice' => $request->user()->hasPermission($organization, 'sales_orders.override_price'),
                'applyDiscount' => $request->user()->hasPermission($organization, 'sales_orders.apply_discount'),
                'addCustomItem' => $request->user()->hasPermission($organization, 'sales_orders.update'),
            ],
            'createdCustomer' => $request->session()->get('pos.created_customer'),
            'completedOrder' => $completedOrder,
            'recentSales' => $recentSales,
        ]);
    }

    public function products(Request $request, ActiveTenantContext $context): JsonResponse
    {
        [$organization] = $this->operationalContext($request, $context);
        abort_unless(
            $request->user()->hasPermission($organization, 'products.view')
            && $request->user()->hasPermission($organization, 'inventory.view'),
            403,
        );

        $filters = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'search' => ['nullable', 'string', 'max:255', 'required_without:barcode'],
            'barcode' => ['nullable', 'string', 'max:255', 'required_without:search'],
        ]);
        $warehouse = $this->warehouse($organization, (int) $filters['warehouse_id']);

        $variants = ProductVariant::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', CatalogStatus::Active->value)
            ->whereHas('product', fn ($query) => $query->where('status', CatalogStatus::Active->value))
            ->when($filters['barcode'] ?? null, fn ($query, string $barcode) => $query->where('barcode', $barcode))
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('sku', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($query) => $query->where('name', 'like', "%{$search}%"))))
            ->with([
                'product:id,name',
                'taxRate:id,name,rate',
                'inventoryBalances' => fn ($query) => $query->where('warehouse_id', $warehouse->getKey()),
            ])
            ->orderBy('sku')
            ->limit(isset($filters['barcode']) ? 1 : 20)
            ->get(['id', 'product_id', 'label', 'sku', 'reference', 'barcode', 'default_sale_price', 'tax_rate_id'])
            ->map(function (ProductVariant $variant) {
                $balance = $variant->inventoryBalances->first();

                return [
                    'id' => $variant->getKey(),
                    'product_name' => $variant->product->name,
                    'variant_name' => $variant->label,
                    'sku' => $variant->sku,
                    'reference' => $variant->reference,
                    'barcode' => $variant->barcode,
                    'default_sale_price' => $variant->default_sale_price,
                    'tax_rate' => $variant->taxRate?->only(['id', 'name', 'rate']),
                    'stock' => [
                        'on_hand' => $balance?->on_hand ?? '0.0000',
                        'reserved' => $balance?->reserved ?? '0.0000',
                        'available' => $balance?->available ?? '0.0000',
                    ],
                ];
            });

        return response()->json(['data' => $variants]);
    }

    public function customers(Request $request, ActiveTenantContext $context): JsonResponse
    {
        [$organization] = $this->operationalContext($request, $context);
        $this->authorize('viewAny', [Customer::class, $organization]);
        $filters = $request->validate(['search' => ['required', 'string', 'max:255']]);

        $customers = Customer::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', CustomerStatus::Active->value)
            ->where(fn ($query) => $query
                ->where('display_name', 'like', "%{$filters['search']}%")
                ->orWhere('company_name', 'like', "%{$filters['search']}%")
                ->orWhere('phone', 'like', "%{$filters['search']}%")
                ->orWhere('email', 'like', "%{$filters['search']}%"))
            ->orderBy('display_name')
            ->limit(20)
            ->get(['id', 'display_name', 'company_name', 'phone', 'email']);

        return response()->json(['data' => $customers]);
    }

    public function storeCustomer(Request $request, ActiveTenantContext $context, CustomerManager $manager): RedirectResponse
    {
        [$organization] = $this->operationalContext($request, $context);
        $this->authorize('create', [Customer::class, $organization]);
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        $customer = $manager->create($request->user(), $organization, [
            ...$data,
            'type' => CustomerType::Individual->value,
            'status' => CustomerStatus::Active->value,
        ]);

        return redirect()->route('pos.index')->with('pos.created_customer', $customer->only([
            'id', 'display_name', 'company_name', 'phone', 'email',
        ]));
    }

    public function complete(CompletePosSaleRequest $request, ActiveTenantContext $context, CreatePosSaleAction $action): RedirectResponse
    {
        [$organization, $store] = $this->operationalContext($request, $context);
        $data = $request->validated();
        $this->warehouse($organization, (int) $data['warehouse_id']);

        foreach (['sales_orders.create', 'sales_orders.update', 'sales_orders.confirm', 'sales_orders.fulfill'] as $permission) {
            abort_unless($request->user()->hasPermission($organization, $permission), 403);
        }

        $order = $action->execute($request->user(), $organization, $store, $data);

        return redirect()->route('pos.index')->with('pos.completed_order_id', $order->getKey());
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
}
