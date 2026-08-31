<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\ProductImport;
use App\Models\User;

class ProductImportPolicy
{
    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'products.import');
    }

    public function view(User $user, ProductImport $import): bool
    {
        return $user->hasPermission($import->organization_id, 'products.import');
    }

    public function update(User $user, ProductImport $import): bool
    {
        return $this->view($user, $import) && in_array($import->status, ['uploaded', 'previewed'], true);
    }
}
