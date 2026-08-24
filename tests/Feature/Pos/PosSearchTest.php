<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use Tests\Support\PosTestCase;

class PosSearchTest extends PosTestCase
{
    public function test_product_search_by_name(): void
    {
        [$owner, , , $warehouse, $variant] = $this->searchContext();
        $this->actingAs($owner)->getJson(route('pos.products.index', ['warehouse_id' => $warehouse->id, 'search' => 'Studio Camera']))
            ->assertOk()->assertJsonPath('data.0.id', $variant->id);
    }

    public function test_product_search_by_sku(): void
    {
        [$owner, , , $warehouse, $variant] = $this->searchContext();
        $this->actingAs($owner)->getJson(route('pos.products.index', ['warehouse_id' => $warehouse->id, 'search' => 'POS-SKU']))
            ->assertOk()->assertJsonPath('data.0.id', $variant->id);
    }

    public function test_product_search_by_reference(): void
    {
        [$owner, , , $warehouse, $variant] = $this->searchContext();
        $this->actingAs($owner)->getJson(route('pos.products.index', ['warehouse_id' => $warehouse->id, 'search' => 'POS-REF']))
            ->assertOk()->assertJsonPath('data.0.id', $variant->id);
    }

    public function test_exact_barcode_lookup_returns_one_accessible_variant(): void
    {
        [$owner, , , $warehouse, $variant] = $this->searchContext();
        $this->actingAs($owner)->getJson(route('pos.products.index', ['warehouse_id' => $warehouse->id, 'barcode' => '6111111111111']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $variant->id);
    }

    public function test_foreign_tenant_products_are_excluded_even_by_exact_barcode(): void
    {
        [$owner, , , $warehouse] = $this->searchContext();
        $other = User::factory()->create();
        $foreignOrganization = $this->createOrganization($other, 'Foreign');
        $foreign = $this->createProduct($foreignOrganization, 'Secret', 'SECRET', ['barcode' => '6999999999999']);

        $this->actingAs($owner)->getJson(route('pos.products.index', ['warehouse_id' => $warehouse->id, 'barcode' => '6999999999999']))
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonMissing(['id' => $foreign->variants->first()->id]);
    }

    public function test_product_result_uses_inventory_balance_for_selected_warehouse_without_purchase_price(): void
    {
        [$owner, , , $warehouse, $variant] = $this->searchContext();
        $response = $this->actingAs($owner)->getJson(route('pos.products.index', ['warehouse_id' => $warehouse->id, 'search' => 'POS-SKU']))
            ->assertOk()
            ->assertJsonPath('data.0.stock.on_hand', '8.0000')
            ->assertJsonPath('data.0.stock.reserved', '0.0000')
            ->assertJsonPath('data.0.stock.available', '8.0000');

        $this->assertStringNotContainsString('purchase_price', $response->getContent());
        $this->assertSame($variant->id, $response->json('data.0.id'));
    }

    public function test_customer_lookup_is_organization_scoped(): void
    {
        [$owner, $organization, , $warehouse] = $this->searchContext();
        $customer = $this->createCustomer($organization, 'POS Customer', ['phone' => '0600000000']);
        $other = User::factory()->create();
        $foreignOrganization = $this->createOrganization($other);
        $this->createCustomer($foreignOrganization, 'Secret Customer', ['phone' => '0600000000']);

        $this->actingAs($owner)->getJson(route('pos.customers.index', ['search' => '0600000000', 'warehouse_id' => $warehouse->id]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $customer->id);
    }

    public function test_quick_customer_creation_reuses_customer_domain_and_ignores_privileged_fields(): void
    {
        [$owner, $organization] = $this->searchContext();
        $foreignOwner = User::factory()->create();
        $foreignOrganization = $this->createOrganization($foreignOwner);

        $this->actingAs($owner)->post(route('pos.customers.store'), [
            'display_name' => 'Quick Customer', 'phone' => '0612345678', 'email' => 'quick@example.test',
            'organization_id' => $foreignOrganization->id, 'status' => 'inactive', 'type' => 'company',
        ])->assertRedirect(route('pos.index'));

        $this->assertDatabaseHas('customers', [
            'organization_id' => $organization->id, 'display_name' => 'Quick Customer',
            'status' => 'active', 'type' => 'individual',
        ]);
        $this->assertDatabaseMissing('customers', ['organization_id' => $foreignOrganization->id, 'display_name' => 'Quick Customer']);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'event' => 'customer.created']);
    }

    private function searchContext(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $product = $this->createProduct($organization, 'Studio Camera', 'POS-SKU', [
            'reference' => 'POS-REF', 'barcode' => '6111111111111',
            'purchase_price' => '25.0000', 'default_sale_price' => '100.0000',
        ]);
        $variant = $product->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '8.0000');

        return [$owner, $organization, $store, $warehouse, $variant];
    }
}
