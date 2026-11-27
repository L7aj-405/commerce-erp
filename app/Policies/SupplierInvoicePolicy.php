<?php

namespace App\Policies;

use App\Enums\SupplierInvoiceStatus;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\ActiveTenantContext;
use App\Services\SupplierInvoices\SupplierInvoiceStoreAccess;

/**
 * Supplier invoices: organization (active tenant) + explicit permission + explicit store membership
 * (SupplierInvoiceStoreAccess, no Owner/Admin bypass). The kill switch and Test Mode are handled by the
 * route layer and the lifecycle actions.
 */
class SupplierInvoicePolicy
{
    public function __construct(
        private readonly ActiveTenantContext $context,
        private readonly SupplierInvoiceStoreAccess $stores,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->permitted($user, 'supplier_invoices.view');
    }

    /** Creating requires the manage permission; the target store is checked by the action. */
    public function create(User $user): bool
    {
        return $this->permitted($user, 'supplier_invoices.manage');
    }

    public function view(User $user, SupplierInvoice $invoice): bool
    {
        return $this->sameOrganization($invoice)
            && $user->hasPermission($invoice->organization_id, 'supplier_invoices.view')
            && $this->stores->canView($user, $invoice->store);
    }

    public function update(User $user, SupplierInvoice $invoice): bool
    {
        return $this->sameOrganization($invoice)
            && $invoice->status === SupplierInvoiceStatus::Draft
            && $user->hasPermission($invoice->organization_id, 'supplier_invoices.manage')
            && $this->stores->canEdit($user, $invoice->store);
    }

    public function approve(User $user, SupplierInvoice $invoice): bool
    {
        return $this->sameOrganization($invoice)
            && $invoice->status === SupplierInvoiceStatus::Draft
            && $user->hasPermission($invoice->organization_id, 'supplier_invoices.approve')
            && $this->stores->canApprove($user, $invoice->store);
    }

    public function void(User $user, SupplierInvoice $invoice): bool
    {
        return $this->sameOrganization($invoice)
            && $invoice->status !== SupplierInvoiceStatus::Void
            && $user->hasPermission($invoice->organization_id, 'supplier_invoices.void')
            && $this->stores->canVoid($user, $invoice->store);
    }

    private function permitted(User $user, string $permission): bool
    {
        $organization = $this->context->organization();

        return $organization !== null && $user->hasPermission($organization->getKey(), $permission);
    }

    private function sameOrganization(SupplierInvoice $invoice): bool
    {
        return $this->context->organization()?->getKey() === $invoice->organization_id;
    }
}
