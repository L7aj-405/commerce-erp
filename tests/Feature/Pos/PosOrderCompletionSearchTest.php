<?php

namespace Tests\Feature\Pos;

use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\PosTestCase;

class PosOrderCompletionSearchTest extends PosTestCase
{
    public function test_search_matches_name_reference_and_sku_and_preserves_exact_variant_identity(): void
    {
        [$owner, $organization, , $warehouse, $order] = $this->completedOrderContext();
        $product = $this->createProduct($organization, 'Yamaha NSAW392', 'YAM-BLK', [
            'label' => 'Noire',
            'reference' => 'NSAW392BL',
            'default_sale_price' => '2550.0000',
        ]);
        $black = $product->variants->first();
        $white = new ProductVariant;
        $white->organization_id = $organization->id;
        $white->product_id = $product->id;
        $white->label = 'Blanche';
        $white->sku = 'YAM-WHT';
        $white->reference = 'NSAW392WH';
        $white->default_sale_price = '2550.0000';
        $white->regular_sale_price = '2550.0000';
        $white->unit_price_ht = '2550.0000';
        $white->status = 'active';
        $white->save();
        $this->openStock($owner, $organization, $warehouse, $black, '8.0000');
        $this->openStock($owner, $organization, $warehouse, $white, '3.0000');

        $byName = $this->actingAs($owner)->getJson(route('sales.orders.completion.search', ['order' => $order, 'search' => 'Yamaha NSAW392']))
            ->assertOk()->json('data');
        $this->assertCount(2, $byName);

        $byReference = $this->actingAs($owner)->getJson(route('sales.orders.completion.search', ['order' => $order, 'search' => 'NSAW392BL']))
            ->assertOk()->json('data');
        $this->assertSame($black->id, $byReference[0]['id']);
        $this->assertSame('Noire', $byReference[0]['variant_name']);
        $this->assertSame('8.0000', $byReference[0]['local_stock_available']);
        $this->assertArrayNotHasKey('image_url', $byReference[0]);

        $bySku = $this->actingAs($owner)->getJson(route('sales.orders.completion.search', ['order' => $order, 'search' => 'YAM-WHT']))
            ->assertOk()->json('data');
        $this->assertSame($white->id, $bySku[0]['id']);
        $this->assertSame('Blanche', $bySku[0]['variant_name']);

        $this->actingAs($owner)->post(route('sales.orders.completion.store', $order), [
            'client_operation_id' => (string) Str::uuid(),
            'lines' => [['product_variant_id' => $black->id, 'quantity' => '1.0000']],
        ])->assertRedirect();
        $addedLine = $order->lines()->whereNotNull('sales_order_addendum_id')->sole();
        $this->assertSame($black->id, $addedLine->product_variant_id);
        $this->assertSame('Noire', $addedLine->variant_name);
        $this->assertNotSame($white->id, $addedLine->product_variant_id);
    }

    public function test_search_hides_foreign_and_inactive_products(): void
    {
        [$owner, $organization, , , $order] = $this->completedOrderContext();
        $inactiveProduct = $this->createProduct($organization, 'Hidden Needle', 'HIDDEN-PRODUCT', ['status' => 'inactive']);
        $inactiveVariant = $this->createProduct($organization, 'Hidden Needle', 'HIDDEN-VARIANT', ['variant_status' => 'inactive']);

        $outsider = User::factory()->create();
        $otherOrganization = $this->createOrganization($outsider, 'Other Organization');
        $foreign = $this->createProduct($otherOrganization, 'Hidden Needle', 'FOREIGN-NEEDLE');

        $ids = collect($this->actingAs($owner)
            ->getJson(route('sales.orders.completion.search', ['order' => $order, 'search' => 'Hidden Needle']))
            ->assertOk()->json('data'))
            ->pluck('id');

        $this->assertNotContains($inactiveProduct->variants->first()->id, $ids);
        $this->assertNotContains($inactiveVariant->variants->first()->id, $ids);
        $this->assertNotContains($foreign->variants->first()->id, $ids);
    }

    public function test_search_results_are_bounded_to_fifteen_variants(): void
    {
        [$owner, $organization, , , $order] = $this->completedOrderContext();
        foreach (range(1, 20) as $index) {
            $this->createProduct($organization, "Bounded Product {$index}", sprintf('BOUND-%02d', $index));
        }

        $rows = $this->actingAs($owner)
            ->getJson(route('sales.orders.completion.search', ['order' => $order, 'search' => 'Bounded Product']))
            ->assertOk()->json('data');

        $this->assertCount(15, $rows);
    }

    /** @return array{User, mixed, mixed, mixed, SalesOrder} */
    private function completedOrderContext(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $base = $this->createProduct($organization, 'Initial Product', 'INITIAL-1', ['default_sale_price' => '100.0000'])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $base, '5.0000');
        $this->actingAs($owner)->post(route('pos.sales.store'), $this->posPayload($warehouse, [$this->posCatalogLine($base)]))->assertRedirect();

        return [$owner, $organization, $store, $warehouse, SalesOrder::query()->firstOrFail()];
    }
}
