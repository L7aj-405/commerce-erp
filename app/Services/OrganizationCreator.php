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

            // Sprint 1.1 §7 — mandatory 2FA for the owner/admin roles is ON
            // by default for every NEWLY created organization (never
            // retroactively for existing ones — see the migration's doc).
            // Skipped in local/testing so it never blocks development or the
            // rest of the test suite, which creates organizations via this
            // exact path for almost every fixture — mirrors the same
            // environment-gated-default pattern already used by
            // NullChallengeVerifier.
            $organization->securitySetting()->create([
                'require_2fa' => false,
                'require_2fa_for_privileged_roles' => ! app()->environment(['local', 'testing']),
            ]);

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
