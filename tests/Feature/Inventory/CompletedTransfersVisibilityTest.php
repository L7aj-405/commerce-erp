<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\CreateStockTransferAction;
use App\Actions\Inventory\PlanShowroomReplenishmentAction;
use App\Actions\Inventory\PrepareTransferRequestAction;
use App\Actions\Inventory\ReceiveTransferRequestAction;
use App\Actions\Inventory\ShipTransferRequestAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\TransferRequest;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentSetting;
use App\Services\OrganizationDataReset\Selective\SelectiveResetCategoryRegistry;
use App\Services\OrganizationDataReset\Selective\SelectiveResetExecutor;
use App\Services\OrganizationDataReset\Selective\SelectiveResetPlanner;
use App\Services\OrganizationDataReset\Selective\SelectiveResetSelection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\PosTestCase;

/**
 * "Transferts effectués" must list every completed physical transfer, from
 * any origin, with its reference, route, products, quantities, date and —
 * when there is one — the originating request and customer order.
 */
class CompletedTransfersVisibilityTest extends PosTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private Warehouse $showroom;

    private Warehouse $sp;

    private ProductVariant $beta;

    private function world(): void
    {
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->org, $this->owner);
        $this->showroom = $this->createWarehouse($this->org, 'Showroom');
        $this->sp = $this->createWarehouse($this->org, 'Entrepôt SP');
        $tax = $this->createTaxRate($this->org, 'TVA 20', '20.0000');
        $this->activate($this->owner, $this->org, $this->store);
        $this->beta = $this->createProduct($this->org, 'Shure BETA 58A', 'BETA58A', ['default_sale_price' => '100.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->openStock($this->owner, $this->org, $this->showroom, $this->beta, '1.0000');
        $this->openStock($this->owner, $this->org, $this->sp, $this->beta, '10.0000');
        $setting = new WarehouseReplenishmentSetting;
        $setting->forceFill(['organization_id' => $this->org->id, 'warehouse_id' => $this->showroom->id, 'auto_replenish' => true, 'default_minimum_quantity' => '2.0000'])->save();
    }

    private function receive(TransferRequest $request): StockTransfer
    {
        app(PrepareTransferRequestAction::class)->execute($this->owner, $request->fresh());
        app(ShipTransferRequestAction::class)->execute($this->owner, $request->fresh());
        app(ReceiveTransferRequestAction::class)->execute($this->owner, $request->fresh());

        return StockTransfer::query()->findOrFail($request->fresh()->stock_transfer_id);
    }

    /** The customer-order case of the live report: SO 3 → 2 for the customer + 2 replenishment = TRF of 4. */
    private function orderTransfer(): array
    {
        app(PlanShowroomReplenishmentAction::class)->execute($this->owner, $this->org, $this->showroom);
        $draft = $this->createPosDraft($this->owner, $this->org, $this->store, $this->showroom);
        $this->addCatalogLine($this->owner, $draft, $this->beta, $this->showroom, ['quantity' => '3.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($this->owner, $draft->fresh());
        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();

        return [$order, $request, $this->receive($request)];
    }

    private function movements(string $number): int
    {
        return DB::table('inventory_movements')->where('organization_id', $this->org->id)->where('reference', $number)->count();
    }

    public function test_every_completed_transfer_is_listed_with_its_origin(): void
    {
        $this->world();
        [$order, $request, $fromOrder] = $this->orderTransfer();
        $manual = app(CreateStockTransferAction::class)->execute($this->owner, $this->org, $this->sp, $this->showroom,
            [['variant' => $this->beta, 'quantity' => '1']], 'Transfert manuel');

        $this->assertSame('4.0000', $fromOrder->unit_count);
        $this->assertSame(2, $this->movements($fromOrder->transfer_number), 'One out + one in, never duplicated.');
        $this->assertSame(2, StockTransfer::query()->where('organization_id', $this->org->id)->count());

        $props = $this->actingAs($this->owner)->get(route('inventory.transfers.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Inventory/Transfers/Index')->has('transfers.data', 2))
            ->viewData('page')['props'];
        $rows = collect($props['transfers']['data'])->keyBy('transfer_number');

        $row = $rows[$fromOrder->transfer_number];
        $this->assertSame(['Entrepôt SP', 'Showroom', '4.0000'], [$row['source_warehouse']['name'], $row['destination_warehouse']['name'], $row['unit_count']]);
        $this->assertSame([['product_name' => 'Shure BETA 58A', 'variant_label' => $this->beta->label, 'quantity' => '4.0000']], $row['lines']);
        $this->assertSame([['id' => $request->id, 'request_number' => $request->request_number, 'sales_order_id' => $order->id, 'sales_order_number' => $order->order_number]], $row['transfer_requests']);
        $this->assertNotNull($row['transferred_at']);

        $this->assertSame([], $rows[$manual->transfer_number]['transfer_requests'], 'A direct transfer has no request.');
        $this->assertSame('1.0000', $rows[$manual->transfer_number]['unit_count']);
    }

    public function test_a_general_replenishment_request_transfer_is_listed(): void
    {
        $this->world();
        $ids = app(PlanShowroomReplenishmentAction::class)->execute($this->owner, $this->org, $this->showroom);
        $transfer = $this->receive(TransferRequest::query()->findOrFail($ids[0]));

        $this->actingAs($this->owner)->get(route('inventory.transfers.index', ['search' => $transfer->transfer_number]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('transfers.data', 1)
                ->where('transfers.data.0.transfer_requests.0.sales_order_number', null));
    }

    public function test_another_organization_never_sees_these_transfers(): void
    {
        $this->world();
        $this->orderTransfer();
        $intruder = User::factory()->create();
        $other = $this->createOrganization($intruder, 'Autre');
        $this->activate($intruder, $other, $this->createStore($other, $intruder));

        $this->actingAs($intruder)->get(route('inventory.transfers.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('transfers.data', 0));
    }

    // === Root cause: selective reset removed the transfer, kept its movements ===

    public function test_selective_reset_of_transfer_requests_keeps_completed_transfers(): void
    {
        $this->world();
        [, $request, $transfer] = $this->orderTransfer();
        $selection = SelectiveResetSelection::fromArray(['categories' => ['sales_orders', 'transfers']], app(SelectiveResetCategoryRegistry::class));
        $plan = app(SelectiveResetPlanner::class)->plan($this->org, $selection);
        $this->assertTrue($plan->isExecutable(), json_encode($plan->blockers));
        $this->assertArrayNotHasKey('stock_transfers', $plan->delete, 'A completed physical transfer is stock history.');
        $this->assertArrayNotHasKey('stock_transfer_lines', $plan->delete);

        app(SelectiveResetExecutor::class)->execute($this->org, $this->owner, $selection, $plan->fingerprint);

        $this->assertDatabaseMissing('transfer_requests', ['id' => $request->id]);
        $this->assertDatabaseHas('stock_transfers', ['id' => $transfer->id, 'transfer_number' => $transfer->transfer_number]);
        $this->assertSame(1, DB::table('stock_transfer_lines')->where('stock_transfer_id', $transfer->id)->count());
        $this->assertSame(2, $this->movements($transfer->transfer_number));
        $this->actingAs($this->owner)->get(route('inventory.transfers.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('transfers.data', 1)
                ->where('transfers.data.0.transfer_number', $transfer->transfer_number)
                ->where('transfers.data.0.transfer_requests', []));
    }

    // === Historical records (TRF-000004): explicit rebuild from the ledger ===

    public function test_historical_transfer_whose_record_was_removed_is_rebuilt_from_its_movements(): void
    {
        $this->world();
        [, $request, $transfer] = $this->orderTransfer();
        $number = $transfer->transfer_number;
        $at = $transfer->transferred_at->toDateTimeString();
        $onHand = DB::table('inventory_balances')->where('organization_id', $this->org->id)->orderBy('id')->pluck('on_hand')->all();
        // Reproduce the live state: record + lines gone, ledger movements kept.
        DB::table('transfer_requests')->where('stock_transfer_id', $transfer->id)->update(['stock_transfer_id' => null]);
        DB::table('stock_transfer_lines')->where('stock_transfer_id', $transfer->id)->delete();
        DB::table('stock_transfers')->where('id', $transfer->id)->delete();
        $this->actingAs($this->owner)->get(route('inventory.transfers.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('transfers.data', 0));

        // Preview: nothing written.
        $this->artisan('inventory:restore-stock-transfers', ['--organization' => $this->org->id, '--actor' => $this->owner->email])
            ->expectsOutputToContain($number)->assertSuccessful();
        $this->assertSame(0, StockTransfer::query()->where('organization_id', $this->org->id)->count());

        $this->artisan('inventory:restore-stock-transfers', ['--organization' => $this->org->id, '--actor' => $this->owner->email, '--apply' => true])
            ->assertSuccessful();
        $restored = StockTransfer::query()->where('organization_id', $this->org->id)->where('transfer_number', $number)->firstOrFail();
        $this->assertSame([$this->sp->id, $this->showroom->id, 'completed', '4.0000', 1], [
            (int) $restored->source_warehouse_id, (int) $restored->destination_warehouse_id, $restored->status, $restored->unit_count, (int) $restored->product_count,
        ]);
        $this->assertSame($at, $restored->transferred_at->toDateTimeString());
        // Read through the model: the quantity contract is the `decimal:4` cast. A raw
        // DB::table() read is driver-dependent (SQLite NUMERIC affinity returns '4').
        $line = $restored->lines()->sole();
        $this->assertSame('4.0000', $line->quantity);
        $this->assertSame(2, $this->movements($number), 'No movement added.');
        $this->assertSame($onHand, DB::table('inventory_balances')->where('organization_id', $this->org->id)->orderBy('id')->pluck('on_hand')->all(), 'No quantity changed.');
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $this->org->id, 'event' => 'stock_transfer.restored_from_ledger', 'auditable_id' => $restored->id]);
        // The request still exists and its receipt audit names this TRF: link restored.
        $this->assertSame($restored->id, (int) $request->fresh()->stock_transfer_id);

        // Idempotent: a second run restores nothing more.
        $this->artisan('inventory:restore-stock-transfers', ['--organization' => $this->org->id, '--actor' => $this->owner->email, '--apply' => true])->assertSuccessful();
        $this->assertSame(1, StockTransfer::query()->where('organization_id', $this->org->id)->count());
    }

    public function test_rebuild_refuses_inconsistent_ledger_groups_and_ignores_other_tenants(): void
    {
        $this->world();
        [, , $transfer] = $this->orderTransfer();
        DB::table('transfer_requests')->where('stock_transfer_id', $transfer->id)->update(['stock_transfer_id' => null]);
        DB::table('stock_transfer_lines')->where('stock_transfer_id', $transfer->id)->delete();
        DB::table('stock_transfers')->where('id', $transfer->id)->delete();
        // Corrupt the pair: the "in" no longer matches the "out" → must not be fabricated.
        DB::table('inventory_movements')->where('organization_id', $this->org->id)->where('reference', $transfer->transfer_number)
            ->where('movement_type', 'transfer_in')->update(['quantity' => '3.0000']);

        $this->artisan('inventory:restore-stock-transfers', ['--organization' => $this->org->id, '--actor' => $this->owner->email, '--apply' => true])
            ->expectsOutputToContain('refused')->assertSuccessful();
        $this->assertSame(0, StockTransfer::query()->where('organization_id', $this->org->id)->count());

        $intruder = User::factory()->create();
        $other = $this->createOrganization($intruder, 'Autre');
        $this->artisan('inventory:restore-stock-transfers', ['--organization' => $other->id, '--actor' => $intruder->email, '--apply' => true])->assertSuccessful();
        $this->assertSame(0, StockTransfer::query()->count());
    }

    public function test_all_missing_transfers_are_discovered_and_validated_independently(): void
    {
        // Server state: 0 stock_transfers, several TRF references in the ledger.
        $this->world();
        $sm57 = $this->createProduct($this->org, 'Shure SM57', 'SM57', ['default_sale_price' => '80.0000'])->variants->first();
        $this->openStock($this->owner, $this->org, $this->sp, $sm57, '6.0000');
        $direct = app(CreateStockTransferAction::class)->execute($this->owner, $this->org, $this->sp, $this->showroom,
            [['variant' => $this->beta, 'quantity' => '1']], 'Direct');
        $multi = app(CreateStockTransferAction::class)->execute($this->owner, $this->org, $this->sp, $this->showroom,
            [['variant' => $this->beta, 'quantity' => '2'], ['variant' => $sm57, 'quantity' => '3']], 'Deux produits');
        [, , $fromOrder] = $this->orderTransfer();
        $corrupt = app(CreateStockTransferAction::class)->execute($this->owner, $this->org, $this->showroom, $this->sp,
            [['variant' => $this->beta, 'quantity' => '1']], 'Retour');
        $expected = [];
        foreach ([$direct, $multi, $fromOrder] as $transfer) {
            $expected[$transfer->transfer_number] = [$transfer->source_warehouse_id, $transfer->destination_warehouse_id, $transfer->unit_count,
                DB::table('stock_transfer_lines')->where('stock_transfer_id', $transfer->id)->orderBy('source_out_movement_id')
                    ->get(['product_variant_id', 'quantity', 'source_out_movement_id', 'destination_in_movement_id'])->map(fn ($l) => (array) $l)->all()];
        }
        DB::table('transfer_requests')->where('organization_id', $this->org->id)->update(['stock_transfer_id' => null]);
        DB::table('stock_transfer_lines')->where('organization_id', $this->org->id)->delete();
        DB::table('stock_transfers')->where('organization_id', $this->org->id)->delete();
        DB::table('inventory_movements')->where('reference', $corrupt->transfer_number)->where('movement_type', 'transfer_in')->delete();
        $movementsBefore = DB::table('inventory_movements')->where('organization_id', $this->org->id)->count();

        $preview = app(\App\Actions\Inventory\RestoreStockTransfersFromLedgerAction::class)->execute($this->owner, $this->org, false);
        $this->assertEqualsCanonicalizing(array_keys($expected), array_column($preview['restorable'], 'number'));
        $this->assertSame([$corrupt->transfer_number], array_column($preview['refused'], 'number'));
        $this->assertSame(0, StockTransfer::query()->where('organization_id', $this->org->id)->count(), 'Preview writes nothing.');

        $this->artisan('inventory:restore-stock-transfers', ['--organization' => $this->org->id, '--actor' => $this->owner->email, '--apply' => true])
            ->expectsOutputToContain('refused '.$corrupt->transfer_number)->assertSuccessful();

        foreach ($expected as $number => [$source, $destination, $units, $lines]) {
            $restored = StockTransfer::query()->where('organization_id', $this->org->id)->where('transfer_number', $number)->firstOrFail();
            $this->assertSame([(int) $source, (int) $destination, $units], [(int) $restored->source_warehouse_id, (int) $restored->destination_warehouse_id, $restored->unit_count], $number);
            $this->assertEquals($lines, DB::table('stock_transfer_lines')->where('stock_transfer_id', $restored->id)->orderBy('source_out_movement_id')
                ->get(['product_variant_id', 'quantity', 'source_out_movement_id', 'destination_in_movement_id'])->map(fn ($l) => (array) $l)->all(), $number);
        }
        $this->assertDatabaseMissing('stock_transfers', ['transfer_number' => $corrupt->transfer_number]);
        $this->assertSame($movementsBefore, DB::table('inventory_movements')->where('organization_id', $this->org->id)->count());
        $this->assertSame(1, (int) TransferRequest::query()->whereNotNull('stock_transfer_id')->count(), 'The order request is re-linked.');
    }
}
