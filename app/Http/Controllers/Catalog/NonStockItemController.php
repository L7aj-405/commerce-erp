<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\CreateNonStockItemAction;
use App\Actions\Catalog\UpdateNonStockItemAction;
use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreNonStockItemRequest;
use App\Http\Requests\Catalog\UpdateNonStockItemRequest;
use App\Models\NonStockItem;
use App\Models\TaxRate;
use App\Services\ActiveTenantContext;
use App\Services\NonStockItemExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;

class NonStockItemController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): \Inertia\Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [NonStockItem::class, $organization]);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $items = NonStockItem::query()
            ->where('organization_id', $organization->getKey())
            ->when($filters['search'] ?? null, fn ($q, string $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")))
            ->when($filters['status'] ?? null, fn ($q, string $s) => $q->where('status', $s))
            ->with('createdBy:id,name')
            ->orderBy('name')
            ->paginate(30)->withQueryString();

        return Inertia::render('Catalog/NonStockItems/Index', [
            'items' => $items,
            'filters' => $filters,
            'taxRates' => TaxRate::query()->where('organization_id', $organization->getKey())
                ->where('status', CatalogStatus::Active->value)->orderBy('name')->get(['id', 'name', 'rate']),
            'can' => ['manage' => $request->user()->can('manage', [NonStockItem::class, $organization])],
        ]);
    }

    public function store(StoreNonStockItemRequest $request, ActiveTenantContext $context, CreateNonStockItemAction $action): JsonResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('manage', [NonStockItem::class, $organization]);

        $item = $action->execute($request->user(), $organization, $request->validated());

        return response()->json(['data' => $this->payload($item)], 201);
    }

    public function update(UpdateNonStockItemRequest $request, NonStockItem $nonStockItem, UpdateNonStockItemAction $action): RedirectResponse
    {
        $this->authorize('update', $nonStockItem);
        $action->execute($request->user(), $nonStockItem, $request->validated());

        return back()->with('success', 'Article hors stock mis à jour.');
    }

    public function export(ActiveTenantContext $context, NonStockItemExporter $exporter): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [NonStockItem::class, $organization]);

        $filename = 'articles-hors-stock-'.now()->toDateString().'.csv';

        return response($exporter->csv($organization), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(NonStockItem $item): array
    {
        return [
            'id' => $item->getKey(),
            'name' => $item->name,
            'reference' => $item->reference,
            'unit_label' => $item->unit_label,
            'price_input_mode' => $item->price_input_mode->value,
            'default_price_excl_tax' => $item->default_price_excl_tax,
            'default_price_incl_tax' => $item->default_price_incl_tax,
            'tax_rate' => $item->tax_rate,
            'tax_name' => $item->tax_name,
            'usage_count' => $item->usage_count,
            'status' => $item->status->value,
        ];
    }
}
