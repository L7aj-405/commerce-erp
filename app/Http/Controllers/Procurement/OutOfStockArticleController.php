<?php

namespace App\Http\Controllers\Procurement;

use App\Actions\Procurement\ReportOutOfStockArticleAction;
use App\Actions\Procurement\ResolveOutOfStockArticleAction;
use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Models\OutOfStockArticle;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Services\ActiveTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OutOfStockArticleController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [OutOfStockArticle::class, $organization]);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['unresolved', 'resolved', 'all'])],
        ]);
        $status = $filters['status'] ?? 'unresolved';

        $articles = OutOfStockArticle::query()
            ->where('organization_id', $organization->getKey())
            ->with([
                'salesOrder:id,order_number',
                'customer:id,display_name',
                'requestedBy:id,name',
                'resolvedBy:id,name',
                'resolvedProductVariant:id,label,sku,product_id',
                'resolvedProductVariant.product:id,name',
            ])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('description', 'like', "%{$search}%")
                ->orWhereHas('salesOrder', fn ($order) => $order->where('order_number', 'like', "%{$search}%"))
                ->orWhereHas('customer', fn ($customer) => $customer->where('display_name', 'like', "%{$search}%"))))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (OutOfStockArticle $article) => [
                'id' => $article->id,
                'description' => $article->description,
                'requested_quantity' => $article->requested_quantity,
                'sales_order' => $article->salesOrder?->only(['id', 'order_number']),
                'customer' => $article->customer?->display_name,
                'requested_by' => $article->requestedBy?->name,
                'created_at' => $article->created_at?->toIso8601String(),
                'status' => $article->status->value,
                'status_label' => $article->status->label(),
                'resolved_article' => $article->resolvedProductVariant ? trim(
                    ($article->resolvedProductVariant->product?->name ?? '').' '.($article->resolvedProductVariant->label ?? '').' ('.$article->resolvedProductVariant->sku.')'
                ) : null,
                'resolved_by' => $article->resolvedBy?->name,
                'resolved_at' => $article->resolved_at?->toIso8601String(),
            ]);

        $unresolvedCount = OutOfStockArticle::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', 'unresolved')
            ->count();

        return Inertia::render('Procurement/OutOfStockArticles/Index', [
            'articles' => $articles,
            'filters' => ['search' => $filters['search'] ?? null, 'status' => $status],
            'unresolvedCount' => $unresolvedCount,
            'can' => [
                'manage' => $request->user()->hasPermission($organization, 'procurement.manage'),
            ],
        ]);
    }

    public function store(Request $request, SalesOrder $order, ReportOutOfStockArticleAction $action): RedirectResponse|JsonResponse
    {
        $this->authorize('view', $order);

        $data = $request->validate([
            'sales_order_line_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $line = SalesOrderLine::query()->where('organization_id', $order->organization_id)
            ->where('sales_order_id', $order->getKey())->whereKey($data['sales_order_line_id'])->firstOrFail();

        $article = $action->execute($request->user(), $order, $line, $data['notes'] ?? null);

        return $request->expectsJson()
            ? response()->json(['data' => ['id' => $article->id, 'status' => $article->status->value]], 201)
            : back()->with('success', 'Article signalé pour ajout au catalogue.');
    }

    public function variantSearch(Request $request, OutOfStockArticle $article): JsonResponse
    {
        $this->authorize('view', $article);

        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $search = trim((string) ($filters['search'] ?? ''));

        $variants = ProductVariant::query()
            ->where('organization_id', $article->organization_id)
            ->where('status', CatalogStatus::Active->value)
            ->whereHas('product', fn ($query) => $query->where('status', CatalogStatus::Active->value))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('sku', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")
                ->orWhere('label', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($product) => $product->where('name', 'like', "%{$search}%"))))
            ->with('product:id,name')
            ->orderBy('product_id')->orderBy('sku')
            ->limit(15)
            ->get(['id', 'product_id', 'label', 'sku', 'reference'])
            ->map(fn (ProductVariant $variant) => [
                'id' => $variant->id,
                'product_name' => $variant->product->name,
                'variant_name' => $variant->label,
                'sku' => $variant->sku,
                'reference' => $variant->reference,
            ]);

        return response()->json(['data' => $variants]);
    }

    public function resolve(Request $request, OutOfStockArticle $article, ResolveOutOfStockArticleAction $action): RedirectResponse
    {
        $this->authorize('manage', $article);

        $data = $request->validate(['product_variant_id' => ['required', 'integer']]);
        $variant = ProductVariant::query()->where('organization_id', $article->organization_id)
            ->whereKey($data['product_variant_id'])->firstOrFail();

        $action->execute($request->user(), $article, $variant);

        return back()->with('success', 'Article associé au catalogue.');
    }
}
