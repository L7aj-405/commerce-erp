<?php

namespace Tests\Feature\Sandbox;

use App\Http\Controllers\PosController;
use App\Models\DeliveryNote;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Sandbox\SandboxInventoryGuard;
use App\Support\Decimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\QuotationTestCase;

class SandboxInventoryTest extends QuotationTestCase
{
    public function test_test_stock_is_snapshotted_once_and_then_diverges_from_production(): void
    {
        [$owner, $org, , $variant, $main] = $this->fixture();
        $this->enterTest();
        $sandboxId = (int) DB::table('sandboxes')->where('organization_id', $org->id)->value('id');
        $this->assertNotNull(DB::table('sandboxes')->where('id', $sandboxId)->value('inventory_initialized_at'));
        $this->assertSame('5.0000', $this->sandboxBalance($sandboxId, $main, $variant)->on_hand);
        $this->assertSame('0.0000', $this->sandboxBalance($sandboxId, $main, $variant)->reserved);
        $this->assertSame(1, DB::table('sandbox_inventory_movements')->where('sandbox_id', $sandboxId)
            ->where('reference_type', 'sandbox_initialization')->where('quantity', 5)->count());

        // Production moves after the snapshot: the Test balance is never rewritten.
        $this->leaveTest();
        $this->post('/inventory/adjustments', ['warehouse_id' => $main->id, 'product_variant_id' => $variant->id,
            'type' => 'adjustment_in', 'quantity' => '3', 'reason' => 'Réception réelle'])->assertRedirect();
        $this->enterTest();
        $this->assertSame('5.0000', InventoryBalance::query()->where('warehouse_id', $main->id)->where('product_variant_id', $variant->id)->value('on_hand'));
        $this->leaveTest();
        $this->assertSame('8.0000', InventoryBalance::query()->where('warehouse_id', $main->id)->where('product_variant_id', $variant->id)->value('on_hand'));
        $this->assertSame(1, DB::table('sandbox_inventory_movements')->where('sandbox_id', $sandboxId)->count());
    }

    public function test_test_reservations_stock_in_out_and_transfers_only_touch_the_sandbox_ledger(): void
    {
        [$owner, $org, , $variant, $main, $second] = $this->fixture();
        $production = $this->productionLedger();
        $this->enterTest();
        $sandboxId = (int) DB::table('sandboxes')->where('organization_id', $org->id)->value('id');

        $this->post('/inventory/reservations', ['warehouse_id' => $main->id, 'product_variant_id' => $variant->id, 'quantity' => '2'])->assertRedirect();
        $reservation = InventoryReservation::query()->firstOrFail();
        $this->assertSame('sandbox_inventory_reservations', $reservation->getTable());
        $this->assertSame('2.0000', $this->sandboxBalance($sandboxId, $main, $variant)->reserved);
        $this->post("/inventory/reservations/{$reservation->id}/release")->assertRedirect();
        $this->assertSame('released', $reservation->fresh()->status->value);
        $this->assertSame('0.0000', $this->sandboxBalance($sandboxId, $main, $variant)->reserved);

        $this->post('/inventory/adjustments', ['warehouse_id' => $main->id, 'product_variant_id' => $variant->id, 'type' => 'adjustment_out', 'quantity' => '1', 'reason' => 'Casse test'])->assertRedirect();
        $this->assertSame('4.0000', $this->sandboxBalance($sandboxId, $main, $variant)->on_hand);
        $this->post('/inventory/adjustments', ['warehouse_id' => $main->id, 'product_variant_id' => $variant->id, 'type' => 'adjustment_in', 'quantity' => '10', 'reason' => 'Arrivage test'])->assertRedirect();
        $this->assertSame('14.0000', $this->sandboxBalance($sandboxId, $main, $variant)->on_hand);

        $this->post('/inventory/transfers', ['source_warehouse_id' => $main->id, 'destination_warehouse_id' => $second->id,
            'reason' => 'Transfert test', 'lines' => [['product_variant_id' => $variant->id, 'quantity' => 4]]])->assertRedirect();
        $this->assertSame('10.0000', $this->sandboxBalance($sandboxId, $main, $variant)->on_hand);
        $this->assertSame('4.0000', $this->sandboxBalance($sandboxId, $second, $variant)->on_hand);
        $transfer = DB::table('sandbox_stock_transfers')->where('sandbox_id', $sandboxId)->first();
        $this->assertSame('TEST-TRF-000001', $transfer->transfer_number);
        $this->get("/inventory/transfers/{$transfer->id}")->assertOk();

        $this->post('/inventory/reservations', ['warehouse_id' => $second->id, 'product_variant_id' => $variant->id, 'quantity' => '1'])->assertRedirect();
        $consumable = InventoryReservation::query()->where('status', 'active')->firstOrFail();
        $this->post("/inventory/reservations/{$consumable->id}/consume")->assertRedirect();
        $this->assertSame('3.0000', $this->sandboxBalance($sandboxId, $second, $variant)->on_hand);

        $this->get('/inventory/stock')->assertOk()->assertInertia(fn (Assert $p) => $p->where('sandbox.active', true)
            ->where('balances.data.0.summary.on_hand', '13.0000'));
        $this->get('/inventory/movements')->assertOk();

        // Test ledger is immutable like Production.
        $movement = InventoryMovement::query()->latest('id')->firstOrFail();
        $this->assertSame('sandbox_inventory_movements', $movement->getTable());
        foreach ([fn () => $movement->forceFill(['reason' => 'x'])->save(), fn () => $movement->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('Test inventory movement mutated.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }

        // Raw SQL on Production inventory tables is refused in Test Mode.
        try {
            DB::table('inventory_balances')->count();
            $this->fail('Production inventory table readable in Test Mode.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('inventory_balances', $e->getMessage());
        }

        $this->leaveTest();
        $this->assertEquals($production, $this->productionLedger());
        $this->assertSame('5.0000', $this->balance($org, $main, $variant)->on_hand);
        $this->assertSame('0.0000', $this->balance($org, $main, $variant)->reserved);
        $this->get('/inventory/stock')->assertOk()->assertInertia(fn (Assert $p) => $p->where('balances.data.0.summary.on_hand', '5.0000'));
    }

    public function test_pos_availability_allocations_and_confirmation_use_test_stock(): void
    {
        [$owner, $org, $store, $variant, $main] = $this->fixture();
        $this->reserve($owner, $org, $main, $variant, '1.0000'); // Production available = 4
        $terminal = (string) Str::uuid();
        $this->withCredentials()->withCookie(PosController::TERMINAL_COOKIE, $terminal);
        $this->getJson('/pos/products?warehouse_id='.$main->id)->assertOk()->assertJsonPath('data.0.stock.available', '4.0000');
        $production = $this->productionLedger();

        $this->enterTest(); // Test available = 5 (on_hand snapshot, no Production reservations)
        $this->getJson('/pos/products?warehouse_id='.$main->id)->assertOk()
            ->assertJsonPath('data.0.stock.available', '5.0000')->assertJsonPath('data.0.stock.total_available', '5.0000');
        $this->post('/inventory/adjustments', ['warehouse_id' => $main->id, 'product_variant_id' => $variant->id, 'type' => 'adjustment_out', 'quantity' => '2', 'reason' => 'Test'])->assertRedirect();
        $this->getJson('/pos/products?warehouse_id='.$main->id)->assertOk()->assertJsonPath('data.0.stock.available', '3.0000');

        $cart = $this->postJson('/pos/drafts', ['warehouse_id' => $main->id])->assertOk()->json('active_sale.id');
        $this->postJson("/pos/drafts/{$cart}/lines", ['line_type' => 'catalog', 'product_variant_id' => $variant->id, 'warehouse_id' => $main->id, 'quantity' => '2', 'discount_type' => 'none', 'discount_value' => '0'])->assertOk();
        $this->assertSame(1, SalesOrderInventoryAllocation::query()->count());
        $this->assertSame('sandbox_sales_order_inventory_allocations', SalesOrderInventoryAllocation::query()->firstOrFail()->getTable());

        $order = $this->createDraftOrder($owner, $org, $store);
        $this->addCatalogLine($owner, $order, $variant, $main, ['quantity' => '2']);
        $this->post("/sales/orders/{$order->id}/confirm")->assertRedirect()->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('confirmed', $order->status->value);
        $reservation = InventoryReservation::query()->where('status', 'active')->firstOrFail();
        $this->assertSame('2.0000', $reservation->quantity);
        $this->assertSame(SalesOrderInventoryAllocation::class, $reservation->reference_type);
        $this->assertSame('2.0000', InventoryBalance::query()->where('warehouse_id', $main->id)->value('reserved'));
        $this->assertSame(0, DB::table('commission_entries')->count());
        $this->assertSame(0, DB::table('transfer_requests')->count());

        // Shortage is computed on Test stock: 3 on hand - 2 reserved = 1 < 3.
        $short = $this->createDraftOrder($owner, $org, $store);
        $this->addCatalogLine($owner, $short, $variant, $main, ['quantity' => '3']);
        $this->post("/sales/orders/{$short->id}/confirm")->assertSessionHasErrors();
        $this->assertSame('draft', $short->fresh()->status->value);

        // Phase 3 documents keep working on a confirmed Test order; the BL stays document-only.
        $this->post("/sales/orders/{$order->id}/invoices")->assertRedirect();
        $this->post("/sales/orders/{$order->id}/delivery-notes")->assertRedirect();
        $note = DeliveryNote::query()->where('sales_order_id', $order->id)->firstOrFail();
        $movementsBefore = InventoryMovement::query()->count();
        $this->post("/delivery-notes/{$note->id}/issue")->assertRedirect();
        $this->assertStringStartsWith('TEST-BL-', $note->fresh()->delivery_note_number);
        $this->assertSame($movementsBefore, InventoryMovement::query()->count());
        $this->assertSame('active', $reservation->fresh()->status->value);

        $this->leaveTest();
        $this->getJson('/pos/products?warehouse_id='.$main->id)->assertOk()->assertJsonPath('data.0.stock.available', '4.0000');
        $this->assertEquals($production, $this->productionLedger());
    }

    public function test_cross_environment_inventory_references_and_tenant_isolation(): void
    {
        [$owner, $org, $store, $variant, $main] = $this->fixture();
        $productionReservation = $this->reserve($owner, $org, $main, $variant, '1.0000');
        $productionOrder = $this->createDraftOrder($owner, $org, $store);
        $productionLine = $this->addCatalogLine($owner, $productionOrder, $variant, $main);
        $productionAllocation = SalesOrderInventoryAllocation::query()->where('sales_order_line_id', $productionLine->id)->firstOrFail();

        $this->enterTest();
        $this->assertNull(InventoryReservation::find($productionReservation->id));
        $this->post("/inventory/reservations/{$productionReservation->id}/release")->assertNotFound();
        $this->assertSame(0, SalesOrderInventoryAllocation::query()->count());

        $order = $this->createDraftOrder($owner, $org, $store);
        $line = $this->addCatalogLine($owner, $order, $variant, $main);
        $testAllocation = SalesOrderInventoryAllocation::query()->where('sales_order_line_id', $line->id)->firstOrFail();

        // Test allocation -> Production reservation: refused by the (sandbox_id, id) composite FK.
        $this->assertFalse(DB::table('sandbox_inventory_reservations')->where('id', $productionReservation->id)->exists());
        try {
            $testAllocation->inventory_reservation_id = $productionReservation->id;
            $testAllocation->save();
            $this->fail('Test allocation referenced a Production reservation.');
        } catch (QueryException) {
            $this->assertNull(DB::table('sandbox_sales_order_inventory_allocations')->where('id', $testAllocation->id)->value('inventory_reservation_id'));
        }
        // Test allocation on a Production order line: refused by the model guard.
        $this->assertHttpStatus(404, function () use ($org, $productionLine, $main) {
            $forged = new SalesOrderInventoryAllocation;
            $forged->organization_id = $org->id;
            $forged->sales_order_line_id = $productionLine->id;
            $forged->warehouse_id = $main->id;
            $forged->quantity = '1.0000';
            $forged->save();
        });
        foreach (range(1, 3) as $n) {
            $this->post('/inventory/reservations', ['warehouse_id' => $main->id, 'product_variant_id' => $variant->id, 'quantity' => '1', 'reference' => "T{$n}"])->assertRedirect();
        }
        $testOnlyReservationId = (int) DB::table('sandbox_inventory_reservations')->max('id');

        $this->leaveTest();
        $this->assertFalse(DB::table('inventory_reservations')->where('id', $testOnlyReservationId)->exists());
        $this->assertNull(InventoryReservation::find($testOnlyReservationId));
        // Production allocation -> Test reservation: refused by the Production FK.
        try {
            $productionAllocation->inventory_reservation_id = $testOnlyReservationId;
            $productionAllocation->save();
            $this->fail('Production allocation referenced a Test reservation.');
        } catch (QueryException) {
            $this->assertNull(DB::table('sales_order_inventory_allocations')->where('id', $productionAllocation->id)->value('inventory_reservation_id'));
        }
        // Production code can never write the sandbox ledger directly either.
        $sandboxRow = (new InventoryBalance)->setTable('sandbox_inventory_balances')->newQuery()->withoutGlobalScopes()->firstOrFail();
        $this->assertHttpStatus(404, fn () => $sandboxRow->forceFill(['on_hand' => '99'])->save());
        $this->assertSame('5.0000', Decimal::normalize((string) DB::table('sandbox_inventory_balances')->where('id', $sandboxRow->id)->value('on_hand')));

        // Another tenant: its own sandbox only ever sees its own Test stock.
        $other = User::factory()->create();
        $otherOrg = $this->createOrganization($other);
        $otherStore = $this->createStore($otherOrg, $other);
        $otherWarehouse = $this->createWarehouse($otherOrg, 'Other', 'OTH');
        $otherVariant = $this->createProduct($otherOrg, 'Other product', 'OTH-1')->variants->firstOrFail();
        $this->activate($other, $otherOrg, $otherStore);
        $this->openStock($other, $otherOrg, $otherWarehouse, $otherVariant, '7.0000');
        $this->actingAs($other);
        $this->enterTest();
        $this->assertSame(['7.0000'], InventoryBalance::query()->pluck('on_hand')->all());
        $this->post('/inventory/adjustments', ['warehouse_id' => $main->id, 'product_variant_id' => $variant->id, 'type' => 'adjustment_in', 'quantity' => '1', 'reason' => 'x'])
            ->assertSessionHasErrors(['warehouse_id', 'product_variant_id']);
        $this->assertSame(0, InventoryReservation::query()->count());
    }

    public function test_rollback_refuses_to_drop_the_test_inventory_layer_while_test_activity_exists(): void
    {
        [, , , $variant, $main] = $this->fixture();
        $this->enterTest();
        $this->post('/inventory/reservations', ['warehouse_id' => $main->id, 'product_variant_id' => $variant->id, 'quantity' => '1'])->assertRedirect();
        $migration = require database_path('migrations/2026_11_21_000004_create_sandbox_inventory.php');
        try {
            $migration->down();
            $this->fail('Rollback dropped the Test inventory layer with Test activity present.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Test inventory activity exists', $e->getMessage());
        }
        foreach (['sandbox_inventory_balances', 'sandbox_inventory_movements', 'sandbox_inventory_reservations',
            'sandbox_sales_order_inventory_allocations', 'sandbox_stock_transfers', 'sandbox_stock_transfer_lines'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasColumn('sandboxes', 'inventory_initialized_at'));
    }

    /** @return array{User, Organization, Store, ProductVariant, Warehouse, Warehouse} */
    private function fixture(): array
    {
        [$owner, $org, $store, $variant] = $this->quotationCatalogFixture();
        $main = $this->createWarehouse($org, 'Main', 'MAIN');
        $second = $this->createWarehouse($org, 'Second', 'SEC');
        $this->openStock($owner, $org, $main, $variant, '5.0000');
        $this->actingAs($owner);

        return [$owner, $org, $store, $variant, $main, $second];
    }

    private function enterTest(): void
    {
        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();
        $this->get('/test-mode')->assertOk(); // resolves the Test context for direct calls
    }

    private function leaveTest(): void
    {
        $this->post('/test-mode/leave')->assertRedirect();
        $this->get('/test-mode')->assertOk();
    }

    private function sandboxBalance(int $sandboxId, Warehouse $warehouse, ProductVariant $variant): object
    {
        $row = DB::table('sandbox_inventory_balances')->where('sandbox_id', $sandboxId)
            ->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->first();

        // SQLite hands raw decimals back as numbers; compare in canonical 4-dp form.
        return (object) ['on_hand' => Decimal::normalize((string) $row->on_hand), 'reserved' => Decimal::normalize((string) $row->reserved)];
    }

    /** @return array<string, mixed> Full Production inventory ledger state (read through the sanctioned bypass). */
    private function productionLedger(): array
    {
        return SandboxInventoryGuard::bypass(fn () => [
            'balances' => DB::table('inventory_balances')->orderBy('id')->get(['id', 'warehouse_id', 'product_variant_id', 'on_hand', 'reserved'])->map(fn ($r) => (array) $r)->all(),
            'movements' => DB::table('inventory_movements')->orderBy('id')->get(['id', 'movement_type', 'quantity', 'quantity_after'])->map(fn ($r) => (array) $r)->all(),
            'reservations' => DB::table('inventory_reservations')->orderBy('id')->get(['id', 'quantity', 'status'])->map(fn ($r) => (array) $r)->all(),
            'allocations' => DB::table('sales_order_inventory_allocations')->orderBy('id')->get(['id', 'quantity', 'inventory_reservation_id'])->map(fn ($r) => (array) $r)->all(),
            'transfers' => DB::table('stock_transfers')->count(),
            'transfer_sequences' => DB::table('stock_transfer_sequences')->get()->map(fn ($r) => (array) $r)->all(),
        ]);
    }

    private function assertHttpStatus(int $status, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected HTTP {$status}.");
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }
}
