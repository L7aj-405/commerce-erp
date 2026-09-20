<?php

namespace App\Policies;

use App\Enums\SalesOrderFulfillmentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\ActiveTenantContext;

class SalesOrderPolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}

    public function viewAny(User $user, Organization $organization, Store $store): bool
    {
        return $this->scope($organization->getKey(), $store->getKey()) && $user->hasPermission($organization, 'sales_orders.view');
    }

    public function view(User $user, SalesOrder $order): bool
    {
        return $this->allowed($user, $order, 'sales_orders.view');
    }

    public function create(User $user, Organization $organization, Store $store): bool
    {
        return $this->scope($organization->getKey(), $store->getKey()) && $user->hasPermission($organization, 'sales_orders.create');
    }

    public function update(User $user, SalesOrder $order): bool
    {
        return $this->allowed($user, $order, 'sales_orders.update');
    }

    public function confirm(User $user, SalesOrder $order): bool
    {
        return $this->allowed($user, $order, 'sales_orders.confirm');
    }

    public function cancel(User $user, SalesOrder $order): bool
    {
        if (! $this->allowed($user, $order, 'sales_orders.cancel')) {
            return false;
        }

        if ($order->status === SalesOrderStatus::Cancelled
            || $order->fulfillment_status !== SalesOrderFulfillmentStatus::Unfulfilled
            || $order->invoices()->whereIn('status', ['issued', 'superseded'])->exists()
            || $order->deliveryNotes()->where('status', 'issued')->exists()
            || $order->transferRequests()->where('status', 'shipped')->exists()
            || $order->procurements()->where('status', 'ordered')->exists()) {
            return false;
        }

        $hasPostedPayments = $order->paymentAllocations()
            ->whereHas('payment', fn ($query) => $query->where('status', 'posted'))
            ->exists();
        $hasDraftInvoices = $order->invoices()->where('status', 'draft')->exists();
        $hasDraftDeliveryNotes = $order->deliveryNotes()->where('status', 'draft')->exists();
        $hasCancellableTransfers = $order->transferRequests()->whereIn('status', ['requested', 'preparing'])->exists();

        return (! $hasPostedPayments || $user->hasPermission($order->organization_id, 'payments.reverse'))
            && (! $hasDraftInvoices || $user->hasPermission($order->organization_id, 'invoices.update_draft'))
            && (! $hasDraftDeliveryNotes || $user->hasPermission($order->organization_id, 'delivery_notes.update_draft'))
            && (! $hasCancellableTransfers || $user->hasPermission($order->organization_id, 'inventory.transfer_requests.manage'));
    }

    public function fulfill(User $user, SalesOrder $order): bool
    {
        return $this->allowed($user, $order, 'sales_orders.fulfill');
    }

    public function correct(User $user, SalesOrder $order): bool
    {
        return $order->status === SalesOrderStatus::Confirmed
            && $order->fulfillment_status === SalesOrderFulfillmentStatus::Unfulfilled
            && $this->scope($order->organization_id, $order->store_id)
            && $user->hasPermission($order->organization_id, 'sales_orders.update')
            && $user->hasPermission($order->organization_id, 'sales_orders.confirm')
            && (! $order->transferRequests()->whereIn('status', ['requested', 'preparing'])->exists()
                || $user->hasPermission($order->organization_id, 'inventory.transfer_requests.manage'));
    }

    public function completePos(User $user, SalesOrder $order): bool
    {
        return $this->scope($order->organization_id, $order->store_id)
            && $user->hasPermission($order->organization_id, 'pos.access')
            && $user->hasPermission($order->organization_id, 'sales_orders.update')
            && $user->hasPermission($order->organization_id, 'sales_orders.fulfill');
    }

    private function allowed(User $user, SalesOrder $order, string $permission): bool
    {
        return $this->scope($order->organization_id, $order->store_id) && $user->hasPermission($order->organization_id, $permission);
    }

    private function scope(int $organizationId, int $storeId): bool
    {
        return $this->context->organization()?->getKey() === $organizationId && $this->context->store()?->getKey() === $storeId;
    }
}
