<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\PrepareTransferRequestAction;
use App\Actions\Inventory\ReceiveTransferRequestAction;
use App\Actions\Inventory\ShipTransferRequestAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Enums\FulfillmentStatus;
use App\Enums\InventoryReservationStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\TransferRequestStatus;
use App\Models\AuditLog;
use App\Models\Fulfillment;
use App\Models\InventoryBalance;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\TransferRequest;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Fulfillment\FulfillmentEngine;
use App\Services\InventoryReservationManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PosTestCase;

/**
 * Incident TRQ-000030 / SO-000073: receiving a customer transfer when the
 * order's allocations point at reservations that are no longer active.
 */
class TransferReceiptReservationStateTest extends PosTestCase
{
    private User $owner;

    private Organization $org;

    private Warehouse $showroom;

    private Warehouse $depot;

    private ProductVariant $variant;

    private SalesOrder $order;

    private TransferRequest $request;

    /** A POS order of 3 units: 2 held at the Showroom, 1 at the depot, its customer transfer shipped. */
    private function shipped(string $showroomStock = '2.0000', string $depotStock = '28.0000', string $quantity = '3.0000'): void
    {
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $store = $this->createStore($this->org, $this->owner);
        $this->showroom = $this->createWarehouse($this->org, 'Showroom');
        $this->depot = $this->createWarehouse($this->org, 'Entrepot SP');
        $tax = $this->createTaxRate($this->org, 'TVA 20', '20.0000');
        $this->variant = $this->createProduct($this->org, 'Pied perche', 'TKM-210', ['default_sale_price' => '750.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->activate($this->owner, $this->org, $store);
        $this->openStock($this->owner, $this->org, $this->showroom, $this->variant, $showroomStock);
        $this->openStock($this->owner, $this->org, $this->depot, $this->variant, $depotStock);

        $draft = $this->createPosDraft($this->owner, $this->org, $store, $this->showroom);
        $this->addCatalogLine($this->owner, $draft, $this->variant, $this->showroom, ['quantity' => $quantity]);
        $this->order = app(ConfirmSalesOrderAction::class)->execute($this->owner, $draft->fresh());
        $this->request = TransferRequest::query()->where('sales_order_id', $this->order->id)->firstOrFail();
        app(PrepareTransferRequestAction::class)->execute($this->owner, $this->request->fresh());
        app(ShipTransferRequestAction::class)->execute($this->owner, $this->request->fresh());
    }

    private function receive(): TransferRequest
    {
        return app(ReceiveTransferRequestAction::class)->execute($this->owner, $this->request->fresh());
    }

    private function allocation(Warehouse $warehouse): ?SalesOrderInventoryAllocation
    {
        return SalesOrderInventoryAllocation::query()->where('warehouse_id', $warehouse->id)
            ->whereHas('salesOrderLine', fn ($q) => $q->where('sales_order_id', $this->order->id))->first();
    }

    private function release(Warehouse $warehouse): InventoryReservation
    {
        return app(InventoryReservationManager::class)->release($this->owner, $this->org, $this->allocation($warehouse)->inventoryReservation);
    }

    private function stock(Warehouse $warehouse): InventoryBalance
    {
        return $this->balance($this->org, $warehouse, $this->variant);
    }

    private function assertStock(Warehouse $warehouse, string $onHand, string $reserved): void
    {
        $this->assertSame([$onHand, $reserved], [$this->stock($warehouse)->on_hand, $this->stock($warehouse)->reserved], $warehouse->name);
    }

    /** reserved == sum(active reservations) at each warehouse, and every active hold matches its allocation. */
    private function assertLedgerConsistent(): void
    {
        foreach ([$this->showroom, $this->depot] as $warehouse) {
            $active = (float) InventoryReservation::query()->where('warehouse_id', $warehouse->id)
                ->where('status', InventoryReservationStatus::Active->value)->sum('quantity');
            $this->assertSame($active, (float) $this->stock($warehouse)->reserved, "reserved balance at {$warehouse->name}");
        }
        foreach (SalesOrderInventoryAllocation::query()->with('inventoryReservation')->get() as $allocation) {
            if ($allocation->inventoryReservation?->status === InventoryReservationStatus::Active) {
                $this->assertSame($allocation->quantity, $allocation->inventoryReservation->quantity);
                $this->assertSame((int) $allocation->warehouse_id, (int) $allocation->inventoryReservation->warehouse_id);
            }
        }
    }

    /** @return array<string, mixed> everything a refused receipt must leave untouched */
    private function snapshot(): array
    {
        return [
            'balances' => InventoryBalance::query()->orderBy('id')->get(['id', 'on_hand', 'reserved'])->toArray(),
            'reservations' => InventoryReservation::query()->orderBy('id')->get(['id', 'quantity', 'status'])->toArray(),
            'allocations' => SalesOrderInventoryAllocation::query()->orderBy('id')->get(['id', 'warehouse_id', 'quantity', 'inventory_reservation_id'])->toArray(),
            'movements' => \DB::table('inventory_movements')->count(),
            'transfers' => \DB::table('stock_transfers')->count(),
            'audit' => AuditLog::query()->count(),
            'status' => $this->request->fresh()->status,
        ];
    }

    private function assertRefused(string $key): string
    {
        $before = $this->snapshot();
        try {
            $this->receive();
            $this->fail('The receipt should have been refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
        $this->assertEquals($before, $this->snapshot(), 'a refused receipt changes nothing');

        return $exception->errors()[$key][0];
    }

    public function test_incident_both_holds_released_receipt_succeeds_with_new_reservations(): void
    {
        $this->shipped();
        $old = [$this->release($this->showroom), $this->release($this->depot)];
        $this->assertStock($this->showroom, '2.0000', '0.0000');

        $this->assertSame(TransferRequestStatus::Received, $this->receive()->status);

        $this->assertStock($this->showroom, '3.0000', '3.0000');
        $this->assertStock($this->depot, '27.0000', '0.0000');
        $this->assertNull($this->allocation($this->depot));
        $this->assertSame('3.0000', $this->allocation($this->showroom)->quantity);
        foreach ($old as $reservation) {
            $fresh = $reservation->fresh();
            $this->assertSame(InventoryReservationStatus::Released, $fresh->status, 'history is never reactivated');
            $this->assertSame($reservation->quantity, $fresh->quantity);
        }
        $this->assertNotContains($this->allocation($this->showroom)->inventory_reservation_id, [$old[0]->id, $old[1]->id]);
        $this->assertDatabaseCount('stock_transfers', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'transfer_request.reservation_restored', 'auditable_id' => $this->request->id]);
        $this->assertLedgerConsistent();

        $fulfilled = app(FulfillSalesOrderAction::class)->execute($this->owner, $this->order->fresh());
        $this->assertSame('fulfilled', $fulfilled->fulfillment_status->value);
        $this->assertStock($this->showroom, '0.0000', '0.0000');
    }

    public function test_healthy_receipt_grows_the_active_destination_hold(): void
    {
        $this->shipped();
        $held = $this->allocation($this->showroom)->inventory_reservation_id;

        $this->receive();

        $this->assertSame($held, $this->allocation($this->showroom)->inventory_reservation_id);
        $this->assertStock($this->showroom, '3.0000', '3.0000');
        $this->assertStock($this->depot, '27.0000', '0.0000');
        $this->assertDatabaseMissing('audit_logs', ['event' => 'transfer_request.reservation_restored']);
        $this->assertLedgerConsistent();
    }

    public function test_released_destination_with_active_source(): void
    {
        $this->shipped();
        $this->release($this->showroom);

        $this->receive();

        $this->assertStock($this->showroom, '3.0000', '3.0000');
        $this->assertStock($this->depot, '27.0000', '0.0000');
        $this->assertLedgerConsistent();
    }

    public function test_released_source_with_active_destination(): void
    {
        $this->shipped();
        $this->release($this->depot);

        $this->receive();

        $this->assertStock($this->showroom, '3.0000', '3.0000');
        $this->assertStock($this->depot, '27.0000', '0.0000');
        $this->assertNull($this->allocation($this->depot));
        $this->assertLedgerConsistent();
    }

    public function test_destination_allocation_without_a_reservation(): void
    {
        $this->shipped();
        $this->release($this->showroom);
        $allocation = $this->allocation($this->showroom);
        $allocation->inventory_reservation_id = null;
        $allocation->save();

        $this->receive();

        $this->assertStock($this->showroom, '3.0000', '3.0000');
        $this->assertLedgerConsistent();
    }

    public function test_partially_fulfilled_order_holds_only_what_is_still_to_ship(): void
    {
        $this->shipped();
        $engine = app(FulfillmentEngine::class);
        $order = $this->order->fresh();
        $line = $order->lines()->firstOrFail();
        // Engine level: the dispatch guard forbids this through the UI while a customer transfer is open.
        $fulfillment = $engine->create($this->owner, $order, [$line->id => '2.0000'], FulfillmentStatus::Delivered, Fulfillment::CHANNEL_DIRECT);
        $consumed = $engine->consumeStock($this->owner, $order, $fulfillment);
        $engine->syncOrder($this->owner, $order);

        $this->receive();

        $this->assertStock($this->showroom, '1.0000', '1.0000');
        $this->assertStock($this->depot, '27.0000', '0.0000');
        $this->assertSame('1.0000', $this->allocation($this->showroom)->quantity);
        $this->assertSame(InventoryReservationStatus::Consumed, InventoryReservation::query()->findOrFail($consumed[0])->status);
        $this->assertSame('2.0000', InventoryReservation::query()->findOrFail($consumed[0])->quantity);
        $this->assertLedgerConsistent();

        $fulfilled = app(FulfillSalesOrderAction::class)->execute($this->owner, $this->order->fresh());
        $this->assertSame('fulfilled', $fulfilled->fulfillment_status->value);
    }

    public function test_only_the_travelling_quantity_leaves_an_active_source_hold(): void
    {
        $this->shipped('2.0000', '28.0000', '7.0000'); // 2 Showroom + 5 depot
        $this->request->lines()->update(['quantity' => '3.0000']);

        $this->receive();

        $this->assertStock($this->showroom, '5.0000', '5.0000');
        $this->assertStock($this->depot, '25.0000', '2.0000');
        $this->assertSame('2.0000', $this->allocation($this->depot)->quantity);
        $this->assertLedgerConsistent();
    }

    public function test_released_source_remainder_is_held_again_where_it_stays(): void
    {
        $this->shipped('2.0000', '28.0000', '7.0000');
        $this->request->lines()->update(['quantity' => '3.0000']);
        $this->release($this->showroom);
        $this->release($this->depot);

        $this->receive();

        $this->assertStock($this->showroom, '5.0000', '5.0000');
        $this->assertStock($this->depot, '25.0000', '2.0000');
        $this->assertLedgerConsistent();
    }

    public function test_lost_hold_cannot_be_restored_without_free_stock_and_retry_works(): void
    {
        $this->shipped();
        $this->release($this->showroom);
        // Another commitment took the freed Showroom units.
        $rival = app(InventoryReservationManager::class)->reserve($this->owner, $this->org, $this->showroom, $this->variant, '2.0000');

        $message = $this->assertRefused('quantity');
        $this->assertStringContainsString('Showroom', $message);

        app(InventoryReservationManager::class)->release($this->owner, $this->org, $rival);
        $this->receive();
        $this->assertStock($this->showroom, '3.0000', '3.0000');
        $this->assertLedgerConsistent();
    }

    public function test_insufficient_source_stock_is_refused_in_the_application_language(): void
    {
        $this->shipped('2.0000', '1.0000');
        $this->release($this->depot);
        app(InventoryReservationManager::class)->reserve($this->owner, $this->org, $this->depot, $this->variant, '1.0000');

        app()->setLocale('fr');
        $this->assertStringStartsWith('Stock disponible insuffisant', $this->assertRefused('quantity'));
        app()->setLocale('en');
        $this->assertStringStartsWith('Not enough available stock', $this->assertRefused('quantity'));
    }

    public function test_untraceable_customer_quantity_is_refused(): void
    {
        $this->shipped();
        $this->release($this->depot);
        $this->allocation($this->depot)->delete();

        $this->assertRefused('allocation');
    }

    public function test_order_that_is_no_longer_confirmed_is_refused(): void
    {
        $this->shipped();
        $this->order->forceFill(['status' => SalesOrderStatus::Draft])->save();

        $this->assertRefused('order');
    }

    public function test_repeated_receipt_moves_stock_once(): void
    {
        $this->shipped();
        $this->release($this->showroom);
        $stale = $this->request->fresh();
        $this->receive();
        $after = $this->snapshot();

        try {
            app(ReceiveTransferRequestAction::class)->execute($this->owner, $stale);
            $this->fail('A second receipt must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        $this->assertEquals($after, $this->snapshot());
    }

    public function test_another_organization_cannot_receive_the_request(): void
    {
        $this->shipped();
        $outsider = User::factory()->create();
        $this->createOrganization($outsider);
        $before = $this->snapshot();

        try {
            app(ReceiveTransferRequestAction::class)->execute($outsider, $this->request->fresh());
            $this->fail('Tenant isolation.');
        } catch (HttpException $exception) {
            $this->assertContains($exception->getStatusCode(), [403, 404]);
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertEquals($before, $this->snapshot());
    }

    public function test_general_transfer_lands_as_free_stock(): void
    {
        $this->shipped();
        // The same shipment without a customer order: nothing is earmarked for anyone.
        $this->release($this->depot);
        $this->request->forceFill(['sales_order_id' => null])->save();
        $this->request->lines()->update(['reason' => 'manual', 'sales_order_line_id' => null]);

        $this->receive();

        $this->assertStock($this->showroom, '3.0000', '2.0000');
        $this->assertStock($this->depot, '27.0000', '0.0000');
        $this->assertLedgerConsistent();
    }

    public function test_http_receipt_reports_the_refusal_then_confirms_success(): void
    {
        $this->shipped();
        $this->release($this->showroom);
        $adjust = app(InventoryReservationManager::class);
        $rival = $adjust->reserve($this->owner, $this->org, $this->showroom, $this->variant, '2.0000');
        app()->setLocale('fr');
        $url = route('inventory.transfer-requests.receive', $this->request);

        $this->actingAs($this->owner)->from('/back')->post($url)->assertRedirect('/back')->assertSessionHasErrors('quantity');

        $adjust->release($this->owner, $this->org, $rival);
        $this->actingAs($this->owner)->from('/back')->post($url)->assertSessionHasNoErrors()
            ->assertSessionHas('success', "Demande {$this->request->request_number} réceptionnée : le stock a été transféré.");
    }
}
