<?php

namespace Tests\Feature\Sales;

use App\Actions\Quotations\ConvertQuotationToSalesOrderAction;
use App\Actions\Quotations\CreateQuotationAction;
use App\Actions\Quotations\IssueQuotationAction;
use App\Actions\Quotations\SaveQuotationLineAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\ProductVariant;
use App\Models\TaxRate;
use App\Models\User;
use Tests\Support\QuotationTestCase;

/**
 * Covers the modernised SalesOrder edit experience: the unified catalogue
 * search endpoint, server-side warehouse sourcing, HT/TTC custom pricing, and
 * that none of the UI rework weakened the Draft-only / tenant / inventory
 * guards.
 */
class SalesOrderEditUxTest extends QuotationTestCase
{
    private TaxRate $tax20;

    private ProductVariant $variant;

    /** @return array{User, \App\Models\Organization, \App\Models\Store, \App\Models\Warehouse} */
    private function base(string $stock = '25.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $this->activate($owner, $organization, $store);
        $this->tax20 = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $this->variant = $this->createProduct($organization, 'Microphone Shure MV7i', 'MV7I-1', [
            'reference' => 'REF-MV7I', 'default_sale_price' => '3780.8333', 'tax_rate_id' => $this->tax20->id,
        ])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $this->variant, $stock);

        return [$owner, $organization, $store, $warehouse];
    }

    // 1 + 2 ---------------------------------------------------------------

    public function test_edit_page_renders_modern_props_and_drops_legacy_ones(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->base();
        $order = $this->createDraftOrder($owner, $organization, $store);

        $this->actingAs($owner)->get(route('sales.orders.edit', $order))->assertInertia(fn ($page) => $page
            ->component('Sales/Orders/Edit')
            ->where('isEditable', true)
            ->where('lineSearchUrl', route('sales.orders.line-search', $order))
            ->where('customerSearchUrl', route('sales.orders.customer-search', $order))
            ->where('originatingQuotation', null)
            ->has('taxRates')
            ->missing('warehouses')
            ->missing('customers')
            ->missing('catalogResults')
            ->missing('filters'));
    }

    public function test_line_props_keep_raw_four_decimal_precision_formatting_is_a_ui_concern(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->base();
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $this->variant, $warehouse, ['quantity' => '1.0000']);

        $this->actingAs($owner)->get(route('sales.orders.edit', $order))->assertInertia(fn ($page) => $page
            ->where('order.lines.0.unit_price_excl_tax', '3780.8333')
            ->where('order.lines.0.quantity', '1.0000')
            ->where('order.lines.0.tax_rate', '20.0000'));
    }

    // 3-7  catalogue search ---------------------------------------------

    public function test_catalogue_search_matches_by_name_sku_and_reference_and_keeps_zero_stock_rows(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->base('0.0000');
        $order = $this->createDraftOrder($owner, $organization, $store);
        $url = route('sales.orders.line-search', $order);

        foreach (['Microphone', 'MV7I-1', 'REF-MV7I'] as $term) {
            $body = $this->actingAs($owner)->getJson($url.'?search='.urlencode($term))->assertOk()->json('data');
            $this->assertCount(1, $body, "search term {$term}");
            $this->assertSame($this->variant->id, $body[0]['id']);
        }

        // Stock is 0 — still listed, flagged, never hidden.
        $row = $this->actingAs($owner)->getJson($url.'?search=Microphone')->json('data.0');
        $this->assertSame('0.0000', $row['stock_available']);
        $this->assertSame('3780.8333', $row['unit_price_excl_tax']);
        $this->assertSame('20.0000', $row['tax_rate']);
    }

    // 8  select catalogue product -------------------------------------

    public function test_selecting_a_catalogue_product_adds_a_snapshotted_line_and_auto_sources_a_warehouse(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->base();
        $order = $this->createDraftOrder($owner, $organization, $store);

        // The modern editor sends no warehouse_id — sourcing is resolved server-side.
        $this->actingAs($owner)->post(route('sales.orders.lines.store', $order), [
            'line_type' => 'catalog', 'product_variant_id' => $this->variant->id,
            'quantity' => '2.0000', 'discount_type' => 'none', 'discount_value' => '0',
        ])->assertRedirect();

        $line = $order->lines()->firstOrFail();
        $this->assertSame('Microphone Shure MV7i', $line->product_name);
        $this->assertSame('3780.8333', $line->unit_price_excl_tax);
        $this->assertSame('20.0000', $line->tax_rate);
        $this->assertDatabaseHas('sales_order_inventory_allocations', [
            'sales_order_line_id' => $line->id, 'warehouse_id' => $warehouse->id, 'quantity' => 2,
        ]);
    }

    // 9-12  custom line + HT/TTC + no hardcoded 20% -------------------

    public function test_custom_line_ht_price_is_stored_verbatim(): void
    {
        [$owner, $organization, $store] = $this->base();
        $order = $this->createDraftOrder($owner, $organization, $store);

        $this->actingAs($owner)->post(route('sales.orders.lines.store', $order), [
            'line_type' => 'custom', 'description' => 'Prestation sur mesure', 'quantity' => '1.0000',
            'price_input_mode' => 'ht', 'unit_price' => '100.0000', 'tax_rate_id' => $this->tax20->id,
            'discount_type' => 'none', 'discount_value' => '0',
        ])->assertRedirect();

        $line = $order->lines()->firstOrFail();
        $this->assertSame('100.0000', $line->unit_price_excl_tax);
        $this->assertSame('20.0000', $line->tax_amount);
        $this->assertSame('120.0000', $line->total_incl_tax);
        $this->assertDatabaseMissing('sales_order_inventory_allocations', ['sales_order_line_id' => $line->id]);
    }

    public function test_custom_line_ttc_price_is_converted_to_ht_with_the_selected_rate_not_a_hardcoded_20_percent(): void
    {
        [$owner, $organization, $store] = $this->base();
        $tax7 = $this->createTaxRate($organization, 'TVA 7', '7.0000');
        $order = $this->createDraftOrder($owner, $organization, $store);

        $this->actingAs($owner)->post(route('sales.orders.lines.store', $order), [
            'line_type' => 'custom', 'description' => 'Article TTC', 'quantity' => '1.0000',
            'price_input_mode' => 'ttc', 'unit_price' => '107.0000', 'tax_rate_id' => $tax7->id,
            'discount_type' => 'none', 'discount_value' => '0',
        ])->assertRedirect();

        $line = $order->lines()->firstOrFail();
        // 107 TTC at 7% → 100 HT exactly. A hardcoded 20% would give 89.1667.
        $this->assertSame('100.0000', $line->unit_price_excl_tax);
        $this->assertSame('7.0000', $line->tax_amount);
        $this->assertSame('107.0000', $line->total_incl_tax);
    }

    public function test_custom_line_without_any_price_is_rejected_not_silently_zero(): void
    {
        [$owner, $organization, $store] = $this->base();
        $order = $this->createDraftOrder($owner, $organization, $store);

        $this->actingAs($owner)->post(route('sales.orders.lines.store', $order), [
            'line_type' => 'custom', 'description' => 'Sans prix', 'quantity' => '1.0000',
            'discount_type' => 'none', 'discount_value' => '0',
        ])->assertRedirect()->assertSessionHasErrors('unit_price');
        $this->assertSame(0, $order->lines()->count());
    }

    // 13-16  recalculation + removal --------------------------------

    public function test_quantity_price_and_discount_edits_recalculate_server_totals(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->base();
        $order = $this->createDraftOrder($owner, $organization, $store);
        $line = $this->addCustomLine($owner, $order, ['unit_price_excl_tax' => '100.0000', 'tax_rate_id' => $this->tax20->id, 'quantity' => '1.0000']);

        $this->actingAs($owner)->patch(route('sales.orders.lines.update', [$order, $line->id]), [
            'line_type' => 'custom', 'description' => 'Consulting', 'quantity' => '3.0000',
            'price_input_mode' => 'ht', 'unit_price' => '120.0000', 'tax_rate_id' => $this->tax20->id,
            'discount_type' => 'percentage', 'discount_value' => '10.0000',
        ])->assertRedirect();

        $order->refresh();
        $line->refresh();
        $this->assertSame('360.0000', $line->subtotal_excl_tax);
        $this->assertSame('36.0000', $line->discount_amount);
        $this->assertSame('324.0000', $line->taxable_amount);
        $this->assertSame('388.8000', $order->total_incl_tax);

        $this->actingAs($owner)->delete(route('sales.orders.lines.destroy', [$order, $line->id]))->assertRedirect();
        $this->assertSame(0, $order->fresh()->lines()->count());
        $this->assertSame('0.0000', $order->fresh()->total_incl_tax);
    }

    // 17  forged totals -------------------------------------------

    public function test_client_supplied_totals_never_override_the_server_snapshot(): void
    {
        [$owner, $organization, $store] = $this->base();
        $order = $this->createDraftOrder($owner, $organization, $store);

        $this->actingAs($owner)->post(route('sales.orders.lines.store', $order), [
            'line_type' => 'custom', 'description' => 'Secure', 'quantity' => '2.0000',
            'price_input_mode' => 'ht', 'unit_price' => '10.0000',
            'discount_type' => 'none', 'discount_value' => '0',
            'subtotal_excl_tax' => '9999', 'tax_amount' => '9999', 'total_incl_tax' => '9999',
        ])->assertRedirect();

        $order->refresh();
        $line = $order->lines()->firstOrFail();
        $this->assertSame('20.0000', $line->subtotal_excl_tax);
        $this->assertSame('20.0000', $order->total_incl_tax);
    }

    // 18-19  isolation + IDOR ------------------------------------

    public function test_foreign_tenant_product_is_invisible_to_search_and_rejected_on_add(): void
    {
        [$owner, $organization, $store] = $this->base();
        $order = $this->createDraftOrder($owner, $organization, $store);

        $intruderOwner = User::factory()->create();
        $otherOrg = $this->createOrganization($intruderOwner);
        $foreignVariant = $this->createProduct($otherOrg, 'Foreign Mic', 'FOREIGN-1')->variants->first();

        $hits = $this->actingAs($owner)->getJson(route('sales.orders.line-search', $order).'?search=Foreign')->assertOk()->json('data');
        $this->assertCount(0, $hits);

        $this->actingAs($owner)->post(route('sales.orders.lines.store', $order), [
            'line_type' => 'catalog', 'product_variant_id' => $foreignVariant->id,
            'quantity' => '1.0000', 'discount_type' => 'none', 'discount_value' => '0',
        ])->assertNotFound();
        $this->assertSame(0, $order->lines()->count());
    }

    public function test_line_idor_between_orders_of_the_same_tenant_is_blocked(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->base();
        $orderA = $this->createDraftOrder($owner, $organization, $store);
        $orderB = $this->createDraftOrder($owner, $organization, $store);
        $lineB = $this->addCatalogLine($owner, $orderB, $this->variant, $warehouse, ['quantity' => '1.0000']);

        $this->actingAs($owner)->patch(route('sales.orders.lines.update', [$orderA, $lineB->id]), [
            'line_type' => 'catalog', 'product_variant_id' => $this->variant->id, 'quantity' => '5.0000',
            'discount_type' => 'none', 'discount_value' => '0',
        ])->assertNotFound();
        $this->actingAs($owner)->delete(route('sales.orders.lines.destroy', [$orderA, $lineB->id]))->assertNotFound();
        $this->assertSame('1.0000', $lineB->fresh()->quantity);
    }

    public function test_foreign_warehouse_id_is_rejected_no_cross_org_sourcing(): void
    {
        [$owner, $organization, $store] = $this->base();
        $order = $this->createDraftOrder($owner, $organization, $store);
        $foreignOrg = $this->createOrganization(User::factory()->create());
        $foreignWarehouse = $this->createWarehouse($foreignOrg, 'Foreign WH');

        $this->actingAs($owner)->post(route('sales.orders.lines.store', $order), [
            'line_type' => 'catalog', 'product_variant_id' => $this->variant->id, 'warehouse_id' => $foreignWarehouse->id,
            'quantity' => '1.0000', 'discount_type' => 'none', 'discount_value' => '0',
        ])->assertNotFound();
        $this->assertSame(0, $order->lines()->count());
    }

    // 20-21  converted-from-Devis ------------------------------

    public function test_order_converted_from_a_devis_opens_in_the_editor_and_editing_it_never_touches_the_devis(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->base();

        $quotation = app(CreateQuotationAction::class)->execute($owner, $organization, $store, [
            'currency_code' => 'MAD', 'quotation_date' => now()->toDateString(),
        ]);
        app(SaveQuotationLineAction::class)->execute($owner, $quotation, [
            'line_type' => 'catalog', 'product_variant_id' => $this->variant->id, 'quantity' => '4',
            'discount_type' => 'none', 'discount_value' => '0',
        ]);
        $issued = app(IssueQuotationAction::class)->execute($owner, $quotation);
        $devisTotalBefore = $issued->total_incl_tax;
        $devisLineQtyBefore = $issued->lines()->first()->quantity;

        $order = app(ConvertQuotationToSalesOrderAction::class)->execute($owner, $issued, ['warehouse_id' => $warehouse->id])['order'];

        $this->actingAs($owner)->get(route('sales.orders.edit', $order))->assertInertia(fn ($page) => $page
            ->where('isEditable', true)
            ->where('originatingQuotation.id', $issued->id)
            ->where('originatingQuotation.number', $issued->quotation_number));

        // Modify the order line — the historical issued Devis must not move.
        $orderLine = $order->lines()->firstOrFail();
        $this->actingAs($owner)->patch(route('sales.orders.lines.update', [$order, $orderLine->id]), [
            'line_type' => 'catalog', 'product_variant_id' => $this->variant->id, 'quantity' => '9.0000',
            'discount_type' => 'none', 'discount_value' => '0',
        ])->assertRedirect();

        $this->assertSame('9.0000', $order->fresh()->lines()->first()->quantity);
        $this->assertSame($devisLineQtyBefore, $issued->fresh()->lines()->first()->quantity);
        $this->assertSame($devisTotalBefore, $issued->fresh()->total_incl_tax);
    }

    // 22-23  inventory safety + confirmed guard ---------------

    public function test_draft_line_editing_never_mutates_inventory(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->base('10.0000');
        $order = $this->createDraftOrder($owner, $organization, $store);
        $before = $this->balance($organization, $warehouse, $this->variant)->only(['on_hand', 'reserved']);
        $movementsBefore = InventoryMovement::query()->count();

        $line = $this->addCatalogLine($owner, $order, $this->variant, $warehouse, ['quantity' => '3.0000']);
        app(SaveSalesOrderLineAction::class)->execute($owner, $order->fresh(), [
            'line_type' => 'catalog', 'product_variant_id' => $this->variant->id, 'quantity' => '5.0000',
            'discount_type' => 'none', 'discount_value' => '0',
        ], $line->fresh());
        $this->actingAs($owner)->delete(route('sales.orders.lines.destroy', [$order, $line->id]))->assertRedirect();

        $this->assertSame(0, InventoryReservation::query()->count());
        $this->assertSame($movementsBefore, InventoryMovement::query()->count());
        $this->assertEquals($before, $this->balance($organization, $warehouse, $this->variant)->only(['on_hand', 'reserved']));
    }

    public function test_confirmed_order_is_not_editable_and_line_mutation_is_refused(): void
    {
        [$owner, $organization, $store, $warehouse] = $this->base('20.0000');
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addCatalogLine($owner, $order, $this->variant, $warehouse, ['quantity' => '2.0000']);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());

        $this->actingAs($owner)->get(route('sales.orders.edit', $order))
            ->assertInertia(fn ($page) => $page->where('isEditable', false));

        $this->actingAs($owner)->post(route('sales.orders.lines.store', $order), [
            'line_type' => 'custom', 'description' => 'Late', 'quantity' => '1.0000',
            'price_input_mode' => 'ht', 'unit_price' => '10.0000', 'discount_type' => 'none', 'discount_value' => '0',
        ])->assertRedirect()->assertSessionHasErrors('order');

        $this->assertSame(1, $order->fresh()->lines()->count());
    }
}
