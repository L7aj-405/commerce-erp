<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InventoryTestCase;

class ProductVisualsTest extends InventoryTestCase
{
    public function test_product_index_exposes_image_url_and_safe_visual_fields(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $product = $this->createProduct($organization, 'Shure SM58', 'SM58-LCE', [
            'reference' => 'AV-00125',
            'default_sale_price' => '1250.0000',
            'image_url' => 'https://images.example.test/sm58.png',
        ]);

        $this->actingAs($owner)->get(route('catalog.products.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Catalog/Products/Index')
            ->where('products.data.0.id', $product->id)
            ->where('products.data.0.image_url', 'https://images.example.test/sm58.png')
            ->where('products.data.0.variants.0.sku', 'SM58-LCE')
            ->where('products.data.0.variants.0.reference', 'AV-00125'));
    }

    public function test_product_show_exposes_image_url_even_when_stock_is_viewable(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization, 'Showroom');
        $product = $this->createProduct($organization, 'Wireless Mic', 'WM-01', [
            'reference' => 'REF-WM',
            'image_url' => 'https://images.example.test/wireless-mic.png',
        ]);
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $product->variants->first(), '3.0000');

        $this->actingAs($owner)->get(route('catalog.products.show', $product))->assertInertia(fn (Assert $page) => $page
            ->component('Catalog/Products/Show')
            ->where('product.image_url', 'https://images.example.test/wireless-mic.png')
            ->where('product.variants.0.sku', 'WM-01')
            ->where('product.variants.0.reference', 'REF-WM')
            ->where('stock.variants.0.warehouses.0.warehouse.name', 'Showroom'));
    }

    public function test_missing_product_image_remains_nullable_in_visual_payload(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $product = $this->createProduct($organization, 'No Image Product', 'NOIMG-1', [
            'image_url' => null,
        ]);

        $this->actingAs($owner)->get(route('catalog.products.show', $product))->assertInertia(fn (Assert $page) => $page
            ->component('Catalog/Products/Show')
            ->where('product.image_url', null));
    }

    public function test_foreign_product_image_payload_does_not_leak_across_tenants(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $organizationA = $this->createOrganization($ownerA, 'Org A');
        $organizationB = $this->createOrganization($ownerB, 'Org B');
        $this->createProduct($organizationA, 'Visible Product', 'VIS-1', ['image_url' => 'https://images.example.test/visible.png']);
        $foreign = $this->createProduct($organizationB, 'Hidden Product', 'HID-1', ['image_url' => 'https://images.example.test/hidden.png']);

        $response = $this->actingAs($ownerA)->get(route('catalog.products.index'));
        $response->assertOk();
        $response->assertDontSee('https://images.example.test/hidden.png');
        $this->actingAs($ownerA)->get(route('catalog.products.show', $foreign))->assertNotFound();
    }
}
