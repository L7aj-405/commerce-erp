<?php

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\UpdateVariantAction;
use App\Models\User;
use Tests\Support\SalesTestCase;

class ProductPricingSnapshotTest extends SalesTestCase
{
    public function test_catalog_price_changes_do_not_rewrite_historical_sales_line_snapshots(): void
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $product = $this->createProduct($organization, 'Snapshot Product', 'SNAP-1', [
            'regular_sale_price' => '100.0000',
            'default_sale_price' => '100.0000',
        ]);
        $variant = $product->variants->first();
        $order = $this->createDraftOrder($owner, $organization, $store);
        $line = $this->addCatalogLine($owner, $order, $variant, $warehouse);

        app(UpdateVariantAction::class)->execute($owner, $variant, [
            'label' => $variant->label,
            'sku' => $variant->sku,
            'reference' => $variant->reference,
            'barcode' => $variant->barcode,
            'purchase_price' => $variant->purchase_price,
            'regular_sale_price' => '120.0000',
            'promotional_sale_price' => '90.0000',
            'tax_rate_id' => $variant->tax_rate_id,
            'status' => $variant->status->value,
        ]);

        $this->assertSame('90.0000', $variant->fresh()->default_sale_price);
        $this->assertSame('100.0000', $line->fresh()->unit_price_excl_tax);
        $this->assertSame('100.0000', $line->fresh()->subtotal_excl_tax);
    }
}
