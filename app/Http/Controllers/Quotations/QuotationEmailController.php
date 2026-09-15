<?php

namespace App\Http\Controllers\Quotations;

use App\Actions\Quotations\SendQuotationEmailAction;
use App\Exceptions\Mail\OrganizationMailDeliveryException;
use App\Exceptions\Mail\OrganizationMailNotConfiguredException;
use App\Http\Controllers\Controller;
use App\Models\Quotation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class QuotationEmailController extends Controller
{
    public function send(Request $request, Quotation $quotation, SendQuotationEmailAction $action): RedirectResponse
    {
        $this->authorize('email', $quotation);
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $action->execute($request->user(), $quotation, $data['email'], $data['subject'] ?? null, $data['message'] ?? null);
        } catch (OrganizationMailNotConfiguredException $exception) {
            return back()->withErrors(['email' => $exception->getMessage()])->with('mailNotConfigured', true);
        } catch (OrganizationMailDeliveryException $exception) {
            return back()->withErrors(['email' => $exception->getMessage()]);
        } catch (Throwable) {
            return back()->withErrors(['email' => 'Le devis n\'a pas pu être envoyé. Réessayez.']);
        }

        return back()->with('success', 'Devis envoyé par e-mail.');
    }
}
