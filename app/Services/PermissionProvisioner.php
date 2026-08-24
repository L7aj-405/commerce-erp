<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Collection;

class PermissionProvisioner
{
    /** @return Collection<string, Permission> */
    public function provisionCatalog(): Collection
    {
        return collect(config('platform.permissions'))->map(
            fn (string $name, string $key) => Permission::query()->updateOrCreate(['key' => $key], ['name' => $name]),
        );
    }

    public function provisionOrganization(Organization $organization): void
    {
        $permissions = $this->provisionCatalog();

        foreach (config('platform.default_roles') as $slug => $definition) {
            $role = Role::query()
                ->where('organization_id', $organization->getKey())
                ->where('slug', $slug)
                ->first();

            if (! $role) {
                continue;
            }

            $keys = $definition['permissions'] === '*' ? $permissions->keys()->all() : $definition['permissions'];
            $role->permissions()->syncWithoutDetaching(
                $permissions->whereIn('key', $keys)->mapWithKeys(
                    fn (Permission $permission) => [$permission->getKey() => ['organization_id' => $organization->getKey()]],
                )->all(),
            );
        }
    }

    public function provisionAllOrganizations(): void
    {
        $this->provisionCatalog();
        Organization::query()->eachById(fn (Organization $organization) => $this->provisionOrganization($organization));
    }
}
