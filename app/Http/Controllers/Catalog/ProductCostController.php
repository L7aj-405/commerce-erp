<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\ConfirmProductCostImportAction;
use App\Actions\Catalog\StageProductCostImportAction;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductCostImport;
use App\Models\ProductVariant;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\ProductCosts\ProductCostExcelExport;
use App\Services\ProductCosts\ProductCostVariantQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductCostController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context, ProductCostVariantQuery $costs): Response
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'product_cost.view'), 403);
        $filters = $this->filters($request, $organization->getKey());
        $variants = $costs->build($organization, $filters)->paginate(25)->withQueryString();

        return Inertia::render('Catalog/ProductCosts/Index', [
            'variants' => $variants,
            'filters' => $filters,
            'coverage' => $costs->coverage($organization),
            'brands' => Brand::query()->where('organization_id', $organization->getKey())->orderBy('name')->get(['id', 'name']),
            'categories' => Category::query()->where('organization_id', $organization->getKey())->orderBy('name')->get(['id', 'name']),
            'can' => [
                'export' => $request->user()->hasPermission($organization, 'product_cost.export'),
                'import' => $request->user()->hasPermission($organization, 'product_cost.import'),
                'manage' => $request->user()->hasPermission($organization, 'product_cost.manage'),
            ],
        ]);
    }

    public function export(Request $request, ActiveTenantContext $context, ProductCostVariantQuery $costs, ProductCostExcelExport $export, AuditLogger $audit): BinaryFileResponse
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'product_cost.export'), 403);
        $filters = $this->filters($request, $organization->getKey());
        $query = $costs->build($organization, $filters);
        $count = (clone $query)->count();
        $path = $export->build($query);
        $audit->record('product_cost.exported', $request->user(), $organization, newValues: [
            'rows_exported' => $count,
            'filters' => $filters,
        ]);

        return response()->download($path, 'prix-achat-'.now()->format('Y-m-d-His').'.xlsx')->deleteFileAfterSend(true);
    }

    public function storeImport(Request $request, ActiveTenantContext $context, StageProductCostImportAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'product_cost.import'), 403);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('catalog_imports.max_file_kb')],
            'organization_id' => ['prohibited'],
            'store_id' => ['prohibited'],
        ]);
        $import = $action->execute($request->user(), $organization, $data['file']);

        return redirect()->route('catalog.product-costs.imports.show', $import);
    }

    public function showImport(Request $request, ActiveTenantContext $context, int $productCostImport): Response
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'product_cost.import'), 403);
        $import = ProductCostImport::query()->where('organization_id', $organization->getKey())
            ->whereKey($productCostImport)->with('createdBy:id,name')->firstOrFail();

        return Inertia::render('Catalog/ProductCosts/ImportPreview', [
            'import' => $import,
            'rows' => $import->rows()->paginate(100),
        ]);
    }

    public function confirmImport(Request $request, ActiveTenantContext $context, int $productCostImport, ConfirmProductCostImportAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'product_cost.import'), 403);
        $import = ProductCostImport::query()->where('organization_id', $organization->getKey())->whereKey($productCostImport)->firstOrFail();
        $action->execute($request->user(), $import);

        return redirect()->route('catalog.product-costs.imports.show', $import)->with('success', 'Prix d’achat mis à jour avec succès.');
    }

    public function update(Request $request, ActiveTenantContext $context, int $variant, AuditLogger $audit): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'product_cost.manage'), 403);
        $productVariant = ProductVariant::query()->where('organization_id', $organization->getKey())->whereKey($variant)->firstOrFail();
        $data = $request->validate([
            'purchase_price' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
            'organization_id' => ['prohibited'],
            'product_id' => ['prohibited'],
        ]);
        $old = $productVariant->purchase_price;
        $productVariant->purchase_price = $data['purchase_price'];
        $productVariant->save();
        $audit->record('product_cost.updated', $request->user(), $organization, auditable: $productVariant,
            oldValues: ['purchase_price' => $old], newValues: ['purchase_price' => $productVariant->purchase_price]);

        return back()->with('success', 'Prix d’achat mis à jour.');
    }

    /** @return array{search?:string,stock:string,cost:string,brand?:int,category?:int} */
    private function filters(Request $request, int $organizationId): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'stock' => ['nullable', Rule::in(['all', 'in_stock', 'out_of_stock'])],
            'cost' => ['nullable', Rule::in(['all', 'missing', 'present'])],
            'brand' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('organization_id', $organizationId)],
            'category' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('organization_id', $organizationId)],
        ]);

        return [...$validated, 'stock' => $validated['stock'] ?? 'in_stock', 'cost' => $validated['cost'] ?? 'missing'];
    }
}
