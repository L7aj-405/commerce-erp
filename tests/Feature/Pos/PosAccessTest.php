<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PosTestCase;

class PosAccessTest extends PosTestCase
{
    public function test_pos_page_requires_authentication(): void
    {
        $this->get(route('pos.index'))->assertRedirect(route('login'));
    }

    public function test_pos_requires_a_valid_active_organization(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('pos.index'))->assertStatus(409);
    }

    public function test_pos_without_an_active_store_renders_store_selection_guidance(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Pos/Index')
            ->where('store', null)
            ->has('warehouses', 0));
    }

    public function test_pos_operational_endpoints_require_an_active_store(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $warehouse = $this->createWarehouse($organization);
        $this->activate($owner, $organization);

        $this->actingAs($owner)->getJson(route('pos.products.index', [
            'warehouse_id' => $warehouse->id,
            'search' => 'anything',
        ]))->assertStatus(409);
    }

    public function test_authorized_sales_employee_can_open_pos(): void
    {
        $owner = User::factory()->create();
        $employee = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization, 'Till Warehouse');
        $this->addDefaultSalesEmployee($organization, $store, $employee);

        $this->actingAs($employee)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Pos/Index')
            ->where('store.id', $store->id)
            ->where('warehouses.0.id', $warehouse->id));
    }

    public function test_user_without_pos_access_is_denied(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->addOrganizationMember($organization, $user, ['sales_orders.view'], roleName: 'No POS');
        $this->addStoreMember($store, $user);
        $this->activate($user, $organization, $store);

        $this->actingAs($user)->get(route('pos.index'))->assertForbidden();
    }

    public function test_available_warehouses_are_active_organization_scoped(): void
    {
        $owner = User::factory()->create();
        $organizationA = $this->createOrganization($owner, 'Organization A');
        $storeA = $this->createStore($organizationA, $owner);
        $warehouseA = $this->createWarehouse($organizationA, 'Visible Warehouse');
        $organizationB = $this->createOrganization($owner, 'Organization B');
        $warehouseB = $this->createWarehouse($organizationB, 'Hidden Warehouse');
        $this->activate($owner, $organizationA, $storeA);

        $this->actingAs($owner)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->has('warehouses', 1)
            ->where('warehouses.0.id', $warehouseA->id)
            ->missing('warehouses.1')
            ->whereNot('warehouses.0.id', $warehouseB->id));
    }
}
