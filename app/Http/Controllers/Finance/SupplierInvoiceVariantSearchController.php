<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Services\ActiveTenantContext;
use App\Services\Sandbox\SandboxContext;
use App\Services\SupplierInvoices\SupplierInvoiceSettings;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;

/**
 * Variant picker for goods lines of a supplier invoice. Returns identification fields only: never a cost or price.
 */
class SupplierInvoiceVariantSearchController extends Controller implements HasMiddleware
{
    private const LIMIT = 20;

    private const MIN_LENGTH = 2;

    /** Kill switch: the feature answers 404 until enabled (and always in Test Mode). */
    public static function middleware(): array
    {
        return [function (Request $request, Closure $next) {
            abort_if(app(SandboxContext::class)->isSandbox(), 404);
            abort_unless(app(SupplierInvoiceSettings::class)->enabled(), 404);

            return $next($request);
        }];
    }

    public function __construct(private readonly ActiveTenantContext $context) {}

    public function __invoke(Request $request): JsonResponse
    {
        $organization = $this->context->organizationOrFail();
        abort_unless($request->user()->hasPermission($organization, 'supplier_invoices.manage'), 403);

        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < self::MIN_LENGTH) {
            return response()->json(['data' => []]);
        }
        // '!' is the LIKE escape character: works identically on MySQL and SQLite.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($term, 0, 100)).'%';

        $variants = ProductVariant::query()
            ->where('product_variants.organization_id', $organization->getKey())
            ->where('product_variants.status', 'active')
            ->whereHas('product', fn (Builder $product) => $product->where('organization_id', $organization->getKey())->where('status', 'active'))
            ->where(function (Builder $query) use ($like) {
                foreach (['sku', 'reference', 'barcode', 'label'] as $column) {
                    $query->orWhereRaw("product_variants.{$column} LIKE ? ESCAPE '!'", [$like]);
                }
                $query->orWhereHas('product', fn (Builder $product) => $product->whereRaw("products.name LIKE ? ESCAPE '!'", [$like]));
            })
            ->with('product:id,name')
            ->orderBy('product_variants.id')
            ->limit(self::LIMIT)
            ->get(['product_variants.id', 'product_variants.product_id', 'product_variants.sku', 'product_variants.label']);

        return response()->json(['data' => $variants->map(fn (ProductVariant $variant) => [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'label' => $variant->label,
            'product_name' => $variant->product?->name,
        ])->values()->all()]);
    }
}
