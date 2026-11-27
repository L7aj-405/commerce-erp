<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PermissionProvisioner
{
    /** @return Collection<string, Permission> */
    public function provisionCatalog(): Collection
    {
        return collect(config('platform.permissions'))->map(
            fn (string $name, string $key) => Permission::query()->updateOrCreate(['key' => $key], ['name' => $name]),
        );
    }

    /**
     * Upserts the catalogue row(s) for the given keys and FAILS LOUDLY when config does not declare one of them
     * (e.g. a stale cached config at migration time). A silent no-op would let `migrate` record the migration as
     * applied while the permission is missing from the Role Editor forever.
     *
     * @param  list<string>  $keys
     */
    public function provisionKeysOrFail(array $keys): void
    {
        $catalog = $this->provisionCatalog();
        foreach ($keys as $key) {
            if (! $catalog->has($key) || ! DB::table('permissions')->where('key', $key)->exists()) {
                throw new RuntimeException("Permission [{$key}] is not declared in config('platform.permissions') (stale config cache?). Run `php artisan config:clear` and migrate again.");
            }
        }
    }

    /**
     * Equivalence grant: every role holding `$holderKey` also receives `$newKey` (same organization). Roles that do not
     * hold `$holderKey` are never touched. Idempotent.
     */
    public function grantToHoldersOf(string $holderKey, string $newKey): void
    {
        $holderId = DB::table('permissions')->where('key', $holderKey)->value('id');
        $newId = DB::table('permissions')->where('key', $newKey)->value('id');
        if ($holderId === null || $newId === null) {
            throw new RuntimeException("Cannot grant [{$newKey}] to holders of [{$holderKey}]: a permission row is missing.");
        }

        DB::table('role_permission')->where('permission_id', $holderId)->orderBy('role_id')->each(function ($grant) use ($newId) {
            $exists = DB::table('role_permission')->where('role_id', $grant->role_id)->where('permission_id', $newId)->exists();
            if (! $exists) {
                DB::table('role_permission')->insert([
                    'organization_id' => $grant->organization_id,
                    'role_id' => $grant->role_id,
                    'permission_id' => $newId,
                ]);
            }
        });
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
