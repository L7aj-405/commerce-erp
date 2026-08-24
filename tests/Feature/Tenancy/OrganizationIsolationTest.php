<?php

namespace Tests\Feature\Tenancy;

use App\Models\OrganizationMembership;
use App\Models\Store;
use App\Models\User;
use Tests\Support\PlatformTestCase;

class OrganizationIsolationTest extends PlatformTestCase
{
    public function test_user_cannot_view_update_or_delete_another_organization(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $organizationA = $this->createOrganization($userA, 'Organization A');
        $organizationB = $this->createOrganization($userB, 'Organization B');

        $this->actingAs($userA)->get(route('organizations.show', $organizationB))->assertNotFound();
        $this->actingAs($userA)->patch(route('organizations.update', $organizationB), ['name' => 'Forged'])->assertNotFound();
        $this->actingAs($userA)->delete(route('organizations.destroy', $organizationB))->assertNotFound();

        $this->assertDatabaseHas('organizations', ['id' => $organizationA->id, 'name' => 'Organization A']);
        $this->assertDatabaseHas('organizations', ['id' => $organizationB->id, 'name' => 'Organization B']);
    }

    public function test_forged_organization_id_is_ignored_when_creating_a_store(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $organizationA = $this->createOrganization($userA, 'Organization A');
        $organizationB = $this->createOrganization($userB, 'Organization B');
        $this->activate($userA, $organizationA);

        $this->actingAs($userA)->post(route('stores.store'), [
            'name' => 'Safe Store',
            'code' => 'SAFE',
            'organization_id' => $organizationB->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('stores', [
            'name' => 'Safe Store',
            'organization_id' => $organizationA->id,
        ]);
        $this->assertDatabaseMissing('stores', [
            'name' => 'Safe Store',
            'organization_id' => $organizationB->id,
        ]);
    }

    public function test_knowing_a_valid_foreign_tenant_id_does_not_grant_access(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $this->createOrganization($userA, 'Organization A');
        $organizationB = $this->createOrganization($userB, 'Organization B');

        $this->actingAs($userA)
            ->getJson('/organizations/'.$organizationB->getKey())
            ->assertNotFound();
    }

    public function test_cross_tenant_membership_route_binding_is_rejected(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $target = User::factory()->create();
        $this->createOrganization($userA, 'Organization A');
        $organizationB = $this->createOrganization($userB, 'Organization B');
        $membershipB = $this->addOrganizationMember($organizationB, $target, ['organizations.view']);

        $this->actingAs($userA)
            ->patchJson(route('organization-memberships.update', $membershipB), ['status' => 'suspended'])
            ->assertNotFound();

        $this->assertDatabaseHas('organization_memberships', [
            'id' => $membershipB->id,
            'status' => 'active',
        ]);
    }

    public function test_cross_tenant_role_cannot_be_used_to_create_a_membership(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $newUser = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'Organization A');
        $organizationB = $this->createOrganization($ownerB, 'Organization B');
        $roleB = $organizationB->roles()->where('slug', 'admin')->firstOrFail();

        $this->actingAs($ownerA)
            ->postJson(route('organization-memberships.store', $organizationA), [
                'user_id' => $newUser->id,
                'role_id' => $roleB->id,
                'organization_id' => $organizationB->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role_id');

        $this->assertDatabaseMissing('organization_memberships', [
            'organization_id' => $organizationA->id,
            'user_id' => $newUser->id,
        ]);
    }
}
