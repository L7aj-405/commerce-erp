<?php

namespace Tests\Feature\Security\TenantRedTeam;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SalesTestCase;

class SalesTenantAttackTest extends SalesTestCase
{
    private User $userA;

    private User $userB;

    private Organization $organizationA;

    private Organization $organizationB;

    private Organization $organizationC;

    private Store $storeA1;

    private Store $storeA2;

    private Store $storeB;

    private Store $storeC;

    private Customer $customerA;

    private Customer $customerB;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private ProductVariant $variantA;

    private ProductVariant $variantB;

    private SalesOrder $orderA1;

    private SalesOrder $orderA2;

    private SalesOrder $orderB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();
        $this->organizationA = $this->createOrganization($this->userA, 'Sales Org A');
        $this->storeA1 = $this->createStore($this->organizationA, $this->userA, 'Store A1');
        $this->storeA2 = $this->createStore($this->organizationA, $this->userA, 'Store A2');
        $this->customerA = $this->createCustomer($this->organizationA, 'Customer A');
        $this->warehouseA = $this->createWarehouse($this->organizationA, 'Warehouse A');
        $this->variantA = $this->createProduct($this->organizationA, 'Product A', 'SALE-A')->variants->first();
        $this->activate($this->userA, $this->organizationA, $this->storeA1);
        $this->openStock($this->userA, $this->organizationA, $this->warehouseA, $this->variantA, '10.0000');
        $this->orderA1 = $this->createDraftOrder($this->userA, $this->organizationA, $this->storeA1, $this->customerA);
        $this->addCatalogLine($this->userA, $this->orderA1, $this->variantA, $this->warehouseA);
        app(ConfirmSalesOrderAction::class)->execute($this->userA, $this->orderA1);
        $this->orderA2 = $this->createDraftOrder($this->userA, $this->organizationA, $this->storeA2);
        $this->addCustomLine($this->userA, $this->orderA2, ['description' => 'Store A2 Secret']);

        $this->organizationB = $this->createOrganization($this->userA, 'Sales Org B');
        $this->storeB = $this->createStore($this->organizationB, $this->userA, 'Store B');
        $this->customerB = $this->createCustomer($this->organizationB, 'Secret Customer B');
        $this->warehouseB = $this->createWarehouse($this->organizationB, 'Secret Warehouse B');
        $this->variantB = $this->createProduct($this->organizationB, 'Secret Product B', 'SALE-B')->variants->first();
        $this->activate($this->userA, $this->organizationB, $this->storeB);
        $this->openStock($this->userA, $this->organizationB, $this->warehouseB, $this->variantB);
        $this->orderB = $this->createDraftOrder($this->userA, $this->organizationB, $this->storeB, $this->customerB);

        $this->organizationC = $this->createOrganization($this->userB, 'Sales Org C');
        $this->storeC = $this->createStore($this->organizationC, $this->userB, 'Store C');
        $this->activate($this->userA, $this->organizationA, $this->storeA1);
    }

    public function test_foreign_customer_and_foreign_organization_order_ids_are_non_discoverable(): void
    {
        $this->actingAs($this->userA)->get(route('sales.customers.edit', $this->customerB))->assertNotFound();
        $this->actingAs($this->userA)->get(route('sales.orders.show', $this->orderB))->assertNotFound();
    }

    public function test_same_organization_order_in_non_active_store_is_non_discoverable_until_switch(): void
    {
        $this->actingAs($this->userA)->get(route('sales.orders.show', $this->orderA2))->assertNotFound();
        $this->activate($this->userA, $this->organizationA, $this->storeA2);
        $this->actingAs($this->userA)->get(route('sales.orders.show', $this->orderA2))->assertOk()->assertSee('Store A2 Secret');
    }

    public function test_unrelated_user_cannot_access_known_order_ids(): void
    {
        $this->activate($this->userB, $this->organizationC, $this->storeC);
        $this->actingAs($this->userB)->get(route('sales.orders.show', $this->orderA1))->assertNotFound();
        $this->actingAs($this->userB)->post(route('sales.orders.confirm', $this->orderA2))->assertNotFound();
    }

    public function test_forged_store_organization_and_actor_fields_cannot_change_order_ownership(): void
    {
        $this->actingAs($this->userA)->post(route('sales.orders.store'), [
            'organization_id' => $this->organizationB->id, 'store_id' => $this->storeB->id, 'created_by_user_id' => $this->userB->id,
            'sale_date' => '2026-08-24', 'currency_code' => 'MAD',
        ])->assertRedirect();
        $this->assertDatabaseHas('sales_orders', ['organization_id' => $this->organizationA->id, 'store_id' => $this->storeA1->id, 'created_by_user_id' => $this->userA->id, 'order_number' => 'SO-000003']);
    }

    public function test_foreign_customer_variant_and_warehouse_relationship_attacks_fail(): void
    {
        $this->actingAs($this->userA)->postJson(route('sales.orders.store'), ['customer_id' => $this->customerB->id, 'sale_date' => '2026-08-24', 'currency_code' => 'MAD'])->assertJsonValidationErrors('customer_id');
        $draft = $this->createDraftOrder($this->userA, $this->organizationA, $this->storeA1);
        $this->actingAs($this->userA)->post(route('sales.orders.lines.store', $draft), ['line_type' => 'catalog', 'product_variant_id' => $this->variantB->id, 'warehouse_id' => $this->warehouseA->id, 'quantity' => '1.0000', 'discount_type' => 'none'])->assertNotFound();
        $this->actingAs($this->userA)->post(route('sales.orders.lines.store', $draft), ['line_type' => 'catalog', 'product_variant_id' => $this->variantA->id, 'warehouse_id' => $this->warehouseB->id, 'quantity' => '1.0000', 'discount_type' => 'none'])->assertNotFound();
    }

    public function test_forged_totals_statuses_and_snapshot_actors_are_ignored(): void
    {
        $draft = $this->createDraftOrder($this->userA, $this->organizationA, $this->storeA1);
        $this->actingAs($this->userA)->post(route('sales.orders.lines.store', $draft), [
            'line_type' => 'custom', 'description' => 'Safe', 'quantity' => '1.0000', 'unit_price_excl_tax' => '5.0000', 'discount_type' => 'none',
            'subtotal_excl_tax' => '99999', 'discount_total' => '99999', 'tax_total' => '99999', 'total_incl_tax' => '99999',
            'status' => 'confirmed', 'payment_status' => 'paid', 'fulfillment_status' => 'fulfilled',
            'confirmed_by_user_id' => $this->userB->id, 'fulfilled_by_user_id' => $this->userB->id, 'cancelled_by_user_id' => $this->userB->id,
        ])->assertRedirect();
        $draft->refresh();
        $this->assertSame('5.0000', $draft->total_incl_tax);
        $this->assertSame('draft', $draft->status->value);
        $this->assertNull($draft->confirmed_by_user_id);
    }

    public function test_direct_lifecycle_urls_cannot_cross_store_or_tenant_boundary(): void
    {
        foreach (['confirm', 'cancel', 'fulfill'] as $action) {
            $this->actingAs($this->userA)->post(route("sales.orders.{$action}", $this->orderA2), ['reason' => 'Attack'])->assertNotFound();
            $this->actingAs($this->userA)->post(route("sales.orders.{$action}", $this->orderB), ['reason' => 'Attack'])->assertNotFound();
        }
    }

    public function test_sales_search_dropdowns_and_pagination_do_not_leak_foreign_metadata(): void
    {
        $this->actingAs($this->userA)->get(route('sales.orders.index', ['search' => 'SO']))->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)->where('orders.data.0.id', $this->orderA1->id)->where('orders.total', 1));
        $draft = $this->createDraftOrder($this->userA, $this->organizationA, $this->storeA1);

        $this->actingAs($this->userA)->getJson(route('sales.orders.line-search', $draft).'?search=SALE')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'SALE-A')
            ->assertJsonMissing(['sku' => 'SALE-B']);

        $this->actingAs($this->userA)->getJson(route('sales.orders.customer-search', $draft).'?search=Customer')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.display_name', 'Customer A')
            ->assertJsonMissing(['display_name' => 'Secret Customer B']);
    }

    public function test_sales_owned_reservation_cannot_be_manipulated_through_inventory_routes(): void
    {
        $reservation = $this->orderA1->lines()->first()->allocations()->first()->inventoryReservation;
        $this->actingAs($this->userA)->post(route('inventory.reservations.release', $reservation))->assertForbidden();
        $this->actingAs($this->userA)->post(route('inventory.reservations.consume', $reservation))->assertForbidden();
        $this->assertDatabaseHas('inventory_reservations', ['id' => $reservation->id, 'status' => 'active']);
    }
}
