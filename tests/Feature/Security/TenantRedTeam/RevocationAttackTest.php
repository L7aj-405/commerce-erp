<?php

namespace Tests\Feature\Security\TenantRedTeam;

use App\Models\User;

class RevocationAttackTest extends TenantRedTeamTestCase
{
    public function test_suspended_organization_membership_revokes_stale_session_and_catalog_access(): void
    {
        $membership = $this->userA->organizationMemberships()
            ->where('organization_id', $this->organizationA->id)
            ->firstOrFail();
        $membership->status = 'suspended';
        $membership->save();

        $this->actingAs($this->userA)
            ->withHeader('X-Inertia', 'true')
            ->get(route('catalog.products.index'))
            ->assertStatus(409);

        $response = $this->actingAs($this->userA)
            ->withHeader('X-Inertia', 'true')
            ->get(route('platform.index'))
            ->assertOk()
            ->assertJsonPath('props.tenant.organization', null)
            ->assertJsonPath('props.tenant.store', null)
            ->assertJsonCount(1, 'props.organizations')
            ->assertJsonPath('props.organizations.0.id', $this->organizationB->id)
            ->assertJsonCount(0, 'props.stores');

        $this->assertNull($this->userA->fresh()->active_organization_id);
        $this->assertNull($this->userA->fresh()->active_store_id);
        $this->assertResponseDoesNotContain($response, [
            'RedTeam Organization A', 'RedTeam Store A', 'RedTeam Product A',
        ]);
    }

    public function test_store_membership_revocation_invalidates_active_store_on_next_request(): void
    {
        $this->storeA->memberships()->where('user_id', $this->userA->id)->delete();

        $response = $this->actingAs($this->userA)
            ->withHeader('X-Inertia', 'true')
            ->get(route('platform.index'))
            ->assertOk()
            ->assertJsonPath('props.tenant.organization.id', $this->organizationA->id)
            ->assertJsonPath('props.tenant.store', null)
            ->assertJsonCount(0, 'props.stores');

        $this->assertNull($this->userA->fresh()->active_store_id);
        $this->assertResponseDoesNotContain($response, ['RedTeam Store A', 'RED-A']);
        $this->actingAs($this->userA)
            ->get(route('stores.show', $this->storeA))
            ->assertNotFound();
    }

    public function test_permission_revocation_takes_effect_without_new_login(): void
    {
        $viewer = User::factory()->create();
        $membership = $this->addOrganizationMember(
            $this->organizationA,
            $viewer,
            ['catalog.view', 'products.view'],
            roleName: 'Revocable Viewer',
        );
        $this->activate($viewer, $this->organizationA);

        $this->actingAs($viewer)
            ->withHeader('X-Inertia', 'true')
            ->get(route('catalog.products.show', $this->productA))
            ->assertOk();

        $productsView = $this->permission('products.view');
        $membership->role->permissions()->detach($productsView->id);

        $this->actingAs($viewer)
            ->withHeader('X-Inertia', 'true')
            ->get(route('catalog.products.show', $this->productA))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->withHeader('X-Inertia', 'true')
            ->get(route('catalog.products.index'))
            ->assertForbidden();
    }
}
