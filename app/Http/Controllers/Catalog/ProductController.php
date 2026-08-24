<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\ArchiveProductAction;
use App\Actions\Catalog\CreateProductAction;
use App\Actions\Catalog\UpdateProductAction;
use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\UnitOfMeasure;
use App\Services\ActiveTenantContext;
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
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(CatalogStatus::class)],
            'brand' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('organization_id', $organization->id)],
            'category' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('organization_id', $organization->id)],
        ]);

        $products = Product::query()
            ->where('organization_id', $organization->id)
            ->with(['brand:id,name', 'defaultCategory:id,name', 'defaultUnit:id,name,symbol', 'variants:id,product_id,sku,reference,barcode,default_sale_price,status'])
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

        return Inertia::render('Catalog/Products/Index', [
            'products' => $products,
            'filters' => $filters,
            'brands' => $organization->brands()->orderBy('name')->get(['id', 'name']),
            'categories' => $organization->categories()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [Product::class, $organization]);

        return Inertia::render('Catalog/Products/Form', ['product' => null, ...$this->lookups($organization->id)]);
    }

    public function store(Request $request, ActiveTenantContext $context, CreateProductAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [Product::class, $organization]);
        $product = $action->execute($request->user(), $organization, $request->validate($this->rules($organization->id)));

        return redirect()->route('catalog.products.show', $product);
    }

    public function show(Product $product): Response
    {
        $this->authorize('view', $product);

        return Inertia::render('Catalog/Products/Show', [
            'product' => $product->load(['brand', 'defaultCategory', 'defaultUnit', 'variants.taxRate']),
        ]);
    }

    public function edit(Product $product): Response
    {
        $this->authorize('update', $product);

        return Inertia::render('Catalog/Products/Form', [
            'product' => $product->load('variants'),
            ...$this->lookups($product->organization_id),
        ]);
    }

    public function update(Request $request, Product $product, UpdateProductAction $action): RedirectResponse
    {
        $this->authorize('update', $product);
        $productRules = array_filter(
            $this->rules($product->organization_id),
            fn (string $key) => ! str_starts_with($key, 'variant'),
            ARRAY_FILTER_USE_KEY,
        );
        $data = $request->validate($productRules);
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
            'taxRates' => TaxRate::query()->where('organization_id', $organizationId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'rate']),
        ];
    }

    /** @return array<string, mixed> */
    private function rules(int $organizationId): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('organization_id', $organizationId)],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('organization_id', $organizationId)],
            'unit_id' => ['nullable', 'integer', Rule::exists('units_of_measure', 'id')->where('organization_id', $organizationId)],
            'status' => ['required', Rule::enum(CatalogStatus::class)],
            'variant' => ['required', 'array'],
            'variant.label' => ['nullable', 'string', 'max:255'],
            'variant.sku' => ['required', 'string', 'max:255', Rule::unique('product_variants', 'sku')->where('organization_id', $organizationId)],
            'variant.reference' => ['nullable', 'string', 'max:255'],
            'variant.barcode' => ['nullable', 'string', 'max:255', Rule::unique('product_variants', 'barcode')->where('organization_id', $organizationId)],
            'variant.purchase_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'variant.default_sale_price' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
            'variant.tax_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')->where('organization_id', $organizationId)],
            'variant.status' => ['sometimes', Rule::enum(CatalogStatus::class)],
        ];
    }
}
