<?php

namespace Tests\Feature\Pos;

use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Tests\Support\PosTestCase;

/**
 * Regression: applying a per-line discount from the POS cart used to fail with
 * "The quantity field format is invalid." because the client re-sent the line's
 * DECIMAL(19,4) quantity string ("2.0000"), which the line endpoint validates
 * against `^[1-9]\d*$`. The client now sends the canonical whole-unit form ("2").
 */
class PosLineDiscountTest extends PosTestCase
{
    public function test_percentage_line_discount_applies_and_leaves_quantity_unchanged(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->discountContext('2000.0000');
        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $line = $this->addCatalogLine($owner, $draft, $variant, $warehouse, ['quantity' => '2']);

        $this->actingAs($owner)->patchJson(route('pos.drafts.lines.update', [$draft, $line]), $this->lineBody($variant, $warehouse, [
            'discount_type' => 'percentage',
            'discount_value' => '22',
        ]))
            ->assertOk()
            ->assertJsonPath('active_sale.lines.0.quantity', '2.0000')
            ->assertJsonPath('active_sale.lines.0.discount_type', 'percentage')
            ->assertJsonPath('active_sale.lines.0.discount_value', '22.0000');

        $line->refresh();
        $this->assertSame('2.0000', $line->quantity);
        $this->assertSame('percentage', $line->discount_type->value);
        $this->assertSame('4000.0000', $line->subtotal_excl_tax); // 2 × 2000 HT
        $this->assertSame('880.0000', $line->discount_amount);     // 22%
        $this->assertSame('3120.0000', $line->total_incl_tax);     // authoritative, server-side
    }

    public function test_fixed_line_discount_applies_and_leaves_quantity_unchanged(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->discountContext('2000.0000');
        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $line = $this->addCatalogLine($owner, $draft, $variant, $warehouse, ['quantity' => '2']);

        $this->actingAs($owner)->patchJson(route('pos.drafts.lines.update', [$draft, $line]), $this->lineBody($variant, $warehouse, [
            'discount_type' => 'fixed',
            'discount_value' => '100',
        ]))->assertOk()->assertJsonPath('active_sale.lines.0.quantity', '2.0000');

        $line->refresh();
        $this->assertSame('2.0000', $line->quantity);
        $this->assertSame('fixed', $line->discount_type->value);
        $this->assertSame('100.0000', $line->discount_amount);
        $this->assertSame('3900.0000', $line->total_incl_tax);
    }

    public function test_quantity_update_still_works(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->discountContext('2000.0000');
        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $line = $this->addCatalogLine($owner, $draft, $variant, $warehouse, ['quantity' => '2']);

        $this->actingAs($owner)->patchJson(route('pos.drafts.lines.update', [$draft, $line]), $this->lineBody($variant, $warehouse, [
            'quantity' => '3',
        ]))->assertOk()->assertJsonPath('active_sale.lines.0.quantity', '3.0000');

        $this->actingAs($owner)->patchJson(route('pos.drafts.lines.update', [$draft, $line]), $this->lineBody($variant, $warehouse, [
            'quantity' => '2',
        ]))->assertOk()->assertJsonPath('active_sale.lines.0.quantity', '2.0000');
    }

    public function test_quantity_is_required_on_a_line_update(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->discountContext('2000.0000');
        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $line = $this->addCatalogLine($owner, $draft, $variant, $warehouse, ['quantity' => '2']);

        $payload = $this->lineBody($variant, $warehouse, ['discount_type' => 'percentage', 'discount_value' => '22']);
        unset($payload['quantity']);

        $this->actingAs($owner)->patchJson(route('pos.drafts.lines.update', [$draft, $line]), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');
    }

    public function test_non_canonical_or_invalid_quantity_is_still_rejected(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->discountContext('2000.0000');
        $draft = $this->createPosDraft($owner, $organization, $store, $warehouse);
        $line = $this->addCatalogLine($owner, $draft, $variant, $warehouse, ['quantity' => '2']);

        foreach (['2.0000', 'abc', '0', '-1'] as $badQuantity) {
            $this->actingAs($owner)->patchJson(route('pos.drafts.lines.update', [$draft, $line]), $this->lineBody($variant, $warehouse, [
                'quantity' => $badQuantity,
                'discount_type' => 'percentage',
                'discount_value' => '22',
            ]))->assertUnprocessable()->assertJsonValidationErrors('quantity');

            $line->refresh();
            $this->assertSame('2.0000', $line->quantity, "quantity must stay 2 after rejecting '{$badQuantity}'");
            $this->assertSame('none', $line->discount_type->value);
        }
    }

    /** @return array<string, mixed> */
    private function lineBody(ProductVariant $variant, Warehouse $warehouse, array $overrides = []): array
    {
        return array_replace([
            'line_type' => 'catalog',
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '2',
            'discount_type' => 'none',
            'discount_value' => '0.0000',
        ], $overrides);
    }

    /** @return array{User, Organization, Store, Warehouse, ProductVariant} */
    private function discountContext(string $priceHt): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'Discount Line Product', 'DLP-1', [
            'default_sale_price' => $priceHt,
        ])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '20.0000');

        return [$owner, $organization, $store, $warehouse, $variant];
    }
}
