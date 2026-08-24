<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SalesTestCase;

class CustomerTest extends SalesTestCase
{
    public function test_customer_can_be_created_and_updated_in_active_organization(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->actingAs($owner)->post(route('sales.customers.store'), [
            'type' => 'company', 'display_name' => 'ACME Buyer', 'company_name' => 'ACME',
            'email' => 'buyer@example.test', 'phone' => '100', 'status' => 'active',
        ])->assertRedirect();
        $customer = $organization->customers()->firstOrFail();
        $this->actingAs($owner)->patch(route('sales.customers.update', $customer), [
            'type' => 'company', 'display_name' => 'ACME Updated', 'company_name' => 'ACME',
            'email' => 'new@example.test', 'phone' => '200', 'status' => 'inactive',
        ])->assertRedirect();
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'organization_id' => $organization->id, 'display_name' => 'ACME Updated', 'status' => 'inactive']);
    }

    public function test_forged_organization_id_is_ignored_on_customer_creation(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $this->actingAs($ownerA)->post(route('sales.customers.store'), [
            'organization_id' => $organizationB->id, 'type' => 'individual', 'display_name' => 'Safe', 'status' => 'active',
        ])->assertRedirect();
        $this->assertDatabaseHas('customers', ['organization_id' => $organizationA->id, 'display_name' => 'Safe']);
        $this->assertDatabaseMissing('customers', ['organization_id' => $organizationB->id, 'display_name' => 'Safe']);
    }

    public function test_same_user_cannot_resolve_customer_outside_active_organization(): void
    {
        $owner = User::factory()->create();
        $organizationA = $this->createOrganization($owner, 'A');
        $organizationB = $this->createOrganization($owner, 'B');
        $customerB = $this->createCustomer($organizationB, 'Secret B');
        $this->activate($owner, $organizationA);
        $this->actingAs($owner)->get(route('sales.customers.edit', $customerB))->assertNotFound();
    }

    public function test_unrelated_user_cannot_access_customer(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $this->createOrganization($ownerB, 'B');
        $customerA = $this->createCustomer($organizationA);
        $this->actingAs($ownerB)->get(route('sales.customers.edit', $customerA))->assertNotFound();
    }

    public function test_customer_search_and_pagination_are_tenant_scoped(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'A');
        $organizationB = $this->createOrganization($ownerB, 'B');
        $this->createCustomer($organizationA, 'Visible Match');
        $this->createCustomer($organizationB, 'Foreign Match');
        $this->actingAs($ownerA)->get(route('sales.customers.index', ['search' => 'Match']))->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 1)->where('customers.data.0.display_name', 'Visible Match')->where('customers.total', 1));
    }

    public function test_inactive_customer_remains_visible_but_is_not_available_for_new_orders(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $customer = $this->createCustomer($organization, 'Inactive', ['status' => 'inactive']);
        $this->actingAs($owner)->get(route('sales.customers.index'))->assertOk()->assertSee('Inactive');
        $this->actingAs($owner)->postJson(route('sales.orders.store'), ['customer_id' => $customer->id, 'sale_date' => '2026-08-24', 'currency_code' => 'MAD'])
            ->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_user_without_customer_permission_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->addOrganizationMember($organization, $user, []);
        $this->activate($user, $organization);
        $this->actingAs($user)->get(route('sales.customers.index'))->assertForbidden();
    }
}
