<?php

namespace App\Services\OrganizationDataReset;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class OrganizationDataResetOwnerGuard
{
    /** @return array<string,mixed> */
    public function snapshot(Organization $organization): array
    {
        $owner = DB::table('users')->where('id', $organization->owner_id)->first();
        $membership = DB::table('organization_memberships')
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $organization->owner_id)
            ->first();

        if (! $owner || ! $membership || $membership->status !== 'active') {
            throw new OrganizationDataResetException('The primary Owner must have an active organization membership before reset.');
        }

        $role = DB::table('roles')
            ->where('organization_id', $organization->getKey())
            ->where('id', $membership->role_id)
            ->first();
        if (! $role || ! (bool) $role->is_system) {
            throw new OrganizationDataResetException('The primary Owner must use a protected system role before reset.');
        }

        return [
            'organization_owner_id' => (int) $organization->owner_id,
            'owner' => $this->only((array) $owner, [
                'id', 'name', 'email', 'email_verified_at', 'password', 'remember_token',
                'active_organization_id', 'active_store_id', 'two_factor_secret',
                'two_factor_recovery_codes', 'two_factor_confirmed_at',
            ]),
            'membership' => (array) $membership,
            'role' => (array) $role,
            'role_permissions' => DB::table('role_permission')
                ->where('organization_id', $organization->getKey())
                ->where('role_id', $membership->role_id)
                ->orderBy('permission_id')->get()->map(fn ($row) => (array) $row)->all(),
            'store_memberships' => DB::table('store_memberships')
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $organization->owner_id)
                ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'security' => (array) (DB::table('organization_security_settings')
                ->where('organization_id', $organization->getKey())->first() ?? []),
            'trusted_devices' => DB::table('trusted_two_factor_devices')
                ->where('user_id', $organization->owner_id)
                ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'social_identities' => DB::table('user_social_identities')
                ->where('user_id', $organization->owner_id)
                ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'sessions' => DB::table('sessions')
                ->where('user_id', $organization->owner_id)
                ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'notification_preferences' => DB::table('user_notification_preferences')
                ->where('user_id', $organization->owner_id)
                ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }

    /** @param array<string,mixed> $before */
    public function assertUnchanged(Organization $organization, array $before): void
    {
        if ($before !== $this->snapshot($organization)) {
            throw new OrganizationDataResetException('The primary Owner login, access, password, 2FA or security state changed during reset.');
        }
    }

    /** @param array<string,mixed> $values @param list<string> $keys @return array<string,mixed> */
    private function only(array $values, array $keys): array
    {
        return array_intersect_key($values, array_flip($keys));
    }
}
