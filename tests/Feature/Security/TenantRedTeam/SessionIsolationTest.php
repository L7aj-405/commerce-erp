<?php

namespace Tests\Feature\Security\TenantRedTeam;

class SessionIsolationTest extends TenantRedTeamTestCase
{
    public function test_logout_then_login_as_another_user_does_not_inherit_tenant_context(): void
    {
        $this->actingAs($this->userA)
            ->withHeader('X-Inertia', 'true')
            ->get(route('platform.index'))
            ->assertOk()
            ->assertJsonPath('props.tenant.organization.id', $this->organizationA->id)
            ->assertJsonPath('props.tenant.store.id', $this->storeA->id);

        $this->actingAs($this->userA)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'email' => $this->userB->email,
            'password' => 'password',
        ])->assertRedirect(route('platform.index'));

        $response = $this->withHeader('X-Inertia', 'true')
            ->get(route('platform.index'))
            ->assertOk()
            ->assertJsonPath('props.auth.user.id', $this->userB->id)
            ->assertJsonPath('props.tenant.organization.id', $this->organizationC->id)
            ->assertJsonPath('props.tenant.store.id', $this->storeC->id)
            ->assertJsonCount(1, 'props.organizations')
            ->assertJsonPath('props.organizations.0.id', $this->organizationC->id)
            ->assertJsonCount(1, 'props.stores')
            ->assertJsonPath('props.stores.0.id', $this->storeC->id);

        $this->assertResponseDoesNotContain($response, [
            'RedTeam Organization A', 'RedTeam Organization B',
            'RedTeam Store A', 'RedTeam Store B', 'RED-A', 'RED-B',
        ]);
    }

    public function test_switching_one_users_context_does_not_mutate_another_users_context(): void
    {
        $this->actingAs($this->userA)
            ->post(route('context.organization', ['organizationId' => $this->organizationB->id]))
            ->assertRedirect();
        $this->actingAs($this->userA)
            ->post(route('context.store', ['storeId' => $this->storeB->id]))
            ->assertRedirect();

        $this->assertSame($this->organizationB->id, $this->userA->fresh()->active_organization_id);
        $this->assertSame($this->storeB->id, $this->userA->fresh()->active_store_id);
        $this->assertSame($this->organizationC->id, $this->userB->fresh()->active_organization_id);
        $this->assertSame($this->storeC->id, $this->userB->fresh()->active_store_id);

        $this->actingAs($this->userB)
            ->withHeader('X-Inertia', 'true')
            ->get(route('platform.index'))
            ->assertOk()
            ->assertJsonPath('props.tenant.organization.id', $this->organizationC->id)
            ->assertJsonPath('props.tenant.store.id', $this->storeC->id);
    }

    public function test_logout_invalidates_authenticated_access_without_erasing_other_users_context(): void
    {
        $this->actingAs($this->userA)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->get(route('platform.index'))->assertRedirect(route('login'));
        $this->get(route('catalog.products.index'))->assertRedirect(route('login'));

        $this->assertSame($this->organizationC->id, $this->userB->fresh()->active_organization_id);
        $this->assertSame($this->storeC->id, $this->userB->fresh()->active_store_id);
    }
}
