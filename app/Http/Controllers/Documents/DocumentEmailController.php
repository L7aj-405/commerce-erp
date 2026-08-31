<?php

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\SendDeliveryNoteEmailAction;
use App\Actions\Documents\SendInvoiceEmailAction;
use App\Http\Controllers\Controller;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class DocumentEmailController extends Controller
{
    public function invoice(Request $request, Invoice $invoice, SendInvoiceEmailAction $action): RedirectResponse
    {
        $this->authorize('email', $invoice);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);

        try {
            $action->execute($request->user(), $invoice, $data['email']);
        } catch (Throwable) {
            return back()->withErrors(['email' => 'The Invoice could not be emailed. Please try again.']);
        }

        return back()->with('success', 'Invoice emailed successfully.');
    }

    public function deliveryNote(Request $request, DeliveryNote $deliveryNote, SendDeliveryNoteEmailAction $action): RedirectResponse
    {
        $this->authorize('email', $deliveryNote);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);

        try {
            $action->execute($request->user(), $deliveryNote, $data['email']);
        } catch (Throwable) {
            return back()->withErrors(['email' => 'The Delivery Note could not be emailed. Please try again.']);
        }

        return back()->with('success', 'Delivery Note emailed successfully.');
    }
}
