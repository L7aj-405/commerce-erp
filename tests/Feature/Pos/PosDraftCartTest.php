<?php

namespace Tests\Feature\Pos;

use App\Models\ProductVariant;
use App\Models\User;
use Tests\Support\PosTestCase;

class PosDraftCartTest extends PosTestCase
{
    public function test_catalog_product_can_be_added_to_server_backed_pos_cart(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->context();
        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);

        $this->actingAs($owner)->postJson(route('pos.drafts.lines.store', $draft), [
            'line_type' => 'catalog',
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '1',
            'discount_type' => 'none',
            'discount_value' => '0.0000',
        ])->assertOk()->assertJsonPath('active_sale.lines.0.product_variant_id', $variant->id);

        $this->assertDatabaseHas('sales_order_lines', ['sales_order_id' => $draft->id, 'product_variant_id' => $variant->id, 'quantity' => 1]);
    }

    public function test_duplicate_catalog_add_increments_quantity_instead_of_creating_second_line(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->context();
        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);

        $payload = [
            'line_type' => 'catalog',
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '1',
            'discount_type' => 'none',
            'discount_value' => '0.0000',
        ];

        $this->actingAs($owner)->postJson(route('pos.drafts.lines.store', $draft), $payload)->assertOk();
        $this->actingAs($owner)->postJson(route('pos.drafts.lines.store', $draft), $payload)
            ->assertOk()
            ->assertJsonPath('active_sale.lines.0.quantity', '2.0000');

        $this->assertDatabaseCount('sales_order_lines', 1);
    }

    public function test_pos_cart_quantity_can_be_updated_and_line_removed(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->context();
        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $line = $this->addCatalogLine($owner, $draft, $variant, $warehouse);

        $this->actingAs($owner)->patchJson(route('pos.drafts.lines.update', [$draft, $line]), [
            'line_type' => 'catalog',
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '3',
            'discount_type' => 'none',
            'discount_value' => '0.0000',
        ])->assertOk()->assertJsonPath('active_sale.lines.0.quantity', '3.0000');

        $this->actingAs($owner)->deleteJson(route('pos.drafts.lines.destroy', [$draft, $line]))->assertOk();

        $this->assertDatabaseMissing('sales_order_lines', ['id' => $line->id]);
    }

    public function test_unavailable_quantity_is_rejected_before_cart_mutation(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->context('1.0000');
        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);

        $this->actingAs($owner)->postJson(route('pos.drafts.lines.store', $draft), [
            'line_type' => 'catalog',
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '2',
            'discount_type' => 'none',
            'discount_value' => '0.0000',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertDatabaseCount('sales_order_lines', 0);
    }

    public function test_foreign_product_variant_is_rejected_from_pos_cart(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->context();
        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $otherOwner = User::factory()->create();
        $otherOrganization = $this->createOrganization($otherOwner);
        $foreignVariant = $this->createProduct($otherOrganization, 'Foreign', 'FOREIGN-1')->variants->first();

        $this->actingAs($owner)->postJson(route('pos.drafts.lines.store', $draft), [
            'line_type' => 'catalog',
            'product_variant_id' => $foreignVariant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '1',
            'discount_type' => 'none',
            'discount_value' => '0.0000',
        ])->assertNotFound();

        $this->assertDatabaseCount('sales_order_lines', 0);
    }

    public function test_switching_delivery_back_to_pickup_resets_shipping_fee_and_delivery_fields(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->context();
        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);

        $this->actingAs($owner)->patchJson(route('pos.drafts.update', $draft), [
            'fulfillment_mode' => 'delivery',
            'shipping_fee' => '100.0000',
            'delivery_address' => '1 Rue Atlas',
            'delivery_phone' => '0600000000',
            'delivery_notes' => 'Call on arrival',
        ])->assertOk()
            ->assertJsonPath('active_sale.checkout.fulfillment_mode', 'delivery')
            ->assertJsonPath('active_sale.summary.shipping_fee', '100.0000');

        $this->actingAs($owner)->patchJson(route('pos.drafts.update', $draft), [
            'fulfillment_mode' => 'pickup',
        ])->assertOk()
            ->assertJsonPath('active_sale.checkout.fulfillment_mode', 'pickup')
            ->assertJsonPath('active_sale.checkout.shipping_fee', '0.0000')
            ->assertJsonPath('active_sale.summary.shipping_fee', '0.0000')
            ->assertJsonPath('active_sale.checkout.delivery_address', null)
            ->assertJsonPath('active_sale.checkout.delivery_phone', null)
            ->assertJsonPath('active_sale.checkout.delivery_notes', null);
    }

    /** @return array{User, \App\Models\Organization, \App\Models\Store, \App\Models\Warehouse, ProductVariant} */
    private function context(string $stock = '8.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization, 'Showroom');
        $variant = $this->createProduct($organization, 'Shure SM58', 'SM58-LCE', [
            'reference' => 'AV-00125',
            'barcode' => '6111111111111',
            'image_url' => 'https://images.example.test/sm58.png',
            'default_sale_price' => '1250.0000',
        ])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, $stock);

        return [$owner, $organization, $store, $warehouse, $variant];
    }
}
