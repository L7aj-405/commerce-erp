<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\ConfirmProductImportAction;
use App\Actions\Catalog\PreviewProductImportAction;
use App\Actions\Catalog\StageProductImportAction;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductImport;
use App\Models\TaxRate;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Services\ActiveTenantContext;
use App\Services\CatalogImport\ProductImportHeaderMapper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductImportController extends Controller
{
    public function create(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [ProductImport::class, $organization]);

        return Inertia::render('Catalog/Imports/Wizard', [
            'productImport' => null,
            'recentImports' => ProductImport::query()->where('organization_id', $organization->id)
                ->with('createdBy:id,name')->latest()->limit(10)->get(),
            'maxFileMb' => (int) config('catalog_imports.max_file_kb') / 1024,
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, StageProductImportAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [ProductImport::class, $organization]);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('catalog_imports.max_file_kb')],
            'organization_id' => ['prohibited'],
            'store_id' => ['prohibited'],
        ], ['file.max' => 'Le fichier dépasse la taille maximale autorisée.']);
        $import = $action->execute($request->user(), $organization, $data['file']);

        return redirect()->route('catalog.product-imports.show', $import);
    }

    public function show(Request $request, ProductImport $productImport, ProductImportHeaderMapper $mapper): Response
    {
        $this->authorize('view', $productImport);
        $organizationId = $productImport->organization_id;
        $rows = $productImport->rows()->limit(100)->get();
        $resultRows = in_array($productImport->status, ['completed', 'completed_with_errors', 'failed'], true)
            ? $productImport->rows()->get(['id', 'row_number', 'normalized_data', 'status', 'messages', 'error_code', 'error_phase', 'error_message'])
                ->filter(fn ($row) => $row->error_message || ($row->messages ?? []) !== [])->values()
            : collect();
        $prospectiveRows = $productImport->rows()->get(['status', 'normalized_data'])
            ->filter(fn ($row) => in_array($row->status, ['ready', 'warning'], true));
        $prospectiveCount = $prospectiveRows->filter(fn ($row) => in_array(data_get($row->normalized_data, 'product_type'), ['simple', 'variable'], true))->count()
            + $prospectiveRows->filter(fn ($row) => data_get($row->normalized_data, 'product_type') === 'grouped_variation')
                ->pluck('normalized_data.group_key')->unique()->count();

        return Inertia::render('Catalog/Imports/Wizard', [
            'productImport' => $productImport,
            'rows' => $rows,
            'resultRows' => $resultRows,
            'fields' => $mapper->fields(),
            'lookups' => [
                'brands' => Brand::query()->where('organization_id', $organizationId)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
                'categories' => Category::query()->where('organization_id', $organizationId)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
                'units' => UnitOfMeasure::query()->where('organization_id', $organizationId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'symbol']),
                'taxRates' => TaxRate::query()->where('organization_id', $organizationId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'rate']),
                'warehouses' => Warehouse::query()->where('organization_id', $organizationId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            ],
            'canImportStock' => $request->user()->hasPermission($organizationId, 'inventory.opening'),
            'prospectiveCount' => $prospectiveCount,
            'maxFileMb' => (int) config('catalog_imports.max_file_kb') / 1024,
            'recentImports' => [],
        ]);
    }

    public function preview(Request $request, ProductImport $productImport, PreviewProductImportAction $action): RedirectResponse
    {
        $this->authorize('update', $productImport);
        $submittedDefaults = $request->input('defaults', []);
        if (is_array($submittedDefaults)) {
            $request->merge(['defaults' => [...$submittedDefaults, 'stock_mode' => $request->input('defaults.stock_mode', 'skip')]]);
        }
        $headers = $productImport->headers;
        try {
            $data = $request->validate([
                'mapping' => ['required', 'array:external_product_id,name,sku,sale_price,promo_price,reference,barcode,description,category,brand,product_type,parent_sku,variant_label,image_url,external_stock_status,stock_quantity'],
                'mapping.external_product_id' => ['nullable', 'integer', 'between:0,'.(count($headers) - 1)],
                'mapping.name' => ['nullable', 'integer', 'between:0,'.(count($headers) - 1)],
                'mapping.sku' => ['nullable', 'integer', 'between:0,'.(count($headers) - 1)],
                'mapping.sale_price' => ['nullable', 'integer', 'between:0,'.(count($headers) - 1)],
                'mapping.promo_price' => ['nullable', 'integer', 'between:0,'.(count($headers) - 1)],
                'mapping.reference' => ['nullable', 'integer', 'between:0,'.(count($headers) - 1)],
                'mapping.barcode' => ['nullable', 'integer', 'between:0,'.(count($headers) - 1)],
                'mapping.description' => ['nullable', 'integer', 'between:0,'.(count($headers) - 1)],
                'mapping.category' => ['nullable', 'integer', 'between:0,'.(count($headers) - 1)],
                'mapping.brand' => ['nullable', 'integer', 'between:0,'.(count($headers) - 1)],
                'mapping.product_type' => ['nullable', 'integer', 'between:0,'.(count($headers) - 1)],
                'mapping.parent_sku' => ['nullable', 'integer', 'between:0,'.(count($headers) - 1)],
                'mapping.variant_label' => ['nullable', 'integer', 'between:0,'.(count($headers) - 1)],
                'mapping.image_url' => ['nullable', 'integer', 'between:0,'.(count($headers) - 1)],
                'mapping.external_stock_status' => ['nullable', 'integer', 'between:0,'.(count($headers) - 1)],
                'mapping.stock_quantity' => ['nullable', 'integer', 'between:0,'.(count($headers) - 1)],
                'defaults' => ['array:category_id,brand_id,unit_id,tax_rate_id,stock_mode,warehouse_id'],
                'defaults.category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('organization_id', $productImport->organization_id)],
                'defaults.brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('organization_id', $productImport->organization_id)],
                'defaults.unit_id' => ['nullable', 'integer', Rule::exists('units_of_measure', 'id')->where('organization_id', $productImport->organization_id)],
                'defaults.tax_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')->where('organization_id', $productImport->organization_id)],
                'defaults.stock_mode' => ['required', Rule::in(['skip', 'import'])],
                'defaults.warehouse_id' => [
                    'nullable', 'required_if:defaults.stock_mode,import', 'integer',
                    Rule::exists('warehouses', 'id')->where(fn ($query) => $query
                        ->where('organization_id', $productImport->organization_id)->where('status', 'active')),
                ],
                'organization_id' => ['prohibited'],
                'store_id' => ['prohibited'],
            ]);
        } catch (ValidationException $exception) {
            $this->persistGlobalValidationFailure($productImport, $exception, $request);

            throw $exception;
        }
        $indices = array_filter($data['mapping'], fn ($value) => $value !== null);
        if (count($indices) !== count(array_unique($indices))) {
            $productImport->global_error_code = 'invalid_mapping';
            $productImport->global_error_message = 'Une colonne du fichier ne peut être associée qu’à un seul champ ERP.';
            $productImport->save();

            return back()->withErrors(['mapping' => 'Une colonne du fichier ne peut être associée qu’à un seul champ ERP.']);
        }
        try {
            $action->execute($request->user(), $productImport, $data['mapping'], $data['defaults'] ?? []);
        } catch (ValidationException $exception) {
            $this->persistGlobalValidationFailure($productImport, $exception, $request);

            throw $exception;
        }

        return redirect()->route('catalog.product-imports.show', $productImport);
    }

    public function confirm(Request $request, ProductImport $productImport, ConfirmProductImportAction $action): RedirectResponse
    {
        $this->authorize('update', $productImport);
        $request->validate([
            'organization_id' => ['prohibited'],
            'store_id' => ['prohibited'],
            'product_id' => ['prohibited'],
        ]);
        try {
            $action->execute($request->user(), $productImport);
        } catch (ValidationException $exception) {
            $this->persistGlobalValidationFailure($productImport, $exception, $request);

            return redirect()->route('catalog.product-imports.show', $productImport);
        }

        return redirect()->route('catalog.product-imports.show', $productImport);
    }

    private function persistGlobalValidationFailure(ProductImport $import, ValidationException $exception, Request $request): void
    {
        $errors = $exception->errors();
        if (isset($errors['defaults.warehouse_id'])) {
            $provided = filled($request->input('defaults.warehouse_id')) || filled(data_get($import->defaults, 'warehouse_id'));
            $import->global_error_code = $provided ? 'invalid_warehouse' : 'missing_warehouse';
            $import->global_error_message = $provided
                ? 'L’emplacement sélectionné n’est pas disponible pour cette organisation.'
                : 'Sélectionnez un emplacement pour importer le stock.';
        } elseif (collect(array_keys($errors))->contains(fn ($key) => str_starts_with($key, 'mapping'))) {
            $import->global_error_code = 'invalid_mapping';
            $import->global_error_message = 'Corrigez l’association des colonnes avant de prévisualiser l’import.';
        } else {
            $import->global_error_code = 'invalid_import_configuration';
            $import->global_error_message = 'La configuration de l’import est invalide. Corrigez les champs indiqués.';
        }
        $import->save();
    }
}
