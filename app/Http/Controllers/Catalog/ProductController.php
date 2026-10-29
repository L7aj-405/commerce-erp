<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\ArchiveProductAction;
use App\Actions\Catalog\CreateProductAction;
use App\Actions\Catalog\UpdateProductAction;
use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductImport;
use App\Models\TaxRate;
use App\Models\UnitOfMeasure;
use App\Services\ActiveTenantContext;
use App\Services\Catalog\CatalogProductDataValidator;
use App\Services\ProductPriceResolver;
use App\Services\Procurement\SupplierCatalogQuery;
use App\Support\InventoryQuantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [Product::class, $organization]);
        $canViewInventory = $request->user()->hasPermission($organization->id, 'inventory.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(CatalogStatus::class)],
            'brand' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('organization_id', $organization->id)],
            'category' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('organization_id', $organization->id)],
        ]);

        $products = Product::query()
            ->where('organization_id', $organization->id)
            ->with(['brand:id,name', 'defaultCategory:id,name', 'defaultUnit:id,name,symbol', 'variants:id,product_id,sku,reference,barcode,regular_sale_price,promotional_sale_price,default_sale_price,status'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhereHas('brand', fn ($query) => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('variants', fn ($query) => $query
                            ->where('sku', 'like', "%{$search}%")
                            ->orWhere('reference', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%"));
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['brand'] ?? null, fn ($query, int $brand) => $query->where('brand_id', $brand))
            ->when($filters['category'] ?? null, fn ($query, int $category) => $query->where('default_category_id', $category))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $availabilityByProduct = collect();
        if ($canViewInventory && $products->count() > 0) {
            $variantIds = $products->getCollection()->flatMap(fn (Product $product) => $product->variants->pluck('id'))->values();
            $variantAvailability = InventoryBalance::query()
                ->where('organization_id', $organization->id)
                ->whereIn('product_variant_id', $variantIds)
                ->get()
                ->groupBy('product_variant_id')
                ->map(fn ($rows) => $rows->reduce(fn (string $carry, InventoryBalance $balance) => InventoryQuantity::add($carry, $balance->available), InventoryQuantity::ZERO));

            $availabilityByProduct = $products->getCollection()->mapWithKeys(function (Product $product) use ($variantAvailability) {
                $total = $product->variants->reduce(
                    fn (string $carry, $variant) => InventoryQuantity::add($carry, $variantAvailability->get($variant->id, InventoryQuantity::ZERO)),
                    InventoryQuantity::ZERO,
                );

                return [$product->id => $total];
            });
        }

        $products->setCollection(
            $products->getCollection()->map(function (Product $product) use ($availabilityByProduct, $canViewInventory) {
                $product->setAttribute('availability_total', $canViewInventory ? $availabilityByProduct->get($product->id, InventoryQuantity::ZERO) : null);

                return $product;
            }),
        );

        return Inertia::render('Catalog/Products/Index', [
            'products' => $products,
            'filters' => $filters,
            'brands' => $organization->brands()->orderBy('name')->get(['id', 'name']),
            'categories' => $organization->categories()->orderBy('name')->get(['id', 'name']),
            'can' => [
                'create' => $request->user()->can('create', [Product::class, $organization]),
                'import' => $request->user()->can('create', [ProductImport::class, $organization]),
                'inventoryView' => $canViewInventory,
            ],
        ]);
    }

    public function create(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [Product::class, $organization]);

        return Inertia::render('Catalog/Products/Form', [
            'product' => null,
            'canManageCost' => $request->user()->hasPermission($organization, 'product_cost.manage'),
            ...$this->lookups($organization->id),
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, CreateProductAction $action, CatalogProductDataValidator $validator): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [Product::class, $organization]);
        $canManageCost = $request->user()->hasPermission($organization, 'product_cost.manage');
        $product = $action->execute($request->user(), $organization, $validator->product($request->all(), $organization->id, true, $canManageCost));

        return redirect()->route('catalog.products.show', $product)->with('success', 'Produit créé avec succès.');
    }

    public function show(Request $request, Product $product, ActiveTenantContext $context, ProductPriceResolver $priceResolver): Response
    {
        $this->authorize('view', $product);
        $canViewStock = $request->user()->hasPermission($product->organization_id, 'inventory.view');
        $canTransfer = $request->user()->hasPermission($product->organization_id, 'inventory.transfer');
        $canViewCost = $request->user()->hasPermission($product->organization_id, 'product_cost.view');
        $product->load(['brand', 'defaultCategory', 'defaultUnit', 'variants.taxRate']);
        if (! $canViewCost) {
            $product->variants->each->makeHidden('purchase_price');
        }

        $defaultTaxRate = $priceResolver->defaultTaxRate($context->store(), (int) $product->organization_id);
        $priceByVariant = $product->variants->mapWithKeys(function ($variant) use ($priceResolver, $defaultTaxRate) {
            $resolution = $priceResolver->resolveWith($variant, $defaultTaxRate);

            return [$variant->getKey() => [
                'ttc' => $resolution['unit_price_ttc'],
                'ht' => $resolution['unit_price_ht'],
                'ht_source' => $resolution['ht_source'],
                'tax_name' => $resolution['tax_name'],
                'tax_rate' => $resolution['tax_rate_value'],
                'config_missing' => $resolution['config_missing'],
            ]];
        });

        $stock = null;
        if ($canViewStock) {
            $balances = InventoryBalance::query()
                ->where('organization_id', $product->organization_id)
                ->whereIn('product_variant_id', $product->variants->pluck('id'))
                ->with('warehouse:id,name,code')
                ->orderBy('warehouse_id')
                ->get();

            $byVariant = $balances->groupBy('product_variant_id')->map(function ($variantBalances) {
                return $variantBalances->map(function (InventoryBalance $balance) {
                    return [
                        'warehouse' => $balance->warehouse->only(['id', 'name', 'code']),
                        'on_hand' => $balance->on_hand,
                        'reserved' => $balance->reserved,
                        'available' => $balance->available,
                    ];
                })->values();
            });

            $stock = [
                'variants' => $product->variants->map(function ($variant) use ($byVariant) {
                    $warehouseStock = $byVariant->get($variant->getKey(), collect());
                    $total = $warehouseStock->reduce(fn (string $carry, array $row) => InventoryQuantity::add($carry, $row['available']), InventoryQuantity::ZERO);

                    return [
                        'id' => $variant->getKey(),
                        'label' => $variant->label,
                        'sku' => $variant->sku,
                        'available_total' => $total,
                        'warehouses' => $warehouseStock,
                    ];
                })->values(),
            ];
        }

        // Supplier catalog (B1): one grouped read for all variants of the product.
        $organizationId = (int) $product->organization_id;
        $canViewSuppliers = $request->user()->hasPermission($organizationId, 'suppliers.view');
        $canViewSupplierPrices = SupplierCatalogQuery::canViewPrices($request->user(), $organizationId);
        $supplierCatalog = $canViewSuppliers
            ? app(SupplierCatalogQuery::class)->forVariants($organizationId, $product->variants->pluck('id')->map(fn ($id) => (int) $id)->all(), $canViewSupplierPrices)
            : null;

        return Inertia::render('Catalog/Products/Show', [
            'product' => $product,
            'stock' => $stock,
            'priceByVariant' => $priceByVariant,
            'supplierCatalog' => $supplierCatalog === null ? null : (object) $supplierCatalog,
            'defaultCurrency' => config('platform.currency_code', 'MAD'),
            'can' => [
                'create' => $request->user()->can('create', [Product::class, $product->organization]),
                'update' => $request->user()->can('update', $product),
                'archive' => $request->user()->can('archive', $product),
                'stock' => $canViewStock,
                'transfer' => $canTransfer,
                'viewSuppliers' => $canViewSuppliers,
                'manageSuppliers' => $canViewSuppliers && $request->user()->hasPermission($organizationId, 'supplier_catalog.manage'),
                'viewSupplierPrices' => $canViewSupplierPrices,
            ],
        ]);
    }

    public function edit(Request $request, Product $product): Response
    {
        $this->authorize('update', $product);
        $canManageCost = $request->user()->hasPermission($product->organization_id, 'product_cost.manage');
        $product->load('variants');
        if (! $request->user()->hasPermission($product->organization_id, 'product_cost.view')) {
            $product->variants->each->makeHidden('purchase_price');
        }

        return Inertia::render('Catalog/Products/Form', [
            'product' => $product,
            'canManageCost' => $canManageCost,
            ...$this->lookups($product->organization_id),
        ]);
    }

    public function update(Request $request, Product $product, UpdateProductAction $action, CatalogProductDataValidator $validator): RedirectResponse
    {
        $this->authorize('update', $product);
        $data = $validator->product($request->all(), $product->organization_id, false, false);
        $action->execute($request->user(), $product, $data);

        return redirect()->route('catalog.products.show', $product);
    }

    public function archive(Request $request, Product $product, ArchiveProductAction $action): RedirectResponse
    {
        $this->authorize('archive', $product);
        $action->execute($request->user(), $product);

        return redirect()->route('catalog.products.index');
    }

    /** @return array<string, mixed> */
    private function lookups(int $organizationId): array
    {
        return [
            'brands' => Brand::query()->where('organization_id', $organizationId)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'categories' => Category::query()->where('organization_id', $organizationId)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'units' => UnitOfMeasure::query()->where('organization_id', $organizationId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'symbol']),
            'taxRates' => TaxRate::query()->where('organization_id', $organizationId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'rate', 'is_default']),
        ];
    }

}
