<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\ActiveTenantContext;
use App\Services\Sandbox\SandboxDocumentRules;

class InvoicePolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization, Store $store): bool
    {
        return $this->scope($organization->getKey(), $store->getKey()) && $user->hasPermission($organization, 'invoices.view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice->organization_id, $invoice->store_id, 'invoices.view', $invoice->sandbox_id);
    }

    public function create(User $user, SalesOrder $order): bool
    {
        return $this->allowed($user, $order->organization_id, $order->store_id, 'invoices.create', $order->sandbox_id);
    }

    public function updateDraft(User $user, Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Draft && $this->allowed($user, $invoice->organization_id, $invoice->store_id, 'invoices.update_draft', $invoice->sandbox_id);
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $this->allowed($user, $invoice->organization_id, $invoice->store_id, 'invoices.issue', $invoice->sandbox_id);
    }

    public function correct(User $user, Invoice $invoice): bool
    {
        return false;
    }

    /** Invoice lines are snapshots of the authoritative SalesOrder revision. */
    public function editLines(User $user, Invoice $invoice): bool
    {
        return false;
    }

    public function email(User $user, Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Issued
            && $this->allowed($user, $invoice->organization_id, $invoice->store_id, 'invoices.email', $invoice->sandbox_id);
    }

    public function stamp(User $user, Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Issued
            && $this->allowed($user, $invoice->organization_id, $invoice->store_id, 'documents.stamp', $invoice->sandbox_id);
    }

    private function allowed(User $user, int $organizationId, int $storeId, string $permission, ?int $sandboxId): bool
    {
        if (! SandboxDocumentRules::allows($sandboxId, $permission)) {
            return false;
        }

        return $this->scope($organizationId, $storeId) && $user->hasPermission($organizationId, $permission);
    }

    private function scope(int $organizationId, int $storeId): bool
    {
        return $this->context->organization()?->getKey() === $organizationId && $this->context->store()?->getKey() === $storeId;
    }
}
