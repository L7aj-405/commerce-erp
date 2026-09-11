<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\CreateVariantAction;
use App\Actions\Catalog\UpdateVariantAction;
use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductVariantController extends Controller
{
    public function store(Request $request, Product $product, CreateVariantAction $action): RedirectResponse
    {
        $this->authorize('create', [ProductVariant::class, $product]);
        $action->execute($request->user(), $product, $request->validate($this->rules($product->organization_id)));

        return back();
    }

    public function update(Request $request, ProductVariant $variant, UpdateVariantAction $action): RedirectResponse
    {
        $this->authorize('update', $variant);
        $action->execute($request->user(), $variant, $request->validate($this->rules($variant->organization_id, $variant)));

        return back();
    }

    public function archive(Request $request, ProductVariant $variant, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('archive', $variant);
        $oldStatus = $variant->status->value;
        $variant->status = CatalogStatus::Inactive;
        $variant->save();
        $audit->record('product_variant.archived', $request->user(), $variant->organization, auditable: $variant,
            oldValues: ['status' => $oldStatus], newValues: ['status' => CatalogStatus::Inactive->value]);

        return back();
    }

    /** @return array<string, mixed> */
    private function rules(int $organizationId, ?ProductVariant $variant = null): array
    {
        return [
            'label' => ['nullable', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:255', Rule::unique('product_variants', 'sku')->where('organization_id', $organizationId)->ignore($variant)],
            'reference' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:255', Rule::unique('product_variants', 'barcode')->where('organization_id', $organizationId)->ignore($variant)],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'public_price_ttc' => ['nullable', 'numeric', 'min:0', 'decimal:0,4', 'required_without_all:regular_sale_price,default_sale_price'],
            'unit_price_ht' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'regular_sale_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,4', 'required_without_all:default_sale_price,public_price_ttc'],
            'promotional_sale_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,4', 'lte:regular_sale_price'],
            'default_sale_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,4', 'required_without_all:regular_sale_price,public_price_ttc'],
            'tax_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')->where('organization_id', $organizationId)],
            'status' => ['required', Rule::enum(CatalogStatus::class)],
        ];
    }
}
