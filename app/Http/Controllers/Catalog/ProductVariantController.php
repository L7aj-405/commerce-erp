<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\CreateVariantAction;
use App\Actions\Catalog\UpdateVariantAction;
use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AuditLogger;
use App\Services\Catalog\CatalogProductDataValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductVariantController extends Controller
{
    public function store(Request $request, Product $product, CreateVariantAction $action, CatalogProductDataValidator $validator): RedirectResponse
    {
        $this->authorize('create', [ProductVariant::class, $product]);
        $canManageCost = $request->user()->hasPermission($product->organization_id, 'product_cost.manage');
        $action->execute($request->user(), $product, $validator->variant($request->all(), $product->organization_id, null, $canManageCost));

        return back();
    }

    public function update(Request $request, ProductVariant $variant, UpdateVariantAction $action, CatalogProductDataValidator $validator): RedirectResponse
    {
        $this->authorize('update', $variant);
        $canManageCost = $request->user()->hasPermission($variant->organization_id, 'product_cost.manage');
        $data = $validator->variant($request->all(), $variant->organization_id, $variant, $canManageCost);
        if (! $canManageCost) {
            $data['purchase_price'] = $variant->purchase_price;
        }
        $action->execute($request->user(), $variant, $data);

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
}
