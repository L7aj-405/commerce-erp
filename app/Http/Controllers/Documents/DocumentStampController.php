<?php

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\StampInvoiceAction;
use App\Actions\Quotations\StampQuotationAction;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Quotation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DocumentStampController extends Controller
{
    public function invoice(Request $request, Invoice $invoice, StampInvoiceAction $action): RedirectResponse
    {
        $this->authorize('stamp', $invoice);

        try {
            $action->execute($request->user(), $invoice);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('success', 'Cachet apposé sur la facture.');
    }

    public function quotation(Request $request, Quotation $quotation, StampQuotationAction $action): RedirectResponse
    {
        $this->authorize('stamp', $quotation);

        try {
            $action->execute($request->user(), $quotation);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('success', 'Cachet apposé sur le devis.');
    }
}
