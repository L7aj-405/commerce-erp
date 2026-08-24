<?php

namespace Tests\Feature\Security;

use App\Models\OrganizationMembership;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Tests\Support\PlatformTestCase;

class DatabaseIntegrityTest extends PlatformTestCase
{
    public function test_duplicate_organization_membership_is_blocked_by_database(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $ownerRole = $organization->roles()->where('slug', 'owner')->firstOrFail();

        $this->expectException(QueryException::class);

        $duplicate = new OrganizationMembership;
        $duplicate->organization_id = $organization->id;
        $duplicate->user_id = $owner->id;
        $duplicate->role_id = $ownerRole->id;
        $duplicate->status = 'active';
        $duplicate->save();
    }

    public function test_duplicate_store_membership_is_blocked_by_database(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);

        $this->expectException(QueryException::class);

        $duplicate = new StoreMembership;
        $duplicate->organization_id = $organization->id;
        $duplicate->store_id = $store->id;
        $duplicate->user_id = $owner->id;
        $duplicate->save();
    }

    public function test_membership_role_must_belong_to_same_organization(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $member = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'Organization A');
        $organizationB = $this->createOrganization($ownerB, 'Organization B');
        $roleB = $organizationB->roles()->where('slug', 'admin')->firstOrFail();

        $this->expectException(QueryException::class);

        $membership = new OrganizationMembership;
        $membership->organization_id = $organizationA->id;
        $membership->user_id = $member->id;
        $membership->role_id = $roleB->id;
        $membership->status = 'active';
        $membership->save();
    }

    public function test_store_membership_requires_matching_store_and_organization_membership(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'Organization A');
        $organizationB = $this->createOrganization($ownerB, 'Organization B');
        $storeA = $this->createStore($organizationA, $ownerA);

        $this->expectException(QueryException::class);

        $membership = new StoreMembership;
        $membership->organization_id = $organizationB->id;
        $membership->store_id = $storeA->id;
        $membership->user_id = $ownerB->id;
        $membership->save();
    }

    public function test_store_code_is_unique_within_tenant_but_reusable_across_tenants(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'Organization A');
        $organizationB = $this->createOrganization($ownerB, 'Organization B');

        $storeA = new Store;
        $storeA->organization_id = $organizationA->id;
        $storeA->name = 'Store A';
        $storeA->code = 'MAIN';
        $storeA->status = 'active';
        $storeA->save();

        $storeB = new Store;
        $storeB->organization_id = $organizationB->id;
        $storeB->name = 'Store B';
        $storeB->code = 'MAIN';
        $storeB->status = 'active';
        $storeB->save();

        $this->assertDatabaseHas('stores', ['organization_id' => $organizationA->id, 'code' => 'MAIN']);
        $this->assertDatabaseHas('stores', ['organization_id' => $organizationB->id, 'code' => 'MAIN']);
    }

    public function test_duplicate_store_code_within_same_tenant_is_blocked(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->createStore($organization, $owner, 'First Store')->update(['code' => 'MAIN']);

        $this->expectException(QueryException::class);

        $duplicate = new Store;
        $duplicate->organization_id = $organization->id;
        $duplicate->name = 'Duplicate Store';
        $duplicate->code = 'MAIN';
        $duplicate->status = 'active';
        $duplicate->save();
    }

    public function test_required_store_organization_relationship_is_enforced(): void
    {
        $this->expectException(QueryException::class);

        $store = new Store;
        $store->name = 'Orphan Store';
        $store->code = 'ORPHAN';
        $store->status = 'active';
        $store->save();
    }
}
