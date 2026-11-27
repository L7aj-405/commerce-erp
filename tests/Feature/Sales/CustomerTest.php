<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Services\CustomerManager;
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
            'email' => 'buyer@example.test', 'phone' => '100', 'whatsapp_username' => '@acme.buyer', 'status' => 'active',
        ])->assertRedirect();
        $customer = $organization->customers()->firstOrFail();
        $this->assertSame('acme.buyer', $customer->whatsapp_username);
        $this->actingAs($owner)->patch(route('sales.customers.update', $customer), [
            'type' => 'company', 'display_name' => 'ACME Updated', 'company_name' => 'ACME',
            'email' => 'new@example.test', 'phone' => '200', 'whatsapp_username' => '', 'status' => 'inactive',
        ])->assertRedirect();
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'organization_id' => $organization->id, 'display_name' => 'ACME Updated', 'status' => 'inactive']);
        $this->assertNull($customer->fresh()->whatsapp_username);
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
        app(CustomerManager::class)->create($ownerA, $organizationA, ['type' => 'individual', 'display_name' => 'Visible Match', 'status' => 'active']);
        app(CustomerManager::class)->create($ownerB, $organizationB, ['type' => 'individual', 'display_name' => 'Foreign Match', 'status' => 'active']);
        // The customer directory is the Clients view of the unified Contacts module: the old URL redirects, the search stays tenant scoped.
        $this->actingAs($ownerA)->get(route('sales.customers.index', ['search' => 'Match']))->assertRedirect(route('contacts.index', ['role' => 'client', 'search' => 'Match']));
        $this->actingAs($ownerA)->get(route('contacts.index', ['role' => 'client', 'search' => 'Match']))->assertInertia(fn (Assert $page) => $page
            ->has('parties.data', 1)->where('parties.data.0.display_name', 'Visible Match')->where('parties.total', 1));
    }

    public function test_inactive_customer_remains_visible_but_is_not_available_for_new_orders(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $customer = app(CustomerManager::class)->create($owner, $organization, ['type' => 'individual', 'display_name' => 'Inactive', 'status' => 'inactive']);
        // Still listed (the Clients view shows every active contact, whatever its customer status) ...
        $this->actingAs($owner)->get(route('contacts.index', ['role' => 'client']))->assertOk()->assertSee('Inactive');
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
