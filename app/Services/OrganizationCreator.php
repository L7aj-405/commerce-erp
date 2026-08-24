<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrganizationCreator
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionProvisioner $permissions,
    ) {}

    /** @param array<string, mixed> $settings */
    public function create(User $owner, string $name, array $settings = []): Organization
    {
        return DB::transaction(function () use ($owner, $name, $settings) {
            $organization = new Organization;
            $organization->owner_id = $owner->getKey();
            $organization->name = $name;
            $organization->status = 'active';
            $organization->settings = $settings;
            $organization->save();

            $roles = collect(config('platform.default_roles'))->map(function (array $definition, string $slug) use ($organization) {
                $role = new Role;
                $role->organization_id = $organization->getKey();
                $role->name = $definition['name'];
                $role->slug = $slug;
                $role->is_system = true;
                $role->save();

                return $role;
            });

            $this->permissions->provisionOrganization($organization);

            $membership = new OrganizationMembership;
            $membership->organization_id = $organization->getKey();
            $membership->user_id = $owner->getKey();
            $membership->role_id = $roles['owner']->getKey();
            $membership->status = 'active';
            $membership->save();

            if (! $owner->active_organization_id) {
                $owner->active_organization_id = $organization->getKey();
                $owner->active_store_id = null;
                $owner->save();
            }

            $this->audit->record(
                'organization.created',
                $owner,
                $organization,
                auditable: $organization,
                newValues: ['name' => $organization->name, 'settings' => $settings],
            );

            return $organization;
        });
    }
}
