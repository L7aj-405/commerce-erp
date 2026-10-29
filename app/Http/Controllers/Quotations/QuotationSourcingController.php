<?php

namespace App\Http\Controllers\Quotations;

use App\Actions\Quotations\CancelQuotationSupplierRequestAction;
use App\Actions\Quotations\CreateQuotationSupplierRequestAction;
use App\Actions\Quotations\RecordQuotationSupplierAvailabilityAction;
use App\Http\Controllers\Controller;
use App\Models\ProductSupplier;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\Supplier;
use App\Services\Procurement\SupplierCatalogQuery;
use App\Services\Procurement\SupplierRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Internal supplier availability tracking for draft Devis lines. Transport only:
 * validation + routing to the canonical actions, which own authorization.
 */
class QuotationSourcingController extends Controller
{
    /** Known suppliers of the line's Product first, then other active suppliers matching the search. */
    public function supplierOptions(Request $request, Quotation $quotation, QuotationLine $line): JsonResponse
    {
        $this->authorize('update', $quotation);
        abort_unless($this->lineBelongs($line, $quotation), 404);
        abort_unless($request->user()->hasPermission($quotation->organization, 'suppliers.view'), 403);
        $search = trim((string) ($request->validate(['search' => ['nullable', 'string', 'max:255']])['search'] ?? ''));

        $productId = $line->product_variant_id ? $line->productVariant()->value('product_id') : null;
        $knownIds = $productId
            ? ProductSupplier::query()->where('organization_id', $quotation->organization_id)->where('product_id', $productId)->limit(50)->pluck('supplier_id')
            : collect();

        $known = Supplier::query()
            ->where('organization_id', $quotation->organization_id)->where('active', true)
            ->whereIn('id', $knownIds)->orderBy('name')->get(['id', 'name', 'phone']);

        $others = Supplier::query()
            ->where('organization_id', $quotation->organization_id)->where('active', true)
            ->whereNotIn('id', $known->pluck('id'))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'phone']);

        return response()->json(['data' => [
            ...$known->map(fn (Supplier $s) => [...$s->only(['id', 'name', 'phone']), 'known' => true]),
            ...$others->map(fn (Supplier $s) => [...$s->only(['id', 'name', 'phone']), 'known' => false]),
        ]]);
    }

    /**
     * Advisory ranked suppliers for one line (B2). Read-only: opening it never
     * creates a request; the user still picks the supplier to ask.
     */
    public function recommendations(Request $request, Quotation $quotation, QuotationLine $line, SupplierRecommendationService $recommendations): JsonResponse
    {
        $this->authorize('view', $quotation);
        abort_unless($this->lineBelongs($line, $quotation), 404);
        $user = $request->user();
        abort_unless($user->hasPermission($quotation->organization, 'suppliers.view') && $user->hasPermission($quotation->organization, 'products.view'), 403);

        $result = $recommendations->forQuotation($quotation, SupplierCatalogQuery::canViewPrices($user, (int) $quotation->organization_id), (int) $line->getKey());

        return response()->json(['data' => $result[$line->getKey()]]);
    }

    public function store(Request $request, Quotation $quotation, QuotationLine $line, CreateQuotationSupplierRequestAction $action): RedirectResponse
    {
        $this->authorize('update', $quotation);
        abort_unless($this->lineBelongs($line, $quotation), 404);
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'requested_quantity' => ['nullable', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $action->execute($request->user(), $quotation, $line, $data);

        return back();
    }

    public function record(Request $request, Quotation $quotation, QuotationSupplierAvailabilityRequest $supplierRequest, RecordQuotationSupplierAvailabilityAction $action): RedirectResponse
    {
        $this->authorize('view', $quotation);
        abort_unless($this->requestBelongs($supplierRequest, $quotation), 404);
        $data = $request->validate([
            'outcome' => ['required', 'in:available,unavailable'],
            'confirmed_quantity' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $action->execute($request->user(), $quotation, $supplierRequest, $data);

        return back();
    }

    public function cancel(Request $request, Quotation $quotation, QuotationSupplierAvailabilityRequest $supplierRequest, CancelQuotationSupplierRequestAction $action): RedirectResponse
    {
        $this->authorize('view', $quotation);
        abort_unless($this->requestBelongs($supplierRequest, $quotation), 404);

        $action->execute($request->user(), $quotation, $supplierRequest);

        return back();
    }

    private function lineBelongs(QuotationLine $line, Quotation $quotation): bool
    {
        return (int) $line->quotation_id === (int) $quotation->getKey()
            && (int) $line->organization_id === (int) $quotation->organization_id;
    }

    private function requestBelongs(QuotationSupplierAvailabilityRequest $request, Quotation $quotation): bool
    {
        return (int) $request->quotation_id === (int) $quotation->getKey()
            && (int) $request->organization_id === (int) $quotation->organization_id;
    }
}
