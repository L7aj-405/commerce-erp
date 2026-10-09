<?php

namespace Tests\Feature\UsersRolesPermissions;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\Warehouse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InventoryTestCase;

/**
 * POS-W1 — configuring a member's default POS warehouse from Users & Access.
 */
class MemberDefaultWarehouseTest extends InventoryTestCase
{
    public function test_admin_can_assign_and_clear_a_default_warehouse_with_audit(): void
    {
        [$owner, $organization, $warehouse] = $this->context();
        $member = User::factory()->create();
        $membership = $this->addOrganizationMember($organization, $member, ['organizations.view']);

        $this->actingAs($owner)->patch(route('organization-memberships.update', $membership), [
            'default_warehouse_id' => $warehouse->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($warehouse->id, $membership->fresh()->default_warehouse_id);
        $log = AuditLog::query()->where('event', 'organization_membership.default_warehouse_updated')->latest('id')->firstOrFail();
        $this->assertSame($owner->id, $log->actor_id);
        $this->assertSame($organization->id, $log->organization_id);
        $this->assertNull($log->old_values['default_warehouse_id']);
        $this->assertSame($warehouse->id, $log->new_values['default_warehouse_id']);
        $this->assertSame('Showroom MAIN', $log->new_values['default_warehouse_name']);

        $this->actingAs($owner)->patch(route('organization-memberships.update', $membership), [
            'default_warehouse_id' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($membership->fresh()->default_warehouse_id);
        $cleared = AuditLog::query()->where('event', 'organization_membership.default_warehouse_updated')->latest('id')->firstOrFail();
        $this->assertSame($warehouse->id, $cleared->old_values['default_warehouse_id']);
        $this->assertSame('Showroom MAIN', $cleared->old_values['default_warehouse_name']);
        $this->assertNull($cleared->new_values['default_warehouse_id']);
    }

    public function test_unchanged_default_does_not_create_audit_noise(): void
    {
        [$owner, $organization, $warehouse] = $this->context();
        $membership = $this->addOrganizationMember($organization, User::factory()->create(), ['organizations.view']);

        foreach ([1, 2] as $attempt) {
            $this->actingAs($owner)->patch(route('organization-memberships.update', $membership), [
                'default_warehouse_id' => $warehouse->id,
            ])->assertRedirect();
        }

        $this->assertSame(1, AuditLog::query()->where('event', 'organization_membership.default_warehouse_updated')->count());
    }

    public function test_owner_can_set_their_own_default_warehouse(): void
    {
        [$owner, $organization, $warehouse] = $this->context();
        $membership = $this->membershipOf($organization, $owner);

        $this->actingAs($owner)->patch(route('organization-memberships.update', $membership), [
            'default_warehouse_id' => $warehouse->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($warehouse->id, $membership->fresh()->default_warehouse_id);
    }

    public function test_cross_organization_warehouse_is_rejected(): void
    {
        [$owner, $organization] = $this->context();
        $membership = $this->addOrganizationMember($organization, User::factory()->create(), ['organizations.view']);
        $otherOrganization = $this->createOrganization(User::factory()->create(), 'Other');
        $foreignWarehouse = $this->createWarehouse($otherOrganization, 'Foreign', 'FOREIGN');

        $this->actingAs($owner)->patch(route('organization-memberships.update', $membership), [
            'default_warehouse_id' => $foreignWarehouse->id,
        ])->assertSessionHasErrors('default_warehouse_id');

        $this->assertNull($membership->fresh()->default_warehouse_id);
    }

    public function test_forged_and_inactive_warehouse_ids_are_rejected(): void
    {
        [$owner, $organization, $warehouse] = $this->context();
        $membership = $this->addOrganizationMember($organization, User::factory()->create(), ['organizations.view']);
        $inactive = $this->createWarehouse($organization, 'Old Depot', 'OLD');
        $inactive->status = 'inactive';
        $inactive->save();

        foreach ([999999, 'abc', $inactive->id] as $value) {
            $this->actingAs($owner)->patch(route('organization-memberships.update', $membership), [
                'default_warehouse_id' => $value,
            ])->assertSessionHasErrors('default_warehouse_id');
        }

        $this->assertNull($membership->fresh()->default_warehouse_id);
    }

    public function test_user_without_member_update_permission_cannot_change_default_warehouse(): void
    {
        [$owner, $organization, $warehouse] = $this->context();
        $viewer = User::factory()->create();
        $this->addOrganizationMember($organization, $viewer, ['organizations.view', 'members.view']);
        $this->activate($viewer, $organization);
        $target = $this->addOrganizationMember($organization, User::factory()->create(), ['organizations.view']);
        $ownMembership = $this->membershipOf($organization, $viewer);

        $this->actingAs($viewer)->patch(route('organization-memberships.update', $target), [
            'default_warehouse_id' => $warehouse->id,
        ])->assertForbidden();
        $this->actingAs($viewer)->patch(route('organization-memberships.update', $ownMembership), [
            'default_warehouse_id' => $warehouse->id,
        ])->assertForbidden();

        $this->assertNull($target->fresh()->default_warehouse_id);
        $this->assertNull($ownMembership->fresh()->default_warehouse_id);
    }

    public function test_admin_of_another_organization_cannot_edit_membership(): void
    {
        [, $organization, $warehouse] = $this->context();
        $membership = $this->addOrganizationMember($organization, User::factory()->create(), ['organizations.view']);
        $outsider = User::factory()->create();
        $outsiderOrganization = $this->createOrganization($outsider, 'Outsider Org');
        $this->activate($outsider, $outsiderOrganization);

        $this->actingAs($outsider)->patch(route('organization-memberships.update', $membership), [
            'default_warehouse_id' => $warehouse->id,
        ])->assertNotFound();

        $this->assertNull($membership->fresh()->default_warehouse_id);
    }

    public function test_users_access_page_exposes_default_and_only_active_organization_warehouses(): void
    {
        [$owner, $organization, $warehouse] = $this->context();
        $inactive = $this->createWarehouse($organization, 'Old Depot', 'OLD');
        $member = User::factory()->create();
        $membership = $this->addOrganizationMember($organization, $member, ['organizations.view']);
        $membership->default_warehouse_id = $inactive->id;
        $membership->save();
        $inactive->status = 'inactive';
        $inactive->save();
        $this->createWarehouse($this->createOrganization(User::factory()->create(), 'Other'), 'Foreign', 'FOREIGN');

        $this->actingAs($owner)->get(route('users-access.index', $organization))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('warehouses', 1)
            ->where('warehouses.0.id', $warehouse->id)
            ->where('memberships.1.default_warehouse.id', $inactive->id)
            ->where('memberships.1.default_warehouse.available', false)
            ->where('memberships.0.default_warehouse', null));
    }

    public function test_member_default_warehouse_never_blocks_organization_deletion(): void
    {
        [$owner, $organization, $warehouse] = $this->context();
        $membership = $this->membershipOf($organization, $owner);
        $membership->default_warehouse_id = $warehouse->id;
        $membership->save();

        $this->actingAs($owner)->delete(route('organizations.destroy', $organization))->assertRedirect();

        $this->assertDatabaseMissing('organizations', ['id' => $organization->id]);
        $this->assertDatabaseMissing('warehouses', ['id' => $warehouse->id]);
    }

    /** @return array{User, Organization, Warehouse} */
    private function context(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization, 'Showroom MAIN', 'MAIN');
        $this->activate($owner, $organization);
        $this->withFreshAuthentication();

        return [$owner, $organization, $warehouse];
    }

    private function membershipOf(Organization $organization, User $user): OrganizationMembership
    {
        return OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
    }
}
