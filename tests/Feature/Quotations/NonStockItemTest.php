<?php

namespace Tests\Feature\Quotations;

use App\Actions\Catalog\CreateNonStockItemAction;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\NonStockItem;
use App\Models\User;
use Tests\Support\QuotationTestCase;

class NonStockItemTest extends QuotationTestCase
{
    /** @return array{User, \App\Models\Organization, \App\Models\Store} */
    private function tenant(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);

        return [$owner, $organization, $store];
    }

    public function test_a_new_non_stock_article_is_persisted_to_the_reusable_library(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $quotation = $this->createQuotation($owner, $organization, $store);

        $line = $this->addNonStockQuotationLine($owner, $quotation, [
            'name' => 'Projecteur XYZ', 'reference' => 'PRJ-XYZ', 'unit_label' => 'pièce',
            'tax_rate_id' => $tax->id, 'price_input_mode' => 'ttc', 'unit_price' => '1200', 'quantity' => '2',
        ]);

        $item = NonStockItem::query()->where('organization_id', $organization->id)->where('name', 'Projecteur XYZ')->firstOrFail();
        $this->assertSame((int) $item->getKey(), (int) $line->non_stock_item_id);
        $this->assertSame('1000.0000', $item->default_price_excl_tax); // 1200 / 1.2
        $this->assertSame('1200.0000', $item->default_price_incl_tax);
        $this->assertSame('20.0000', $item->tax_rate);
        $this->assertSame(1, $item->usage_count);

        // The line itself derived HT server-side.
        $this->assertSame('1000.0000', $line->unit_price_excl_tax);
        $this->assertSame('2400.0000', $line->total_incl_tax);
        $this->assertDatabaseHas('audit_logs', ['event' => 'non_stock_item.created']);
    }

    public function test_a_library_item_is_reused_on_another_devis_without_re_creation(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $item = app(CreateNonStockItemAction::class)->execute($owner, $organization, [
            'name' => 'Câble HDMI 10m', 'tax_rate_id' => $tax->id, 'price_input_mode' => 'ht', 'unit_price' => '250',
        ]);

        $q1 = $this->createQuotation($owner, $organization, $store);
        $this->addNonStockQuotationLine($owner, $q1, ['non_stock_item_id' => $item->id, 'name' => 'ignored', 'quantity' => '2']);
        $q2 = $this->createQuotation($owner, $organization, $store);
        $line2 = $this->addNonStockQuotationLine($owner, $q2, ['non_stock_item_id' => $item->id, 'name' => 'ignored', 'quantity' => '3']);

        $this->assertSame(1, NonStockItem::query()->where('organization_id', $organization->id)->where('name', 'Câble HDMI 10m')->count());
        $this->assertSame('250.0000', $line2->unit_price_excl_tax);
        $this->assertSame(2, $item->fresh()->usage_count);
    }

    public function test_non_stock_items_create_no_inventory_records_whatsoever(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $movementsBefore = InventoryMovement::query()->count();
        $reservationsBefore = InventoryReservation::query()->count();
        $balancesBefore = InventoryBalance::query()->count();

        $quotation = $this->createQuotation($owner, $organization, $store);
        $this->addNonStockQuotationLine($owner, $quotation, ['name' => 'Prestation montage', 'tax_rate_id' => $tax->id, 'quantity' => '5']);
        $this->issueQuotation($owner, $quotation);

        $this->assertSame($movementsBefore, InventoryMovement::query()->count());
        $this->assertSame($reservationsBefore, InventoryReservation::query()->count());
        $this->assertSame($balancesBefore, InventoryBalance::query()->count());
        $this->assertSame(0, InventoryBalance::query()->whereNull('product_variant_id')->count());
    }

    public function test_unified_search_is_tenant_isolated_and_labels_both_sources(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'SM7B', 'SM7B', ['default_sale_price' => '3500.0000', 'tax_rate_id' => $tax->id])->variants->first();
        app(CreateNonStockItemAction::class)->execute($owner, $organization, ['name' => 'Projecteur XYZ', 'tax_rate_id' => $tax->id, 'unit_price' => '900']);
        $quotation = $this->createQuotation($owner, $organization, $store);

        // Another tenant's catalogue must not leak.
        $outsider = User::factory()->create();
        $otherOrg = $this->createOrganization($outsider);
        $this->createProduct($otherOrg, 'SECRET', 'SECRET-1', ['default_sale_price' => '10.0000']);

        $data = $this->actingAs($owner)->getJson(route('quotations.search', $quotation).'?search=')->assertOk()->json('data');
        $kinds = collect($data)->pluck('kind')->unique()->sort()->values()->all();
        $names = collect($data)->pluck('product_name')->all();

        $this->assertEqualsCanonicalizing(['catalog', 'non_stock'], $kinds);
        $this->assertContains('SM7B', $names);
        $this->assertContains('Projecteur XYZ', $names);
        $this->assertNotContains('SECRET', $names);
    }

    public function test_csv_export_lists_articles_without_any_stock_quantities(): void
    {
        [$owner, $organization, $store] = $this->tenant();
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        app(CreateNonStockItemAction::class)->execute($owner, $organization, ['name' => 'Projecteur XYZ', 'reference' => 'PRJ', 'tax_rate_id' => $tax->id, 'unit_price' => '1000']);

        $csv = $this->actingAs($owner)->get(route('catalog.non-stock-items.export'));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('Projecteur XYZ', $body);
        $this->assertStringContainsString('Devis (utilisations)', $body);
        $this->assertStringNotContainsString('stock', strtolower($body));
        $this->assertStringNotContainsString('on_hand', $body);
    }
}
