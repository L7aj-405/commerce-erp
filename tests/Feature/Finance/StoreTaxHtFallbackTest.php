<?php

namespace Tests\Feature\Finance;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ProductPriceResolver;
use Illuminate\Validation\ValidationException;
use Tests\Support\PosTestCase;

class StoreTaxHtFallbackTest extends PosTestCase
{
    // A — TTC 1200, no explicit HT, Store default TVA 20% -> effective HT 1000.
    public function test_ht_is_derived_from_public_ttc_using_the_store_default_tax(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->fallbackContext('1200.0000', storeRate: '20.0000');
        unset($owner, $organization, $warehouse);

        $price = $this->resolver()->resolve($variant->fresh(), $store->fresh());

        $this->assertSame('1000.0000', $price['unit_price_ht']);
        $this->assertSame('1200.0000', $price['unit_price_ttc']);
        $this->assertSame('20.0000', $price['tax_rate_value']);
        $this->assertSame('derived', $price['ht_source']);
        $this->assertSame('store', $price['tax_source']);
        $this->assertFalse($price['config_missing']);
    }

    // B — TTC 600 at 20% -> HT 500.
    public function test_ht_fallback_for_six_hundred_ttc(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->fallbackContext('600.0000', storeRate: '20.0000');
        unset($owner, $organization, $warehouse);

        $this->assertSame('500.0000', $this->resolver()->resolve($variant->fresh(), $store->fresh())['unit_price_ht']);
    }

    // C — Product explicit tax rate wins over the Store default.
    public function test_product_tax_rate_overrides_the_store_default(): void
    {
        [$owner, $organization, $store] = $this->fallbackContext('1100.0000', storeRate: '20.0000');
        unset($owner);
        $productTax = $this->createTaxRate($organization, 'TVA 10', '10.0000');
        $variant = $this->createProduct($organization, 'Overridden', 'OVR-1', [
            'public_price_ttc' => '1100.0000',
            'tax_rate_id' => $productTax->getKey(),
        ])->variants->first();

        $price = $this->resolver()->resolve($variant, $store->fresh());
        $this->assertSame('10.0000', $price['tax_rate_value']);
        $this->assertSame('product', $price['tax_source']);
        $this->assertSame('1000.0000', $price['unit_price_ht']); // 1100 / 1.10
    }

    // D — an explicitly stored HT is used verbatim, never re-derived.
    public function test_stored_ht_is_used_instead_of_the_fallback(): void
    {
        [$owner, $organization, $store] = $this->fallbackContext('1200.0000', storeRate: '20.0000');
        unset($owner);
        $variant = $this->createProduct($organization, 'Explicit HT', 'EXH-1', [
            'public_price_ttc' => '1200.0000',
            'unit_price_ht' => '950.0000',
        ])->variants->first();

        $price = $this->resolver()->resolve($variant, $store->fresh());
        $this->assertSame('950.0000', $price['unit_price_ht']);
        $this->assertSame('stored', $price['ht_source']);
    }

    // E — no HT, no product tax, no store/org default -> loud failure, not a wrong value.
    public function test_missing_all_tax_configuration_blocks_the_sale(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->fallbackContext('1200.0000', storeRate: null);
        $variant = $this->createProduct($organization, 'Unconfigured', 'UNC-1', ['public_price_ttc' => '1200.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '5.0000');

        $price = $this->resolver()->resolve($variant, $store->fresh());
        $this->assertTrue($price['config_missing']);
        $this->assertNull($price['unit_price_ht']);

        $order = $this->createDraftOrder($owner, $organization, $store);
        try {
            $this->addCatalogLine($owner, $order, $variant, $warehouse);
            $this->fail('Expected a configuration failure when no tax rate can be resolved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('product_variant_id', $exception->errors());
            $this->assertStringContainsString('aucune taxe par défaut', $exception->errors()['product_variant_id'][0]);
        }
        $this->assertDatabaseCount('sales_order_lines', 0);
    }

    // F — the Order line snapshots the derived HT / tax at sale time and never changes.
    public function test_order_line_snapshot_is_immune_to_later_store_tax_changes(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->fallbackContext('1200.0000', storeRate: '20.0000');
        $variant = $this->createProduct($organization, 'Snapshot TTC', 'SNP-1', ['public_price_ttc' => '1200.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');

        $order = $this->createDraftOrder($owner, $organization, $store);
        $line = $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '2']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();

        // The store default tax later drops to 10%.
        $newRate = $this->createTaxRate($organization, 'TVA 10 later', '10.0000');
        $store->update(['default_tax_rate_id' => $newRate->getKey()]);

        $line->refresh();
        $this->assertSame('1000.0000', $line->unit_price_excl_tax);
        $this->assertSame('1200.0000', $line->unit_price_incl_tax);
        $this->assertSame('20.0000', $line->tax_rate);
        $this->assertSame('2000.0000', $line->subtotal_excl_tax);
        $this->assertSame('400.0000', $line->tax_amount);
        $this->assertSame('2400.0000', $line->total_incl_tax);
        $this->assertSame('2400.0000', $order->refresh()->total_incl_tax);
    }

    // G — quantity math on the derived values.
    public function test_quantity_three_at_derived_ht(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->fallbackContext('1200.0000', storeRate: '20.0000');
        $variant = $this->createProduct($organization, 'Qty TTC', 'QTY-1', ['public_price_ttc' => '1200.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');

        $order = $this->createDraftOrder($owner, $organization, $store);
        $line = $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '3']);

        $this->assertSame('3000.0000', $line->subtotal_excl_tax);
        $this->assertSame('600.0000', $line->tax_amount);
        $this->assertSame('3600.0000', $line->total_incl_tax);
    }

    // H — a legacy TTC-only import becomes sellable once a Store default tax exists.
    public function test_legacy_ttc_only_import_is_sellable_after_configuring_store_tax(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->fallbackContext('1200.0000', storeRate: null);
        $variant = $this->createProduct($organization, 'Legacy Import', 'LEG-1', ['default_sale_price' => '1200.0000'])->variants->first();
        // Simulate the real legacy shape: only the public price stored.
        $variant->forceFill(['unit_price_ht' => null, 'public_price_ttc' => null])->save();
        $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');

        $blocked = $this->createDraftOrder($owner, $organization, $store);
        try {
            $this->addCatalogLine($owner, $blocked, $variant->fresh(), $warehouse);
            $this->fail('Legacy TTC-only product should not be sellable without a tax configuration.');
        } catch (ValidationException) {
            // expected
        }

        $rate = $this->createTaxRate($organization, 'TVA 20 config', '20.0000');
        $store->update(['default_tax_rate_id' => $rate->getKey()]);

        $order = $this->createDraftOrder($owner, $organization, $store);
        $line = $this->addCatalogLine($owner, $order, $variant->fresh(), $warehouse);
        $this->assertSame('1000.0000', $line->unit_price_excl_tax);
        $this->assertSame('1200.0000', $line->total_incl_tax);
    }

    private function resolver(): ProductPriceResolver
    {
        return app(ProductPriceResolver::class);
    }

    /**
     * @return array{User, Organization, Store, Warehouse, ProductVariant}
     */
    private function fallbackContext(string $publicTtc, ?string $storeRate): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);

        if ($storeRate !== null) {
            $rate = $this->createTaxRate($organization, "Store TVA {$storeRate}", $storeRate);
            $store->update(['default_tax_rate_id' => $rate->getKey()]);
        }

        $variant = $this->createProduct($organization, 'Fallback Product', 'FBK-1', [
            'public_price_ttc' => $publicTtc,
        ])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, '20.0000');

        return [$owner, $organization, $store, $warehouse, $variant];
    }
}
