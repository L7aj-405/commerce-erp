<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Pos\AddItemsToCompletedPosOrderAction;
use App\Enums\CatalogStatus;
use App\Enums\WarehouseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\AddItemsToCompletedPosOrderRequest;
use App\Models\InventoryBalance;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Services\ProductPriceResolver;
use App\Services\Pos\PosOrderCompletionEligibility;
use App\Services\SalesOrderPaymentCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosOrderCompletionController extends Controller
{
    public function create(SalesOrder $order, SalesOrderPaymentCalculator $payments, PosOrderCompletionEligibility $eligibility): Response
    {
        $this->authorize('completePos', $order);
        $eligibility->assertAllowed(request()->user(), $order);
        $order->load(['store:id,name,code', 'lines.addendum:id,sequence', 'addenda.lines', 'addenda.createdBy:id,name']);

        return Inertia::render('Sales/Orders/Complete', [
            'order' => $order,
            'paymentSummary' => $payments->summary($order),
            'searchUrl' => route('sales.orders.completion.search', $order),
            'submitUrl' => route('sales.orders.completion.store', $order),
        ]);
    }

    public function store(
        AddItemsToCompletedPosOrderRequest $request,
        SalesOrder $order,
        AddItemsToCompletedPosOrderAction $action,
    ): RedirectResponse {
        $this->authorize('completePos', $order);
        $data = $request->validated();
        $action->execute($request->user(), $order, $data['client_operation_id'], $data['lines']);

        return redirect()->route('sales.orders.show', $order)->with('success', 'La commande a été complétée.');
    }

    public function search(Request $request, SalesOrder $order, ProductPriceResolver $prices, PosOrderCompletionEligibility $eligibility): JsonResponse
    {
        $this->authorize('completePos', $order);
        $eligibility->assertAllowed($request->user(), $order);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $search = trim((string) ($filters['search'] ?? ''));
        $warehouseId = $order->pos_warehouse_id;

        $variants = ProductVariant::query()
            ->where('organization_id', $order->organization_id)
            ->where('status', CatalogStatus::Active->value)
            ->whereHas('product', fn ($query) => $query->where('status', CatalogStatus::Active->value))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('sku', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($product) => $product->where('name', 'like', "%{$search}%"))))
            ->with(['product:id,name,default_unit_id', 'product.defaultUnit:id,name,symbol', 'taxRate:id,name,rate'])
            ->orderBy('product_id')
            ->orderBy('sku')
            ->limit(15)
            ->get();

        $availability = InventoryBalance::query()
            ->where('organization_id', $order->organization_id)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_variant_id', $variants->pluck('id'))
            ->whereHas('warehouse', fn ($query) => $query->where('status', WarehouseStatus::Active->value))
            ->get()
            ->keyBy('product_variant_id');
        $defaultTax = $prices->defaultTaxRate($order->store, (int) $order->organization_id);

        return response()->json(['data' => $variants->map(function (ProductVariant $variant) use ($prices, $defaultTax, $availability) {
            $price = $prices->resolveWith($variant, $defaultTax);

            return [
                'id' => $variant->getKey(),
                'product_name' => $variant->product->name,
                'variant_name' => $variant->label,
                'sku' => $variant->sku,
                'reference' => $variant->reference,
                'unit_label' => $variant->product->defaultUnit?->symbol ?? $variant->product->defaultUnit?->name,
                'unit_price_incl_tax' => $price['unit_price_ttc'],
                'tax_rate' => $price['tax_rate_value'],
                'config_missing' => $price['config_missing'],
                'local_stock_available' => $availability->has($variant->getKey())
                    ? $availability->get($variant->getKey())->available
                    : '0.0000',
            ];
        })->values()]);
    }
}
