<?php

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\SendDeliveryNoteEmailAction;
use App\Actions\Documents\SendInvoiceEmailAction;
use App\Exceptions\Mail\OrganizationMailDeliveryException;
use App\Exceptions\Mail\OrganizationMailNotConfiguredException;
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
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $action->execute($request->user(), $invoice, $data['email'], $data['subject'] ?? null, $data['message'] ?? null);
        } catch (OrganizationMailNotConfiguredException $exception) {
            return back()->withErrors(['email' => $exception->getMessage()])->with('mailNotConfigured', true);
        } catch (OrganizationMailDeliveryException $exception) {
            return back()->withErrors(['email' => $exception->getMessage()]);
        } catch (Throwable) {
            return back()->withErrors(['email' => 'La facture n\'a pas pu être envoyée. Réessayez.']);
        }

        return back()->with('success', 'Facture envoyée par e-mail.');
    }

    public function deliveryNote(Request $request, DeliveryNote $deliveryNote, SendDeliveryNoteEmailAction $action): RedirectResponse
    {
        $this->authorize('email', $deliveryNote);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);

        try {
            $action->execute($request->user(), $deliveryNote, $data['email']);
        } catch (OrganizationMailNotConfiguredException $exception) {
            return back()->withErrors(['email' => $exception->getMessage()])->with('mailNotConfigured', true);
        } catch (OrganizationMailDeliveryException $exception) {
            return back()->withErrors(['email' => $exception->getMessage()]);
        } catch (Throwable) {
            return back()->withErrors(['email' => 'Le bon de livraison n\'a pas pu être envoyé. Réessayez.']);
        }

        return back()->with('success', 'Bon de livraison envoyé par e-mail.');
    }
}
