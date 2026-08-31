<?php

namespace App\Actions\Documents\Concerns;

use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;

trait AuthorizesDocumentAction
{
    private function authorizeDocumentScope(User $actor, Organization $organization, Store $store, string $permission): void
    {
        abort_unless(
            $actor->active_organization_id === $organization->getKey()
            && $actor->active_store_id === $store->getKey()
            && $organization->status === 'active'
            && $store->organization_id === $organization->getKey()
            && $store->status === 'active'
            && $store->memberships()->where('user_id', $actor->getKey())->exists()
            && $actor->hasPermission($organization, $permission),
            403,
        );
    }

    private function authorizeOrderDocument(User $actor, SalesOrder $order, string $permission): void
    {
        $this->authorizeDocumentScope($actor, $order->organization, $order->store, $permission);
    }

    private function authorizeInvoice(User $actor, Invoice $invoice, string $permission): void
    {
        $this->authorizeDocumentScope($actor, $invoice->organization, $invoice->store, $permission);
    }

    private function authorizeDeliveryNote(User $actor, DeliveryNote $note, string $permission): void
    {
        $this->authorizeDocumentScope($actor, $note->organization, $note->store, $permission);
    }

    private function authorizeBusinessDate(User $actor, Organization $organization, string $date, string $permission): void
    {
        abort_unless($date === now()->toDateString() || $actor->hasPermission($organization, $permission), 403);
    }
}
