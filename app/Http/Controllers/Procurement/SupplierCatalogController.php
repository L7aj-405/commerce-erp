<?php

namespace App\Http\Controllers\Procurement;

use App\Actions\Procurement\SaveProductVariantSupplierAction;
use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\ProductVariantSupplier;
use App\Models\Supplier;
use App\Services\ActiveTenantContext;
use App\Services\Procurement\SupplierCatalogQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Supplier catalog relations (Phase B1) from the product and supplier pages.
 * Every write goes through SaveProductVariantSupplierAction, which owns
 * authorization (supplier_catalog.manage), tenancy and the business rules.
 */
class SupplierCatalogController extends Controller
{
    public function store(Request $request, ActiveTenantContext $context, SaveProductVariantSupplierAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $action->execute($request->user(), $organization, $this->input($request, ['supplier_id', 'product_variant_id']));

        return back()->with('success', 'Fournisseur lié à la variante.');
    }

    public function update(Request $request, ActiveTenantContext $context, ProductVariantSupplier $productVariantSupplier, SaveProductVariantSupplierAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        abort_unless((int) $productVariantSupplier->organization_id === (int) $organization->getKey(), 404);
        $action->execute($request->user(), $organization, $this->input($request), $productVariantSupplier);

        return back()->with('success', 'Relation fournisseur mise à jour.');
    }

    /** Active suppliers for the "add supplier" picker (bounded autocomplete). */
    public function supplierOptions(Request $request, ActiveTenantContext $context): JsonResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [Supplier::class, $organization]);
        $search = trim((string) $request->validate(['search' => ['nullable', 'string', 'max:255']])['search'] ?? '');

        return response()->json(['data' => Supplier::query()->where('organization_id', $organization->getKey())->where('active', true)
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')->limit(20)->get(['id', 'name'])]);
    }

    /** Variants for the "add product" picker on the supplier page (bounded autocomplete). */
    public function variantOptions(Request $request, ActiveTenantContext $context, SupplierCatalogQuery $catalog): JsonResponse
    {
        $organization = $context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'products.view'), 403);
        $search = trim((string) $request->validate(['search' => ['nullable', 'string', 'max:255']])['search'] ?? '');

        $variants = ProductVariant::query()->where('organization_id', $organization->getKey())
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('sku', 'like', "%{$search}%")->orWhere('reference', 'like', "%{$search}%")->orWhere('barcode', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($product) => $product->where('name', 'like', "%{$search}%"))))
            ->with('product:id,name')->orderBy('sku')->limit(20)->get(['id', 'product_id', 'sku', 'label', 'reference', 'status']);

        $preferred = $catalog->preferredByVariant((int) $organization->getKey(), $variants->pluck('id')->map(fn ($id) => (int) $id)->all());

        return response()->json(['data' => $variants->map(fn (ProductVariant $variant) => [
            'id' => $variant->getKey(), 'sku' => $variant->sku, 'label' => $variant->label, 'reference' => $variant->reference,
            'status' => $variant->status->value, 'product_name' => $variant->product?->name,
            'preferred' => $preferred[$variant->getKey()] ?? null,
        ])]);
    }

    /**
     * Only the fields the request carries: an omitted field keeps its value.
     *
     * @param list<string> $identity @return array<string, mixed>
     */
    private function input(Request $request, array $identity = []): array
    {
        $data = $request->only([...$identity, ...SaveProductVariantSupplierAction::FIELDS]);
        if (array_key_exists('is_preferred', $data)) $data['is_preferred'] = filter_var($data['is_preferred'], FILTER_VALIDATE_BOOLEAN);

        return $data;
    }
}
