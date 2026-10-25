<?php

namespace App\Services\FollowUps;

use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\ActiveTenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;

/**
 * W1.1 safe cross-store navigation to a store-scoped document, shared by Work
 * Center surfaces (follow-ups, attention items): quotations, (W4.4)
 * invoices and (W4.5) sales orders. The caller has already authorized the
 * item it navigates from. Nothing is switched or revealed unless the user holds the document's view
 * permission and is a member of the document's active store; the switch
 * itself is the canonical ActiveTenantContext::activateStore.
 */
final class StoreDocumentOpener
{
    public function openQuotation(User $user, ActiveTenantContext $context, ?int $quotationId): RedirectResponse
    {
        return $this->open($user, $context, Quotation::class, $quotationId, 'quotations.view', 'quotations.show', 'quotation', 'Ce devis n’est pas accessible depuis votre compte.');
    }

    public function openInvoice(User $user, ActiveTenantContext $context, ?int $invoiceId): RedirectResponse
    {
        return $this->open($user, $context, Invoice::class, $invoiceId, 'invoices.view', 'invoices.show', 'invoice', 'Cette facture n’est pas accessible depuis votre compte.');
    }

    public function openSalesOrder(User $user, ActiveTenantContext $context, ?int $orderId): RedirectResponse
    {
        return $this->open($user, $context, SalesOrder::class, $orderId, 'sales_orders.view', 'sales.orders.show', 'sales_order', 'Cette commande n’est pas accessible depuis votre compte.');
    }

    /** @param class-string<Model> $model */
    private function open(User $user, ActiveTenantContext $context, string $model, ?int $id, string $permission, string $route, string $errorKey, string $error): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $document = $id === null ? null : $model::query()
            ->where('organization_id', $organization->getKey())->whereKey($id)
            ->first(['id', 'organization_id', 'store_id']);
        $store = $document ? Store::query()->where('organization_id', $document->organization_id)->whereKey($document->store_id)->first() : null;

        $allowed = $store !== null
            && $store->status === 'active'
            && $user->hasPermission($organization, $permission)
            && $store->memberships()->where('user_id', $user->getKey())->exists();
        if (! $allowed) {
            return back()->withErrors([$errorKey => $error]);
        }

        if ((int) $context->store()?->getKey() !== (int) $store->getKey()) {
            $context->activateStore($user, $store);
        }

        return redirect()->route($route, $document->getKey());
    }
}
