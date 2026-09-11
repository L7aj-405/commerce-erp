<?php

namespace App\Policies;

use App\Enums\QuotationStatus;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use App\Services\ActiveTenantContext;

class QuotationPolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization, Store $store): bool
    {
        return $this->scope($organization->getKey(), $store->getKey()) && $user->hasPermission($organization, 'quotations.view');
    }

    public function view(User $user, Quotation $quotation): bool
    {
        return $this->allowed($user, $quotation, 'quotations.view');
    }

    public function create(User $user, Organization $organization, Store $store): bool
    {
        return $this->scope($organization->getKey(), $store->getKey()) && $user->hasPermission($organization, 'quotations.create');
    }

    public function update(User $user, Quotation $quotation): bool
    {
        return $quotation->status === QuotationStatus::Draft && $this->allowed($user, $quotation, 'quotations.update');
    }

    public function issue(User $user, Quotation $quotation): bool
    {
        return $quotation->status === QuotationStatus::Draft && $this->allowed($user, $quotation, 'quotations.issue');
    }

    public function decide(User $user, Quotation $quotation): bool
    {
        return $quotation->status->isCurrentEligible()
            && $this->allowed($user, $quotation, 'quotations.accept');
    }

    /**
     * Open a revision of an issued Devis. Requires the "issue" permission — a
     * revision is re-issued into the same commercial proposal. A superseded or
     * converted version can never be revised (the current one must be).
     */
    public function revise(User $user, Quotation $quotation): bool
    {
        return $quotation->status->isCurrentEligible()
            && $this->allowed($user, $quotation, 'quotations.issue');
    }

    public function convert(User $user, Quotation $quotation): bool
    {
        return in_array($quotation->status, [QuotationStatus::Issued, QuotationStatus::Accepted, QuotationStatus::Expired, QuotationStatus::Converted], true)
            && $this->allowed($user, $quotation, 'quotations.convert');
    }

    public function email(User $user, Quotation $quotation): bool
    {
        // A superseded revision keeps a viewable PDF for history, but sharing
        // always targets the current version.
        return $quotation->status->isOfficial()
            && $quotation->status !== QuotationStatus::Superseded
            && $this->allowed($user, $quotation, 'quotations.email');
    }

    public function duplicate(User $user, Quotation $quotation): bool
    {
        return $this->allowed($user, $quotation, 'quotations.create');
    }

    private function allowed(User $user, Quotation $quotation, string $permission): bool
    {
        return $this->scope($quotation->organization_id, $quotation->store_id)
            && $user->hasPermission($quotation->organization_id, $permission);
    }

    private function scope(int $organizationId, int $storeId): bool
    {
        return $this->context->organization()?->getKey() === $organizationId
            && $this->context->store()?->getKey() === $storeId;
    }
}
