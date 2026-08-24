<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\TaxRate;
use App\Models\User;

class TaxRatePolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'tax_rates.view');
    }

    public function view(User $user, TaxRate $taxRate): bool
    {
        return $user->hasPermission($taxRate->organization_id, 'tax_rates.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'tax_rates.manage');
    }

    public function update(User $user, TaxRate $taxRate): bool
    {
        return $user->hasPermission($taxRate->organization_id, 'tax_rates.manage');
    }
}
