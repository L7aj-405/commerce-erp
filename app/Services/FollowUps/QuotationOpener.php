<?php

namespace App\Services\FollowUps;

use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use App\Services\ActiveTenantContext;
use Illuminate\Http\RedirectResponse;

/**
 * W1.1 safe cross-store quotation navigation, shared by Work Center surfaces
 * (follow-ups, W4 attention items). The caller has already authorized the
 * item it navigates from. Nothing is switched or revealed unless the user
 * holds quotations.view and is a member of the quotation's active store; the
 * switch itself is the canonical ActiveTenantContext::activateStore.
 */
final class QuotationOpener
{
    public function open(User $user, ActiveTenantContext $context, ?int $quotationId): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $quotation = $quotationId === null ? null : Quotation::query()
            ->where('organization_id', $organization->getKey())->whereKey($quotationId)
            ->first(['id', 'organization_id', 'store_id']);
        $store = $quotation ? Store::query()->where('organization_id', $quotation->organization_id)->whereKey($quotation->store_id)->first() : null;

        $allowed = $store !== null
            && $store->status === 'active'
            && $user->hasPermission($organization, 'quotations.view')
            && $store->memberships()->where('user_id', $user->getKey())->exists();
        if (! $allowed) {
            return back()->withErrors(['quotation' => 'Ce devis n’est pas accessible depuis votre compte.']);
        }

        if ((int) $context->store()?->getKey() !== (int) $store->getKey()) {
            $context->activateStore($user, $store);
        }

        return redirect()->route('quotations.show', $quotation->getKey());
    }
}
