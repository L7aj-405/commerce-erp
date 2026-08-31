<?php

namespace App\Policies;

use App\Enums\DeliveryNoteStatus;
use App\Models\DeliveryNote;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\ActiveTenantContext;

class DeliveryNotePolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization, Store $store): bool
    {
        return $this->scope($organization->getKey(), $store->getKey()) && $user->hasPermission($organization, 'delivery_notes.view');
    }

    public function view(User $user, DeliveryNote $note): bool
    {
        return $this->allowed($user, $note->organization_id, $note->store_id, 'delivery_notes.view');
    }

    public function create(User $user, SalesOrder $order): bool
    {
        return $this->allowed($user, $order->organization_id, $order->store_id, 'delivery_notes.create');
    }

    public function updateDraft(User $user, DeliveryNote $note): bool
    {
        return $note->status === DeliveryNoteStatus::Draft && $this->allowed($user, $note->organization_id, $note->store_id, 'delivery_notes.update_draft');
    }

    public function issue(User $user, DeliveryNote $note): bool
    {
        return $this->allowed($user, $note->organization_id, $note->store_id, 'delivery_notes.issue');
    }

    public function email(User $user, DeliveryNote $note): bool
    {
        return $note->status === DeliveryNoteStatus::Issued
            && $this->allowed($user, $note->organization_id, $note->store_id, 'delivery_notes.email');
    }

    private function allowed(User $user, int $organizationId, int $storeId, string $permission): bool
    {
        return $this->scope($organizationId, $storeId) && $user->hasPermission($organizationId, $permission);
    }

    private function scope(int $organizationId, int $storeId): bool
    {
        return $this->context->organization()?->getKey() === $organizationId && $this->context->store()?->getKey() === $storeId;
    }
}
