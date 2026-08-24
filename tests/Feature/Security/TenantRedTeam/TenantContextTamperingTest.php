<?php

namespace Tests\Feature\Security\TenantRedTeam;

class TenantContextTamperingTest extends TenantRedTeamTestCase
{
    public function test_users_cannot_activate_organizations_without_active_membership(): void
    {
        $this->actingAs($this->userA)
            ->post(route('context.organization', ['organizationId' => $this->organizationC->id]))
            ->assertForbidden();
        $this->assertSame($this->organizationA->id, $this->userA->fresh()->active_organization_id);
        $this->assertSame($this->storeA->id, $this->userA->fresh()->active_store_id);

        foreach ([$this->organizationA, $this->organizationB] as $foreignOrganization) {
            $this->actingAs($this->userB)
                ->post(route('context.organization', ['organizationId' => $foreignOrganization->id]))
                ->assertForbidden();
        }
        $this->assertSame($this->organizationC->id, $this->userB->fresh()->active_organization_id);
        $this->assertSame($this->storeC->id, $this->userB->fresh()->active_store_id);
    }

    public function test_store_ids_from_inactive_or_unrelated_organization_contexts_are_rejected(): void
    {
        foreach ([$this->storeB, $this->storeC] as $foreignStore) {
            $this->actingAs($this->userA)
                ->post(route('context.store', ['storeId' => $foreignStore->id]))
                ->assertForbidden();
        }

        foreach ([$this->storeA, $this->storeB] as $foreignStore) {
            $this->actingAs($this->userB)
                ->post(route('context.store', ['storeId' => $foreignStore->id]))
                ->assertForbidden();
        }

        $this->assertSame($this->storeA->id, $this->userA->fresh()->active_store_id);
        $this->assertSame($this->storeC->id, $this->userB->fresh()->active_store_id);
    }

    public function test_switching_organizations_clears_stale_store_before_new_store_activation(): void
    {
        $this->actingAs($this->userA)
            ->post(route('context.organization', ['organizationId' => $this->organizationB->id]))
            ->assertRedirect();

        $this->assertSame($this->organizationB->id, $this->userA->fresh()->active_organization_id);
        $this->assertNull($this->userA->fresh()->active_store_id);

        $this->actingAs($this->userA)
            ->post(route('context.store', ['storeId' => $this->storeA->id]))
            ->assertForbidden();
        $this->actingAs($this->userA)
            ->post(route('context.store', ['storeId' => $this->storeB->id]))
            ->assertRedirect();

        $this->assertSame($this->storeB->id, $this->userA->fresh()->active_store_id);
    }

    public function test_database_tampered_active_context_is_cleared_on_next_request(): void
    {
        $this->userA->active_organization_id = $this->organizationC->id;
        $this->userA->active_store_id = $this->storeC->id;
        $this->userA->save();

        $response = $this->actingAs($this->userA)
            ->withHeader('X-Inertia', 'true')
            ->get(route('platform.index'))
            ->assertOk()
            ->assertJsonPath('props.tenant.organization', null)
            ->assertJsonPath('props.tenant.store', null)
            ->assertJsonCount(0, 'props.stores');

        $this->assertNull($this->userA->fresh()->active_organization_id);
        $this->assertNull($this->userA->fresh()->active_store_id);
        $this->assertResponseDoesNotContain($response, [
            'RedTeam Organization C', 'RedTeam Store C', 'RED-C',
        ]);
    }

    public function test_forged_store_id_in_creation_payload_does_not_change_context_or_ownership(): void
    {
        $this->actingAs($this->userA)->post(route('stores.store'), [
            'name' => 'Context Attack Store',
            'code' => 'CTX-ATTACK',
            'organization_id' => $this->organizationB->id,
            'store_id' => $this->storeB->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('stores', [
            'organization_id' => $this->organizationA->id,
            'name' => 'Context Attack Store',
            'code' => 'CTX-ATTACK',
        ]);
        $this->assertDatabaseMissing('stores', [
            'organization_id' => $this->organizationB->id,
            'code' => 'CTX-ATTACK',
        ]);
        $this->assertSame($this->storeA->id, $this->userA->fresh()->active_store_id);
    }
}
