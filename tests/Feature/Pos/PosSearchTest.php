<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use Tests\Support\PosTestCase;

class PosSearchTest extends PosTestCase
{
    public function test_pos_catalogue_is_visible_without_search(): void
    {
        [$owner, , , $warehouse, $variant] = $this->searchContext();
        $this->actingAs($owner)->getJson(route('pos.products.index', ['warehouse_id' => $warehouse->id]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $variant->id)
            ->assertJsonPath('meta.current_page', 1);
    }

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

    public function test_product_search_by_brand(): void
    {
        [$owner, $organization, , $warehouse, $variant] = $this->searchContext();
        $variant->product->brand_id = $this->createBrand($organization, 'Shure')->id;
        $variant->product->save();

        $this->actingAs($owner)->getJson(route('pos.products.index', ['warehouse_id' => $warehouse->id, 'search' => 'Shure']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $variant->id)
            ->assertJsonPath('data.0.brand.name', 'Shure');
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
            ->assertJsonPath('data.0.stock.available', '8.0000')
            ->assertJsonPath('data.0.image_url', 'https://images.example.test/pos-product.png');

        $this->assertStringNotContainsString('purchase_price', $response->getContent());
        $this->assertSame($variant->id, $response->json('data.0.id'));
    }

    public function test_product_result_availability_is_scoped_to_operational_warehouse(): void
    {
        [$owner, $organization, , $warehouse, $variant] = $this->searchContext();
        $otherWarehouse = $this->createWarehouse($organization, 'Overflow');
        $this->openStock($owner, $organization, $otherWarehouse, $variant, '10.0000');

        $this->actingAs($owner)->getJson(route('pos.products.index', ['warehouse_id' => $warehouse->id, 'search' => 'POS-SKU']))
            ->assertOk()
            ->assertJsonPath('data.0.stock.available', '8.0000')
            ->assertJsonPath('data.0.stock.total_available', '18.0000');
    }

    public function test_catalogue_can_filter_by_price_range(): void
    {
        [$owner, $organization, , $warehouse] = $this->searchContext();
        $this->createProduct($organization, 'Budget Cable', 'CAB-1', ['default_sale_price' => '20.0000']);
        $this->createProduct($organization, 'Premium Preamp', 'PRE-9', ['default_sale_price' => '900.0000']);

        $response = $this->actingAs($owner)->getJson(route('pos.products.index', [
            'warehouse_id' => $warehouse->id,
            'price_min' => 50,
            'price_max' => 500,
        ]))->assertOk();

        $skus = collect($response->json('data'))->pluck('sku');
        $this->assertTrue($skus->contains('POS-SKU'));   // Studio Camera @ 100,00 DH
        $this->assertFalse($skus->contains('CAB-1'));
        $this->assertFalse($skus->contains('PRE-9'));
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

    public function test_customer_search_matches_company_name_email_and_phone(): void
    {
        [$owner, $organization] = $this->searchContext();
        $byCompany = $this->createCustomer($organization, 'Front Desk', ['company_name' => 'Mohamed Amine SARL', 'phone' => '0522000000']);
        $byEmail = $this->createCustomer($organization, 'Agday Mohamed', ['email' => 'mohamed@example.test']);
        $this->createCustomer($organization, 'Unrelated Client', ['phone' => '0700000000']);

        $response = $this->actingAs($owner)->getJson(route('pos.customers.index', ['search' => 'moh']))
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($byCompany->id));
        $this->assertTrue($ids->contains($byEmail->id));
        $this->assertCount(2, $ids);
    }

    public function test_customer_search_without_term_returns_active_customers_for_the_organization(): void
    {
        [$owner, $organization] = $this->searchContext();
        $mine = $this->createCustomer($organization, 'Showroom Client');
        $this->createCustomer($organization, 'Archived Client', ['status' => 'inactive']);
        $foreign = $this->createOrganization(User::factory()->create());
        $this->createCustomer($foreign, 'Foreign Client');

        $response = $this->actingAs($owner)->getJson(route('pos.customers.index'))->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertCount(1, $ids);
    }

    public function test_quick_customer_creation_reuses_customer_domain_and_ignores_privileged_fields(): void
    {
        [$owner, $organization] = $this->searchContext();
        $foreignOwner = User::factory()->create();
        $foreignOrganization = $this->createOrganization($foreignOwner);

        $this->actingAs($owner)->post(route('pos.customers.store'), [
            'display_name' => 'Quick Customer', 'phone' => '0612345678', 'email' => 'quick@example.test',
            'organization_id' => $foreignOrganization->id, 'status' => 'inactive', 'type' => 'individual',
        ])->assertRedirect(route('pos.index'));

        $this->assertDatabaseHas('customers', [
            'organization_id' => $organization->id, 'display_name' => 'Quick Customer',
            'status' => 'active', 'type' => 'individual',
        ]);
        $this->assertDatabaseMissing('customers', ['organization_id' => $foreignOrganization->id, 'display_name' => 'Quick Customer']);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'event' => 'customer.created']);
    }

    public function test_quick_customer_update_stays_in_active_organization_and_ignores_privileged_fields(): void
    {
        [$owner, $organization] = $this->searchContext();
        $customer = $this->createCustomer($organization, 'POS Customer', ['phone' => '0600000000']);
        $foreignOwner = User::factory()->create();
        $foreignOrganization = $this->createOrganization($foreignOwner);

        $this->actingAs($owner)->patchJson(route('pos.customers.update', $customer), [
            'type' => 'company',
            'company_name' => 'Updated Showroom Client',
            'contact_name' => 'Front Desk',
            'phone' => '0611111111',
            'email' => 'updated@example.test',
            'tax_identifier' => 'ICE-001',
            'billing_address' => '1 Rue Atlas',
            'organization_id' => $foreignOrganization->id,
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('data.display_name', 'Updated Showroom Client')
            ->assertJsonPath('data.company_name', 'Updated Showroom Client');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'organization_id' => $organization->id,
            'display_name' => 'Updated Showroom Client',
            'company_name' => 'Updated Showroom Client',
            'phone' => '0611111111',
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('customers', [
            'id' => $customer->id,
            'organization_id' => $foreignOrganization->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'event' => 'customer.updated']);
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
            'image_url' => 'https://images.example.test/pos-product.png',
        ]);
        $variant = $product->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '8.0000');

        return [$owner, $organization, $store, $warehouse, $variant];
    }
}
