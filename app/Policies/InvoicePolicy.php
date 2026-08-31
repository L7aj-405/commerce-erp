<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\ActiveTenantContext;

class InvoicePolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization, Store $store): bool
    {
        return $this->scope($organization->getKey(), $store->getKey()) && $user->hasPermission($organization, 'invoices.view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice->organization_id, $invoice->store_id, 'invoices.view');
    }

    public function create(User $user, SalesOrder $order): bool
    {
        return $this->allowed($user, $order->organization_id, $order->store_id, 'invoices.create');
    }

    public function updateDraft(User $user, Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Draft && $this->allowed($user, $invoice->organization_id, $invoice->store_id, 'invoices.update_draft');
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice->organization_id, $invoice->store_id, 'invoices.issue');
    }

    public function email(User $user, Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Issued
            && $this->allowed($user, $invoice->organization_id, $invoice->store_id, 'invoices.email');
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
