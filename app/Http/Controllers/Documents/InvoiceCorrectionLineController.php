<?php

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\AddInvoiceCorrectionLineAction;
use App\Actions\Documents\RemoveInvoiceCorrectionLineAction;
use App\Actions\Documents\UpdateInvoiceCorrectionLineAction;
use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreInvoiceCorrectionLineRequest;
use App\Http\Requests\Documents\UpdateInvoiceCorrectionLineRequest;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\ProductVariant;
use App\Services\ProductPriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Legacy HTTP surface retained as a fail-closed 403 compatibility boundary. */
class InvoiceCorrectionLineController extends Controller
{
    /** Dynamic server-side catalogue search for the line editor's Product picker. */
    public function search(Request $request, Invoice $invoice, ProductPriceResolver $priceResolver): JsonResponse
    {
        $this->authorize('editLines', $invoice);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $variants = ProductVariant::query()
            ->where('organization_id', $invoice->organization_id)
            ->where('status', CatalogStatus::Active->value)
            ->whereHas('product', fn ($query) => $query->where('status', CatalogStatus::Active->value))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('sku', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")
                ->orWhere('label', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($product) => $product
                    ->where('name', 'like', "%{$search}%")
                    ->orWhereHas('brand', fn ($brand) => $brand->where('name', 'like', "%{$search}%")))))
            ->with([
                'product:id,name,brand_id,default_unit_id',
                'product.brand:id,name',
                'product.defaultUnit:id,name,symbol',
                'taxRate:id,name,rate',
            ])
            ->orderBy('product_id')
            ->orderByRaw('CASE WHEN sku IS NULL OR sku = ? THEN 1 ELSE 0 END', [''])
            ->orderBy('sku')
            ->paginate(20, ['id', 'organization_id', 'product_id', 'label', 'sku', 'reference', 'barcode', 'default_sale_price', 'public_price_ttc', 'unit_price_ht', 'tax_rate_id'])
            ->withQueryString();

        $defaultTaxRate = $priceResolver->defaultTaxRate($invoice->store, (int) $invoice->organization_id);

        $data = $variants->getCollection()->map(function (ProductVariant $variant) use ($priceResolver, $defaultTaxRate) {
            $price = $priceResolver->resolveWith($variant, $defaultTaxRate);

            return [
                'id' => $variant->getKey(),
                'product_name' => $variant->product->name,
                'variant_name' => $variant->label,
                'sku' => $variant->sku,
                'reference' => $variant->reference,
                'barcode' => $variant->barcode,
                'unit_label' => $variant->product->defaultUnit?->symbol ?? $variant->product->defaultUnit?->name,
                'brand' => $variant->product->brand?->only(['id', 'name']),
                'unit_price_excl_tax' => $price['unit_price_ht'] ?? '0.0000',
                'tax_rate' => $price['tax_rate_value'],
                'tax_name' => $price['tax_name'],
                'tax_config_missing' => $price['config_missing'],
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $variants->currentPage(),
                'has_more' => $variants->hasMorePages(),
                'next_page' => $variants->hasMorePages() ? $variants->currentPage() + 1 : null,
            ],
        ]);
    }

    public function store(StoreInvoiceCorrectionLineRequest $request, Invoice $invoice, AddInvoiceCorrectionLineAction $action): RedirectResponse
    {
        $this->authorize('editLines', $invoice);
        $action->execute($request->user(), $invoice, $request->validated());

        return back();
    }

    public function update(UpdateInvoiceCorrectionLineRequest $request, Invoice $invoice, InvoiceLine $line, UpdateInvoiceCorrectionLineAction $action): RedirectResponse
    {
        $this->authorize('editLines', $invoice);
        abort_unless($this->lineBelongsToInvoice($line, $invoice), 404);
        $action->execute($request->user(), $invoice, $line, $request->validated());

        return back();
    }

    public function destroy(Request $request, Invoice $invoice, InvoiceLine $line, RemoveInvoiceCorrectionLineAction $action): RedirectResponse
    {
        $this->authorize('editLines', $invoice);
        abort_unless($this->lineBelongsToInvoice($line, $invoice), 404);
        $action->execute($request->user(), $invoice, $line);

        return back();
    }

    /**
     * The `{invoice}` binding is already tenant/store scoped; this rejects a
     * `{line}` that belongs to a different Invoice (or organization) so a line
     * from Invoice A can never be mutated through Invoice B.
     */
    private function lineBelongsToInvoice(InvoiceLine $line, Invoice $invoice): bool
    {
        return (int) $line->invoice_id === (int) $invoice->getKey()
            && (int) $line->organization_id === (int) $invoice->organization_id;
    }
}
