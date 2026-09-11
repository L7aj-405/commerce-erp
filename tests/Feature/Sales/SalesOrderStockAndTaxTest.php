<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Decimal;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\SalesTestCase;

/**
 * Final stock/tax corrections for the modern SalesOrder editor:
 *  - a Product with no resolvable tax stays selectable on a Draft, is stored
 *    with an EXPLICIT unresolved-tax marker (never a silent 0%), and blocks
 *    confirmation until a rate is chosen;
 *  - manual Draft sourcing is a provisional company-wide multi-warehouse plan
 *    that is rebuilt strictly against CURRENT availability at confirmation,
 *    so sufficient company-wide stock never causes a false confirmation
 *    failure and stale single-warehouse allocations cannot survive.
 */
class SalesOrderStockAndTaxTest extends SalesTestCase
{
    /** @return array{User, \App\Models\Organization, \App\Models\Store} */
    private function base(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $this->activate($owner, $organization, $store);

        return [$owner, $organization, $store];
    }

    private function addLine(User $owner, $order, array $body): TestResponse
    {
        return $this->actingAs($owner)->post(route('sales.orders.lines.store', $order), array_replace([
            'line_type' => 'catalog', 'quantity' => '1.0000', 'discount_type' => 'none', 'discount_value' => '0',
        ], $body));
    }

    // ---- Tax: unresolved stays selectable, never a silent 0% -------------

    public function test_missing_tax_product_is_selectable_in_search_and_added_with_an_explicit_unresolved_marker(): void
    {
        [$owner, $organization, $store] = $this->base();
        $warehouse = $this->createWarehouse($organization);
        // Public TTC price, no stored HT, no tax rate anywhere → config missing.
        $variant = $this->createProduct($organization, 'Micro Sans TVA', 'NOVAT-1', ['public_price_ttc' => '120.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '4.0000');
        $order = $this->createDraftOrder($owner, $organization, $store);

        $row = $this->actingAs($owner)->getJson(route('sales.orders.line-search', $order).'?search=Micro')->assertOk()->json('data.0');
        $this->assertTrue($row['tax_unresolved']);
        $this->assertNull($row['unit_price_excl_tax']);
        $this->assertSame('120.0000', $row['unit_price_incl_tax']);

        $this->addLine($owner, $order, ['product_variant_id' => $variant->id, 'quantity' => '1.0000'])->assertRedirect();

        $line = $order->lines()->firstOrFail();
        $this->assertTrue($line->tax_unresolved);
        $this->assertSame('0.0000', $line->tax_rate);
        $this->assertNull($line->tax_name);
        // Provisional HT = the public price — a real figure, never a silent 0.
        $this->assertSame('120.0000', $line->unit_price_excl_tax);
    }

    public function test_a_real_configured_zero_percent_tax_is_valid_and_not_flagged_unresolved(): void
    {
        [$owner, $organization, $store] = $this->base();
        $warehouse = $this->createWarehouse($organization);
        $zero = $this->createTaxRate($organization, 'Exonéré', '0.0000');
        $variant = $this->createProduct($organization, 'Produit Exonéré', 'EX-1', [
            'default_sale_price' => '100.0000', 'tax_rate_id' => $zero->id,
        ])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '5.0000');
        $order = $this->createDraftOrder($owner, $organization, $store);

        $this->addLine($owner, $order, ['product_variant_id' => $variant->id, 'quantity' => '1.0000'])->assertRedirect();

        $line = $order->lines()->firstOrFail();
        $this->assertFalse($line->tax_unresolved);
        $this->assertSame('0.0000', $line->tax_rate);
        $this->assertSame('Exonéré', $line->tax_name);
    }

    public function test_confirmation_is_blocked_while_tax_is_unresolved_then_succeeds_once_a_rate_is_chosen(): void
    {
        [$owner, $organization, $store] = $this->base();
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'Ampli TTC', 'AMP-TTC', ['public_price_ttc' => '120.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $variant, '10.0000');
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addLine($owner, $order, ['product_variant_id' => $variant->id, 'quantity' => '1.0000'])->assertRedirect();
        $line = $order->lines()->firstOrFail();
        $this->assertTrue($line->tax_unresolved);

        try {
            app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
            $this->fail('Confirmation must be blocked while a line tax is unresolved.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('TVA', collect($e->errors())->flatten()->implode(' '));
        }
        $this->assertSame('draft', $order->fresh()->status->value);
        $this->assertDatabaseCount('inventory_reservations', 0);

        $tax20 = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $this->actingAs($owner)->patch(route('sales.orders.lines.update', [$order, $line->id]), [
            'line_type' => 'catalog', 'product_variant_id' => $variant->id, 'quantity' => '1.0000',
            'tax_rate_id' => $tax20->id, 'discount_type' => 'none', 'discount_value' => '0',
        ])->assertRedirect();

        $line->refresh();
        $this->assertFalse($line->tax_unresolved);
        $this->assertSame('20.0000', $line->tax_rate);
        $this->assertSame('100.0000', $line->unit_price_excl_tax); // 120 TTC → 100 HT at 20%, not a hardcoded rate
        $this->assertSame('20.0000', $line->tax_amount);
        $this->assertSame('120.0000', $line->total_incl_tax);

        $confirmed = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $this->assertSame('confirmed', $confirmed->status->value);
    }

    // ---- Stock: company-wide multi-warehouse sourcing -------------------

    /** @return array{User, \App\Models\Organization, Warehouse, Warehouse, \App\Models\ProductVariant, \App\Models\SalesOrder} */
    private function twoWarehouseOrder(string $w1 = '2.0000', string $w2 = '3.0000', string $qty = '5.0000'): array
    {
        [$owner, $organization, $store] = $this->base();
        $warehouseA = $this->createWarehouse($organization, 'Showroom');
        $warehouseB = $this->createWarehouse($organization, 'Dépôt principal');
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'Casque', 'CASQ-1', [
            'default_sale_price' => '1000.0000', 'tax_rate_id' => $tax->id,
        ])->variants->first();
        $this->openStock($owner, $organization, $warehouseA, $variant, $w1);
        $this->openStock($owner, $organization, $warehouseB, $variant, $w2);
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addLine($owner, $order, ['product_variant_id' => $variant->id, 'quantity' => $qty])->assertRedirect();

        return [$owner, $organization, $warehouseA, $warehouseB, $variant, $order];
    }

    private function allocMap($line): array
    {
        return $line->allocations()->get()->mapWithKeys(fn (SalesOrderInventoryAllocation $a) => [$a->warehouse_id => $a->quantity])->all();
    }

    private function allocSum($line): string
    {
        return $line->allocations()->get()->reduce(fn (string $c, SalesOrderInventoryAllocation $a) => Decimal::add($c, $a->quantity), '0.0000');
    }

    public function test_requested_five_is_sourced_two_plus_three_across_two_warehouses(): void
    {
        [$owner, , $wA, $wB, , $order] = $this->twoWarehouseOrder('2.0000', '3.0000', '5.0000');
        $map = $this->allocMap($order->lines()->firstOrFail());

        $this->assertSame('2.0000', $map[$wA->id] ?? null);
        $this->assertSame('3.0000', $map[$wB->id] ?? null);
        $this->assertSame('5.0000', $this->allocSum($order->lines()->firstOrFail()));
    }

    public function test_company_wide_sufficient_stock_confirms_without_a_false_failure_and_reservations_match_allocations(): void
    {
        [$owner, $organization, $wA, $wB, $variant, $order] = $this->twoWarehouseOrder('2.0000', '3.0000', '5.0000');

        $confirmed = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $this->assertSame('confirmed', $confirmed->status->value);

        $allocations = $order->lines()->firstOrFail()->allocations()->with('inventoryReservation')->get();
        $this->assertCount(2, $allocations);
        foreach ($allocations as $allocation) {
            $this->assertNotNull($allocation->inventory_reservation_id);
            $this->assertSame($allocation->quantity, $allocation->inventoryReservation->quantity);
            $this->assertSame($allocation->warehouse_id, $allocation->inventoryReservation->warehouse_id);
        }
        $this->assertSame('2.0000', $this->balance($organization, $wA, $variant)->reserved);
        $this->assertSame('3.0000', $this->balance($organization, $wB, $variant)->reserved);
        $this->assertDatabaseCount('inventory_reservations', 2);
    }

    public function test_no_stock_transfer_is_created_by_a_multi_warehouse_confirmation(): void
    {
        [$owner, , , , , $order] = $this->twoWarehouseOrder('2.0000', '3.0000', '5.0000');
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $this->assertDatabaseCount('stock_transfers', 0);
    }

    public function test_allocation_never_crosses_organization(): void
    {
        [$owner, $organization, $wA, $wB, , $order] = $this->twoWarehouseOrder('2.0000', '3.0000', '5.0000');

        $foreignOwner = User::factory()->create();
        $foreignOrg = $this->createOrganization($foreignOwner);
        $foreignWarehouse = $this->createWarehouse($foreignOrg, 'Foreign');
        $foreignVariant = $this->createProduct($foreignOrg, 'Casque', 'CASQ-X')->variants->first();
        $this->openStock($foreignOwner, $foreignOrg, $foreignWarehouse, $foreignVariant, '100.0000');

        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());

        $warehouseIds = $order->lines()->firstOrFail()->allocations()->pluck('warehouse_id')->all();
        $this->assertEqualsCanonicalizing([$wA->id, $wB->id], $warehouseIds);
        foreach ($warehouseIds as $id) {
            $this->assertSame($organization->id, Warehouse::findOrFail($id)->organization_id);
        }
    }

    public function test_quantity_change_rebuilds_a_stale_single_warehouse_allocation(): void
    {
        [$owner, , $wA, $wB, $variant, $order] = $this->twoWarehouseOrder('2.0000', '3.0000', '2.0000');
        $line = $order->lines()->firstOrFail();
        $this->assertSame('2.0000', $this->allocSum($line));

        $this->actingAs($owner)->patch(route('sales.orders.lines.update', [$order, $line->id]), [
            'line_type' => 'catalog', 'product_variant_id' => $variant->id, 'quantity' => '7.0000',
            'discount_type' => 'none', 'discount_value' => '0',
        ])->assertRedirect();

        $line->refresh();
        $this->assertSame('7.0000', $this->allocSum($line), 'stale allocation must follow the new quantity');
        $map = $this->allocMap($line);
        // 2 in A + 3 in B is real stock; the unsourced remainder is parked on the
        // preferred warehouse so the plan still covers the full 7.
        $this->assertSame('2.0000', $map[$wA->id] ?? null);
        $this->assertSame('5.0000', $map[$wB->id] ?? null);
    }

    public function test_product_replacement_clears_the_old_allocation_and_rebuilds_for_the_new_variant(): void
    {
        [$owner, $organization, $store] = $this->base();
        $wA = $this->createWarehouse($organization, 'A');
        $wB = $this->createWarehouse($organization, 'B');
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variantA = $this->createProduct($organization, 'Variant A', 'VA-1', ['default_sale_price' => '100.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $variantB = $this->createProduct($organization, 'Variant B', 'VB-1', ['default_sale_price' => '100.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->openStock($owner, $organization, $wA, $variantA, '5.0000');
        $this->openStock($owner, $organization, $wB, $variantB, '4.0000');
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addLine($owner, $order, ['product_variant_id' => $variantA->id, 'quantity' => '3.0000'])->assertRedirect();
        $line = $order->lines()->firstOrFail();
        $this->assertSame([$wA->id], array_keys($this->allocMap($line)));

        $this->actingAs($owner)->patch(route('sales.orders.lines.update', [$order, $line->id]), [
            'line_type' => 'catalog', 'product_variant_id' => $variantB->id, 'quantity' => '3.0000',
            'discount_type' => 'none', 'discount_value' => '0',
        ])->assertRedirect();

        $line->refresh();
        $this->assertSame($variantB->id, $line->product_variant_id);
        $map = $this->allocMap($line);
        $this->assertArrayNotHasKey($wA->id, $map, 'the old variant A warehouse allocation must not survive');
        $this->assertSame('3.0000', $map[$wB->id] ?? null);
    }

    public function test_confirmation_reallocates_against_current_availability_not_the_stale_draft_plan(): void
    {
        [$owner, $organization, $store] = $this->base();
        $wA = $this->createWarehouse($organization, 'A');
        $wB = $this->createWarehouse($organization, 'B');
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'Mixed', 'MIX-1', ['default_sale_price' => '100.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->openStock($owner, $organization, $wA, $variant, '5.0000');
        $this->openStock($owner, $organization, $wB, $variant, '5.0000');
        $order = $this->createDraftOrder($owner, $organization, $store);
        $this->addLine($owner, $order, ['product_variant_id' => $variant->id, 'quantity' => '3.0000'])->assertRedirect();
        $line = $order->lines()->firstOrFail();
        // Draft plan sourced everything from one warehouse.
        $this->assertCount(1, $line->allocations()->get());

        // Availability at that warehouse drops after the Draft was built.
        $stale = array_key_first($this->allocMap($line));
        $other = $stale === $wA->id ? $wB : $wA;
        $staleWarehouse = Warehouse::findOrFail($stale);
        $this->reserve($owner, $organization, $staleWarehouse, $variant, '4.0000');

        $confirmed = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $this->assertSame('confirmed', $confirmed->status->value);

        $line->refresh();
        $map = $this->allocMap($line);
        $this->assertSame('1.0000', $map[$stale] ?? null, 'confirmation used the CURRENT available 1, not the stale 3');
        $this->assertSame('2.0000', $map[$other->id] ?? null);
        $this->assertSame('5.0000', $this->balance($organization, $staleWarehouse, $variant)->reserved);
        $this->assertSame('2.0000', $this->balance($organization, $other, $variant)->reserved);
        $this->assertCount(2, $line->allocations()->whereNotNull('inventory_reservation_id')->get());
    }

    public function test_confirmed_order_stays_non_editable_in_this_editor(): void
    {
        [$owner, , , , $variant, $order] = $this->twoWarehouseOrder('5.0000', '5.0000', '3.0000');
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());

        $this->expectException(ValidationException::class);
        app(SaveSalesOrderLineAction::class)->execute($owner, $order->fresh(), [
            'line_type' => 'catalog', 'product_variant_id' => $variant->id, 'quantity' => '9.0000',
            'discount_type' => 'none', 'discount_value' => '0',
        ], $order->lines()->firstOrFail());
    }
}
