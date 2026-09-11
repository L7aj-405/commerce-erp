<?php

namespace App\Http\Controllers\Quotations;

use App\Actions\Quotations\RemoveQuotationLineAction;
use App\Actions\Quotations\SaveQuotationLineAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quotations\SaveQuotationLineRequest;
use App\Models\Quotation;
use App\Models\QuotationLine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class QuotationLineController extends Controller
{
    public function store(SaveQuotationLineRequest $request, Quotation $quotation, SaveQuotationLineAction $action): RedirectResponse
    {
        $this->authorize('update', $quotation);
        $action->execute($request->user(), $quotation, $request->validated());

        return back();
    }

    public function update(SaveQuotationLineRequest $request, Quotation $quotation, QuotationLine $line, SaveQuotationLineAction $action): RedirectResponse
    {
        $this->authorize('update', $quotation);
        abort_unless($this->belongs($line, $quotation), 404);
        $action->execute($request->user(), $quotation, $request->validated(), $line);

        return back();
    }

    public function destroy(Request $request, Quotation $quotation, QuotationLine $line, RemoveQuotationLineAction $action): RedirectResponse
    {
        $this->authorize('update', $quotation);
        abort_unless($this->belongs($line, $quotation), 404);
        $action->execute($request->user(), $quotation, $line);

        return back();
    }

    /**
     * The `{quotation}` binding is tenant/store scoped; this rejects a `{line}`
     * that belongs to a different Devis (or organisation) — a line from Devis A
     * can never be mutated through Devis B.
     */
    private function belongs(QuotationLine $line, Quotation $quotation): bool
    {
        return (int) $line->quotation_id === (int) $quotation->getKey()
            && (int) $line->organization_id === (int) $quotation->organization_id;
    }
}
