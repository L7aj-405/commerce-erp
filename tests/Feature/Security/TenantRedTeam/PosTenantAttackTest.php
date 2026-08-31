<?php

namespace Tests\Feature\Security\TenantRedTeam;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PosTestCase;

class PosTenantAttackTest extends PosTestCase
{
    private User $user;

    private Organization $organizationA;

    private Organization $organizationB;

    private Store $storeA1;

    private Store $storeA2;

    private Store $storeB;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private ProductVariant $variantA;

    private ProductVariant $variantB;

    private Customer $customerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->organizationA = $this->createOrganization($this->user, 'POS Org A');
        $this->storeA1 = $this->createStore($this->organizationA, $this->user, 'Store A1');
        $this->storeA2 = $this->createStore($this->organizationA, $this->user, 'Store A2');
        $this->warehouseA = $this->createWarehouse($this->organizationA, 'Warehouse A');
        $this->variantA = $this->createProduct($this->organizationA, 'Visible Product', 'POS-A', [
            'barcode' => '6111111111111', 'default_sale_price' => '100.0000',
        ])->variants->first();

        $this->organizationB = $this->createOrganization($this->user, 'POS Org B');
        $this->storeB = $this->createStore($this->organizationB, $this->user, 'Store B');
        $this->warehouseB = $this->createWarehouse($this->organizationB, 'Secret Warehouse B');
        $this->variantB = $this->createProduct($this->organizationB, 'Secret Product B', 'POS-B', [
            'barcode' => '6999999999999', 'default_sale_price' => '999.0000',
        ])->variants->first();
        $this->customerB = $this->createCustomer($this->organizationB, 'Secret Customer B');

        $this->activate($this->user, $this->organizationA, $this->storeA1);
        $this->openStock($this->user, $this->organizationA, $this->warehouseA, $this->variantA, '20.0000');
    }

    public function test_forged_tenant_store_lifecycle_totals_and_source_fields_are_not_trusted(): void
    {
        $payload = $this->posPayload($this->warehouseA, [$this->posCatalogLine($this->variantA, [
            'subtotal_excl_tax' => '0.0000', 'tax_total' => '0.0000', 'total_incl_tax' => '0.0000',
            'inventory_reservation_id' => 999999,
        ])], [
            'organization_id' => $this->organizationB->id,
            'store_id' => $this->storeB->id,
            'source' => 'ecommerce',
            'status' => 'cancelled',
            'payment_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'total_incl_tax' => '0.0000',
            'tax_total' => '0.0000',
            'discount_total' => '999.0000',
            'confirmed_by' => 999999,
            'fulfilled_by' => 999999,
        ]);

        $this->actingAs($this->user)->post(route('pos.sales.store'), $payload)->assertRedirect();

        $order = SalesOrder::query()->firstOrFail();
        $this->assertSame($this->organizationA->id, $order->organization_id);
        $this->assertSame($this->storeA1->id, $order->store_id);
        $this->assertSame('pos', $order->source->value);
        $this->assertSame('confirmed', $order->status->value);
        $this->assertSame('fulfilled', $order->fulfillment_status->value);
        $this->assertSame('paid', $order->payment_status->value);
        $this->assertSame('100.0000', $order->total_incl_tax);
    }

    public function test_foreign_warehouse_id_is_rejected_before_any_pos_order_is_created(): void
    {
        $this->actingAs($this->user)->postJson(route('pos.sales.store'), $this->posPayload($this->warehouseB, [
            $this->posCatalogLine($this->variantA),
        ]))->assertNotFound();

        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_foreign_variant_and_customer_ids_cannot_cross_active_organization(): void
    {
        $this->actingAs($this->user)->postJson(route('pos.sales.store'), $this->posPayload($this->warehouseA, [
            $this->posCatalogLine($this->variantB),
        ]))->assertNotFound();
        $this->actingAs($this->user)->postJson(route('pos.sales.store'), $this->posPayload($this->warehouseA, [
            $this->posCatalogLine($this->variantA),
        ], ['customer_id' => $this->customerB->id]))->assertNotFound();

        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_foreign_financial_account_cannot_receive_pos_payment(): void
    {
        $foreignAccount = $this->createPosAccount($this->organizationB, 'cash', 'B-CASH', 'Secret Cash B');
        $payload = $this->posPayload($this->warehouseA, [$this->posCatalogLine($this->variantA)], ['payments' => [
            $this->posPayment($foreignAccount, '100.0000'),
        ]]);
        $this->actingAs($this->user)->postJson(route('pos.sales.store'), $payload)->assertNotFound();
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_pos_account_options_do_not_leak_other_organizations(): void
    {
        $visible = $this->createPosAccount($this->organizationA, 'cash', 'A-CASH', 'Visible Cash A');
        $hidden = $this->createPosAccount($this->organizationB, 'cash', 'B-CASH', 'Secret Cash B');
        $this->actingAs($this->user)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->has('financialAccounts', 1)
            ->where('financialAccounts.0.id', $visible->id)
            ->whereNot('financialAccounts.0.id', $hidden->id));
    }

    public function test_known_foreign_barcode_and_customer_metadata_are_not_disclosed(): void
    {
        $productResponse = $this->actingAs($this->user)->getJson(route('pos.products.index', [
            'warehouse_id' => $this->warehouseA->id, 'barcode' => '6999999999999',
        ]))->assertOk()->assertJsonCount(0, 'data');
        $customerResponse = $this->actingAs($this->user)->getJson(route('pos.customers.index', [
            'search' => 'Secret Customer B',
        ]))->assertOk()->assertJsonCount(0, 'data');

        $this->assertStringNotContainsString('Secret Product B', $productResponse->getContent());
        $this->assertStringNotContainsString('Secret Customer B', $customerResponse->getContent());
    }

    public function test_same_organization_different_store_id_cannot_override_active_store(): void
    {
        $this->actingAs($this->user)->post(route('pos.sales.store'), $this->posPayload($this->warehouseA, [
            $this->posCatalogLine($this->variantA),
        ], ['store_id' => $this->storeA2->id]))->assertRedirect();

        $this->assertDatabaseHas('sales_orders', ['store_id' => $this->storeA1->id, 'source' => 'pos']);
        $this->assertDatabaseMissing('sales_orders', ['store_id' => $this->storeA2->id]);
    }

    public function test_recent_pos_sales_do_not_leak_across_stores_and_operation_ids_are_store_scoped(): void
    {
        $operationId = (string) Str::uuid();
        $this->activate($this->user, $this->organizationA, $this->storeA2);
        $this->actingAs($this->user)->post(route('pos.sales.store'), $this->posPayload($this->warehouseA, [
            $this->posCustomLine(['description' => 'Store A2 POS Secret']),
        ], ['client_operation_id' => $operationId]))->assertRedirect();
        $storeA2Order = SalesOrder::query()->where('store_id', $this->storeA2->id)->firstOrFail();

        $this->activate($this->user, $this->organizationA, $this->storeA1);
        $response = $this->actingAs($this->user)->get(route('pos.index'))->assertOk();
        $this->assertStringNotContainsString($storeA2Order->order_number, $response->getContent());
        $this->assertStringNotContainsString('Store A2 POS Secret', $response->getContent());

        $this->actingAs($this->user)->post(route('pos.sales.store'), $this->posPayload($this->warehouseA, [
            $this->posCatalogLine($this->variantA),
        ], ['client_operation_id' => $operationId]))->assertRedirect();

        $this->assertDatabaseCount('sales_orders', 2);
        $this->assertDatabaseHas('sales_orders', ['store_id' => $this->storeA1->id, 'client_operation_id' => $operationId]);
        $this->assertDatabaseHas('sales_orders', ['store_id' => $this->storeA2->id, 'client_operation_id' => $operationId]);
    }
}
