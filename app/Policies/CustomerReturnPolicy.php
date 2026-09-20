<?php

namespace App\Policies;

use App\Models\CustomerReturn;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\ActiveTenantContext;

class CustomerReturnPolicy
{
    public function __construct(private readonly ActiveTenantContext $context) {}
    public function viewAny(User $user, Organization $organization, Store $store): bool { return $this->scope($organization->id, $store->id) && $user->hasPermission($organization, 'sales_returns.view'); }
    public function view(User $user, CustomerReturn $return): bool { return $this->allowed($user, $return, 'sales_returns.view'); }
    public function receive(User $user, CustomerReturn $return): bool { return $return->status === 'draft' && $this->allowed($user, $return, 'sales_returns.receive'); }
    public function cancel(User $user, CustomerReturn $return): bool { return $return->status === 'draft' && $this->allowed($user, $return, 'sales_returns.cancel'); }
    public function refund(User $user, CustomerReturn $return): bool { return $return->status === 'received' && $this->allowed($user, $return, 'payments.reverse'); }
    private function allowed(User $user, CustomerReturn $return, string $permission): bool { return $this->scope($return->organization_id, $return->store_id) && $user->hasPermission($return->organization_id, $permission); }
    private function scope(int $organizationId, int $storeId): bool { return $this->context->organization()?->id === $organizationId && $this->context->store()?->id === $storeId; }
}
