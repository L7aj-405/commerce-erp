<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Services\ActiveTenantContext;
use App\Services\FollowUps\StoreDocumentOpener;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Open a store document from a cross-store surface (customer profile, activity
 * timeline) through the canonical safe store switch: StoreDocumentOpener
 * re-checks the view permission and active store membership, then redirects
 * to the normal store-scoped page. Nothing is bypassed or revealed otherwise.
 */
class DocumentOpenController extends Controller
{
    public function __invoke(Request $request, ActiveTenantContext $context, string $type, int $id, StoreDocumentOpener $opener): RedirectResponse
    {
        return match ($type) {
            'quotation' => $opener->openQuotation($request->user(), $context, $id),
            'sales_order' => $opener->openSalesOrder($request->user(), $context, $id),
            'invoice' => $opener->openInvoice($request->user(), $context, $id),
            'delivery_note' => $opener->openDeliveryNote($request->user(), $context, $id),
            'payment' => $opener->openPayment($request->user(), $context, $id),
            default => abort(404),
        };
    }
}
