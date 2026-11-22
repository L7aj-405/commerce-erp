<?php

namespace Tests\Feature\Finance;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use App\Services\ActiveTenantContext;
use App\Services\MembershipService;
use App\Services\SupplierInvoices\SupplierInvoiceStoreAccess;
use Tests\Support\PlatformTestCase;

/** Phase 2 Sprint 1 (PR4): store access is explicit-membership only, with no Owner/Admin bypass. */
class SupplierInvoiceStoreAccessTest extends PlatformTestCase
{
    private function access(): SupplierInvoiceStoreAccess
    {
        return app(SupplierInvoiceStoreAccess::class);
    }

    /** @return array{0: User, 1: Organization, 2: Store} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner, 'Main');

        return [$owner, $organization, $store];
    }

    private function member(Organization $organization, Store $store): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($organization, $user, ['supplier_invoices.view']);
        $this->addStoreMember($store, $user);

        return $user;
    }

    private function assertAll(bool $expected, User $user, Store $store, string $message): void
    {
        foreach (['canCreate', 'canEdit', 'canApprove', 'canView', 'canVoid'] as $method) {
            $this->assertSame($expected, $this->access()->{$method}($user, $store), "{$message} ({$method})");
        }
    }

    public function test_a_store_creator_is_an_explicit_member(): void
    {
        [$owner, , $store] = $this->fixture();

        $this->assertDatabaseHas('store_memberships', ['store_id' => $store->id, 'user_id' => $owner->id]);
        $this->assertAll(true, $owner, $store, 'creator');
    }

    public function test_an_owner_without_store_membership_is_denied_everything(): void
    {
        [$owner, $organization] = $this->fixture();
        $otherCreator = User::factory()->create();
        $this->addOrganizationMember($organization, $otherCreator, ['stores.create']);
        $this->activate($otherCreator, $organization);
        $foreignStore = $this->createStore($organization, $otherCreator, 'Other');

        $this->assertSame($organization->owner_id, $owner->id);
        $this->assertTrue($owner->hasPermission($organization, 'supplier_invoices.approve'));
        $this->assertAll(false, $owner, $foreignStore, 'owner without membership');
        $this->assertSame([$foreignStore->id], $this->access()->visibleStoreIds($otherCreator, $organization));
        $this->assertNotContains($foreignStore->id, $this->access()->visibleStoreIds($owner, $organization));
    }

    public function test_an_admin_role_without_membership_is_denied(): void
    {
        [, $organization, $store] = $this->fixture();
        $admin = User::factory()->create();
        $membership = new OrganizationMembership;
        $membership->organization_id = $organization->id;
        $membership->user_id = $admin->id;
        $membership->role_id = $organization->roles()->where('slug', 'admin')->firstOrFail()->id;
        $membership->status = 'active';
        $membership->save();

        $this->assertTrue($admin->hasPermission($organization, 'supplier_invoices.approve'));
        $this->assertAll(false, $admin, $store, 'admin without membership');
    }

    public function test_adding_and_removing_a_store_membership_grants_and_revokes_access(): void
    {
        [, $organization, $store] = $this->fixture();
        $user = User::factory()->create();
        $this->addOrganizationMember($organization, $user, ['supplier_invoices.view']);
        $this->assertAll(false, $user, $store, 'before membership');

        $membership = $this->addStoreMember($store, $user);
        $this->assertAll(true, $user, $store, 'after membership');
        $this->assertSame([$store->id], $this->access()->visibleStoreIds($user, $organization));

        $membership->delete();
        $this->assertAll(false, $user, $store, 'after removal');
        $this->assertSame([], $this->access()->visibleStoreIds($user, $organization));
    }

    public function test_removal_through_the_membership_service_revokes_access(): void
    {
        [$owner, $organization, $store] = $this->fixture();
        $this->activate($owner, $organization, $store);
        $user = $this->member($organization, $store);
        $this->assertAll(true, $user, $store, 'member');

        app(ActiveTenantContext::class)->resolve($owner);
        $membership = StoreMembership::query()->where('store_id', $store->id)->where('user_id', $user->id)->firstOrFail();
        app(MembershipService::class)->removeStoreMember($owner, $membership);

        $this->assertAll(false, $user->fresh(), $store, 'removed through service');
    }

    public function test_inactive_store_allows_only_view_and_void_for_members(): void
    {
        [$owner, , $store] = $this->fixture();
        $store->status = 'inactive';
        $store->save();

        $this->assertFalse($this->access()->canCreate($owner, $store));
        $this->assertFalse($this->access()->canEdit($owner, $store));
        $this->assertFalse($this->access()->canApprove($owner, $store));
        $this->assertTrue($this->access()->canView($owner, $store));
        $this->assertTrue($this->access()->canVoid($owner, $store));
    }

    public function test_a_non_member_is_denied_on_an_inactive_store_too(): void
    {
        [, $organization, $store] = $this->fixture();
        $user = User::factory()->create();
        $this->addOrganizationMember($organization, $user, ['supplier_invoices.void']);
        $store->status = 'inactive';
        $store->save();

        $this->assertAll(false, $user, $store, 'non-member, inactive store');
    }

    public function test_cross_organization_membership_never_grants_access(): void
    {
        [, $organizationA, $storeA] = $this->fixture();
        $ownerB = User::factory()->create();
        $orgB = $this->createOrganization($ownerB, 'B');
        $storeB = $this->createStore($orgB, $ownerB, 'B store');

        $this->assertAll(false, $ownerB, $storeA, 'org B owner on org A store');
        $this->assertAll(true, $ownerB, $storeB, 'org B owner on own store');
        $this->assertSame([], $this->access()->visibleStoreIds($ownerB, $organizationA));
        $this->assertSame([$storeB->id], $this->access()->visibleStoreIds($ownerB, $orgB));
    }

    public function test_visible_store_ids_include_inactive_stores_and_only_the_users_stores(): void
    {
        [$owner, $organization, $active] = $this->fixture();
        $inactive = $this->createStore($organization, $owner, 'Closed');
        $inactive->status = 'inactive';
        $inactive->save();
        $third = $this->createStore($organization, $owner, 'Third');
        $user = $this->member($organization, $active);
        $this->addStoreMember($inactive, $user);

        $ids = $this->access()->visibleStoreIds($user, $organization);
        $this->assertEqualsCanonicalizing([$active->id, $inactive->id], $ids);
        $this->assertNotContains($third->id, $ids);
    }
}
