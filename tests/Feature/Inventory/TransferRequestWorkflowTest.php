<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\CancelTransferRequestAction;
use App\Actions\Inventory\CreateOrderTransferRequestsAction;
use App\Actions\Inventory\CreateStockTransferAction;
use App\Actions\Inventory\PlanShowroomReplenishmentAction;
use App\Actions\Inventory\PrepareTransferRequestAction;
use App\Actions\Inventory\ReceiveTransferRequestAction;
use App\Actions\Inventory\ShipTransferRequestAction;
use App\Actions\Sales\CancelSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Enums\TransferRequestReason;
use App\Enums\TransferRequestStatus;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\Store;
use App\Models\TransferRequest;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentOverride;
use App\Models\WarehouseReplenishmentSetting;
use Illuminate\Validation\ValidationException;
use Tests\Support\PosTestCase;

class TransferRequestWorkflowTest extends PosTestCase
{
    /** @return array{User, Organization, Store, Warehouse, Warehouse, ProductVariant} */
    private function context(string $showroomStock = '5.0000', string $depotStock = '30.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $showroom = $this->createWarehouse($organization, 'Showroom AV');
        $depot = $this->createWarehouse($organization, 'Dépôt principal');
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'Micro MV7i', 'MV7I', ['default_sale_price' => '1000.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->seedStock($owner, $organization, $showroom, $variant, $showroomStock);
        $this->seedStock($owner, $organization, $depot, $variant, $depotStock);

        return [$owner, $organization, $store, $showroom, $depot, $variant];
    }

    /** Opening stock rejects a zero quantity — just skip it. */
    private function seedStock(User $owner, Organization $org, Warehouse $warehouse, ProductVariant $variant, string $qty): void
    {
        if ((float) $qty > 0) {
            $this->openStock($owner, $org, $warehouse, $variant, $qty);
        }
    }

    private function override(Organization $org, Warehouse $warehouse, ProductVariant $variant, string $minimum): void
    {
        WarehouseReplenishmentOverride::query()->create([
            'organization_id' => $org->id,
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'minimum_quantity' => $minimum,
        ]);
    }

    private function confirmedPosOrder(User $owner, Organization $org, Store $store, Warehouse $showroom, ProductVariant $variant, string $qty): SalesOrder
    {
        $draft = $this->createPosDraft($owner, $org, $store, $showroom);
        $this->addCatalogLine($owner, $draft, $variant, $showroom, ['quantity' => $qty]);

        return app(ConfirmSalesOrderAction::class)->execute($owner, $draft->fresh());
    }

    private function enableReplenishment(Organization $org, Warehouse $warehouse, string $minimum = '2.0000'): void
    {
        WarehouseReplenishmentSetting::query()->create([
            'organization_id' => $org->id,
            'warehouse_id' => $warehouse->id,
            'auto_replenish' => true,
            'default_minimum_quantity' => $minimum,
        ]);
    }

    private function drive(User $owner, TransferRequest $request): TransferRequest
    {
        app(PrepareTransferRequestAction::class)->execute($owner, $request->fresh());
        app(ShipTransferRequestAction::class)->execute($owner, $request->fresh());

        return app(ReceiveTransferRequestAction::class)->execute($owner, $request->fresh());
    }

    // 1 + 15 --------------------------------------------------------------

    public function test_company_wide_order_is_split_five_showroom_five_depot(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('5.0000', '30.0000');
        $order = $this->confirmedPosOrder($owner, $org, $store, $showroom, $variant, '10.0000');

        $allocations = $order->lines()->firstOrFail()->allocations()->get()->keyBy('warehouse_id');
        $this->assertSame('5.0000', $allocations[$showroom->id]->quantity);
        $this->assertSame('5.0000', $allocations[$depot->id]->quantity);
        // reserved stock is excluded from what other orders may take
        $this->assertSame('0.0000', $this->balance($org, $showroom, $variant)->available);
        $this->assertSame('25.0000', $this->balance($org, $depot, $variant)->available);
    }

    // 2 + 3 + 4 ---------------------------------------------------------

    public function test_confirmation_raises_a_remote_transfer_request_without_moving_stock(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('5.0000', '30.0000');
        $order = $this->confirmedPosOrder($owner, $org, $store, $showroom, $variant, '10.0000');

        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();
        $this->assertSame(TransferRequestStatus::Requested, $request->status);
        $this->assertSame($depot->id, (int) $request->source_warehouse_id);
        $this->assertSame($showroom->id, (int) $request->destination_warehouse_id);
        $line = $request->lines()->firstOrFail();
        $this->assertSame(TransferRequestReason::OrderFulfillment, $line->reason);
        $this->assertSame('5.0000', $line->quantity);
        $this->assertDatabaseHas('audit_logs', ['event' => 'transfer_request.created', 'auditable_id' => $request->id]);

        // no physical effect yet
        $this->assertSame('30.0000', $this->balance($org, $depot, $variant)->on_hand);
        $this->assertSame('5.0000', $this->balance($org, $showroom, $variant)->on_hand);
        $this->assertSame('25.0000', $this->balance($org, $depot, $variant)->available); // still reserved for the order
        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertDatabaseMissing('inventory_movements', ['movement_type' => 'transfer_out']);
    }

    // 5 + 21 ----------------------------------------------------------

    public function test_receipt_conserves_quantity_and_creates_no_finance_record(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('5.0000', '30.0000');
        $order = $this->confirmedPosOrder($owner, $org, $store, $showroom, $variant, '10.0000');
        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();

        $received = $this->drive($owner, $request);

        $this->assertSame(TransferRequestStatus::Received, $received->status);
        $this->assertNotNull($received->stock_transfer_id);
        // quantity conserved: 35 units company-wide before and after
        $this->assertSame('25.0000', $this->balance($org, $depot, $variant)->on_hand);
        $this->assertSame('10.0000', $this->balance($org, $showroom, $variant)->on_hand);
        // the customer earmark followed the stock to the Showroom
        $this->assertSame('0.0000', $this->balance($org, $showroom, $variant)->available);
        $this->assertSame('25.0000', $this->balance($org, $depot, $variant)->available);
        $this->assertDatabaseCount('stock_transfers', 1);
        // the order's allocations are all at the Showroom now
        $this->assertSame(0, SalesOrderInventoryAllocation::query()
            ->whereHas('salesOrderLine', fn ($q) => $q->where('sales_order_id', $order->id))
            ->where('warehouse_id', $depot->id)->count());
        // pure inventory event — no finance
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    // 6 -------------------------------------------------------------

    public function test_order_is_not_fulfillable_until_the_transfer_is_received(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('5.0000', '30.0000');
        $order = $this->confirmedPosOrder($owner, $org, $store, $showroom, $variant, '10.0000');
        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();

        $this->assertTrue($order->fresh()->awaitingReplenishment());
        try {
            app(FulfillSalesOrderAction::class)->execute($owner, $order->fresh());
            $this->fail('Fulfillment must be blocked while awaiting replenishment.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('approvisionner', collect($e->errors())->flatten()->implode(' '));
        }

        $this->drive($owner, $request);
        $this->assertFalse($order->fresh()->awaitingReplenishment());
        $fulfilled = app(FulfillSalesOrderAction::class)->execute($owner, $order->fresh());
        $this->assertSame('fulfilled', $fulfilled->fulfillment_status->value);
        $this->assertSame('0.0000', $this->balance($org, $showroom, $variant)->on_hand);
    }

    // 8 + 9 + 12 ------------------------------------------------------

    public function test_order_and_minimum_replenishment_consolidate_into_one_seven_unit_request(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('5.0000', '30.0000');
        $this->enableReplenishment($org, $showroom, '2.0000');

        $order = $this->confirmedPosOrder($owner, $org, $store, $showroom, $variant, '10.0000');

        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();
        $lines = $request->lines()->get()->keyBy(fn ($l) => $l->reason->value);
        $this->assertSame('5.0000', $lines['order_fulfillment']->quantity);
        $this->assertSame('2.0000', $lines['minimum_replenishment']->quantity);
        $this->assertSame(7.0, $request->lines()->get()->reduce(fn ($c, $l) => $c + (float) $l->quantity, 0.0));
        $this->assertDatabaseHas('audit_logs', ['event' => 'auto_replenishment.created']);
    }

    public function test_customer_order_wins_the_last_remote_units_over_minimum_replenishment(): void
    {
        // Depot has EXACTLY the 5 the customer needs remotely — nothing left for display stock.
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('5.0000', '5.0000');
        $this->enableReplenishment($org, $showroom, '2.0000');

        $order = $this->confirmedPosOrder($owner, $org, $store, $showroom, $variant, '10.0000');

        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();
        $this->assertSame('5.0000', $request->lines()->where('reason', 'order_fulfillment')->firstOrFail()->quantity);
        $this->assertSame(0, $request->lines()->where('reason', 'minimum_replenishment')->count());
    }

    // 10 + 11 + 14 --------------------------------------------------

    public function test_active_incoming_replenishment_prevents_duplicates(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('0.0000', '30.0000');
        $this->enableReplenishment($org, $showroom, '0.0000');
        $this->override($org, $showroom, $variant, '2.0000');

        $first = app(PlanShowroomReplenishmentAction::class)->execute($owner, $org, $showroom);
        $this->assertCount(1, $first);
        $req = TransferRequest::query()->whereKey($first[0])->firstOrFail();
        $this->assertSame('2.0000', $req->lines()->firstOrFail()->quantity);

        // second sweep: 2 already incoming → nothing new
        $second = app(PlanShowroomReplenishmentAction::class)->execute($owner, $org, $showroom);
        $this->assertCount(0, $second);
        $this->assertSame(1, TransferRequest::query()->where('organization_id', $org->id)->count());
    }

    public function test_partial_incoming_only_tops_up_the_remainder(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('0.0000', '30.0000');
        $this->enableReplenishment($org, $showroom, '0.0000');
        $this->override($org, $showroom, $variant, '2.0000');

        // 1 already on the way
        $existing = new TransferRequest;
        $existing->organization_id = $org->id;
        $existing->request_number = 'TRQ-SEED1';
        $existing->source_warehouse_id = $depot->id;
        $existing->destination_warehouse_id = $showroom->id;
        $existing->status = TransferRequestStatus::Requested;
        $existing->requested_at = now();
        $existing->save();
        $existing->lines()->create([
            'organization_id' => $org->id,
            'product_variant_id' => $variant->id,
            'quantity' => '1.0000',
            'reason' => TransferRequestReason::MinimumReplenishment->value,
        ]);

        app(PlanShowroomReplenishmentAction::class)->execute($owner, $org, $showroom);

        // minimum 2, already 1 incoming → exactly 1 more is topped up (line 1 → 2).
        $this->assertSame('2.0000', $existing->fresh()->lines()->firstOrFail()->quantity);
        $this->assertSame(1, TransferRequest::query()->where('organization_id', $org->id)->count());
    }

    // 13 -----------------------------------------------------------

    public function test_replenishment_splits_deterministically_across_multiple_sources(): void
    {
        $owner = User::factory()->create();
        $org = $this->createOrganization($owner);
        $store = $this->createStore($org, $owner);
        $showroom = $this->createWarehouse($org, 'Showroom');
        $depotA = $this->createWarehouse($org, 'Depot A');
        $depotB = $this->createWarehouse($org, 'Depot B');
        $variant = $this->createProduct($org, 'Split', 'SPLIT')->variants->first();
        $this->activate($owner, $org, $store);
        $this->openStock($owner, $org, $depotA, $variant, '4.0000');
        $this->openStock($owner, $org, $depotB, $variant, '10.0000');
        $this->enableReplenishment($org, $showroom, '0.0000');
        $this->override($org, $showroom, $variant, '7.0000');

        $created = app(PlanShowroomReplenishmentAction::class)->execute($owner, $org, $showroom);

        $bySource = TransferRequest::query()->whereIn('id', $created)->get()
            ->mapWithKeys(fn ($r) => [(int) $r->source_warehouse_id => (float) $r->lines()->sum('quantity')]);
        $this->assertSame(4.0, $bySource[$depotA->id]);
        $this->assertSame(3.0, $bySource[$depotB->id]);
    }

    // 14 -----------------------------------------------------------

    public function test_replenishment_never_sources_from_another_organization(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('0.0000', '0.0000');
        $this->enableReplenishment($org, $showroom, '0.0000');
        $this->override($org, $showroom, $variant, '5.0000');

        $foreignOwner = User::factory()->create();
        $foreignOrg = $this->createOrganization($foreignOwner);
        $foreignWarehouse = $this->createWarehouse($foreignOrg, 'Foreign');
        $foreignVariant = $this->createProduct($foreignOrg, 'Micro MV7i', 'MV7I')->variants->first();
        $this->activate($foreignOwner, $foreignOrg);
        $this->openStock($foreignOwner, $foreignOrg, $foreignWarehouse, $foreignVariant, '999.0000');
        $this->activate($owner, $org, $store);

        $created = app(PlanShowroomReplenishmentAction::class)->execute($owner, $org, $showroom);
        $this->assertCount(0, $created);
    }

    // 16 -----------------------------------------------------------

    public function test_re_running_request_creation_is_idempotent(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('5.0000', '30.0000');
        $order = $this->confirmedPosOrder($owner, $org, $store, $showroom, $variant, '10.0000');

        // simulate a retried confirmation hook
        app(CreateOrderTransferRequestsAction::class)->createForConfirmedOrder($owner, $order->fresh(['lines.allocations', 'organization', 'store']));
        app(CreateOrderTransferRequestsAction::class)->createForConfirmedOrder($owner, $order->fresh(['lines.allocations', 'organization', 'store']));

        $this->assertSame(1, TransferRequest::query()->where('sales_order_id', $order->id)->count());
        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();
        $this->assertSame(1, $request->lines()->where('reason', 'order_fulfillment')->count());
        $this->assertSame('5.0000', $request->lines()->where('reason', 'order_fulfillment')->firstOrFail()->quantity);
    }

    // 17 -----------------------------------------------------------

    public function test_two_orders_cannot_oversell_the_same_remote_stock(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('0.0000', '30.0000');

        $this->confirmedPosOrder($owner, $org, $store, $showroom, $variant, '20.0000');

        $draft = $this->createPosDraft($owner, $org, $store, $showroom);
        $this->expectException(ValidationException::class);
        $this->addCatalogLine($owner, $draft, $variant, $showroom, ['quantity' => '20.0000']);
    }

    // 18 + 19 ----------------------------------------------------

    public function test_order_cancellation_drops_unshipped_transfer_demand_but_not_a_shipped_one(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('5.0000', '30.0000');

        $orderA = $this->confirmedPosOrder($owner, $org, $store, $showroom, $variant, '10.0000');
        $requestA = TransferRequest::query()->where('sales_order_id', $orderA->id)->firstOrFail();
        app(CancelSalesOrderAction::class)->execute($owner, $orderA->fresh(), 'Client absent');
        $requestA->refresh();
        $this->assertSame(TransferRequestStatus::Cancelled, $requestA->status);
        $this->assertSame('30.0000', $this->balance($org, $depot, $variant)->available); // reservation released

        $orderB = $this->confirmedPosOrder($owner, $org, $store, $showroom, $variant, '10.0000');
        $requestB = TransferRequest::query()->where('sales_order_id', $orderB->id)->firstOrFail();
        app(PrepareTransferRequestAction::class)->execute($owner, $requestB->fresh());
        app(ShipTransferRequestAction::class)->execute($owner, $requestB->fresh());
        app(CancelSalesOrderAction::class)->execute($owner, $orderB->fresh(), 'Trop tard');
        $this->assertSame(TransferRequestStatus::Shipped, $requestB->fresh()->status);
    }

    public function test_a_shipped_request_cannot_be_cancelled(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('5.0000', '30.0000');
        $order = $this->confirmedPosOrder($owner, $org, $store, $showroom, $variant, '10.0000');
        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();
        app(PrepareTransferRequestAction::class)->execute($owner, $request->fresh());
        app(ShipTransferRequestAction::class)->execute($owner, $request->fresh());

        $this->expectException(ValidationException::class);
        app(CancelTransferRequestAction::class)->execute($owner, $request->fresh(), 'nope');
    }

    // 20 -----------------------------------------------------------

    public function test_manual_stock_transfer_still_works(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('0.0000', '10.0000');

        $transfer = app(CreateStockTransferAction::class)->execute(
            $owner, $org, $depot, $showroom, [['variant' => $variant, 'quantity' => '4']], 'Réassort manuel',
        );

        $this->assertSame('completed', $transfer->status);
        $this->assertSame('6.0000', $this->balance($org, $depot, $variant)->on_hand);
        $this->assertSame('4.0000', $this->balance($org, $showroom, $variant)->on_hand);
    }

    // 22 -----------------------------------------------------------

    public function test_warehouse_role_authorization_on_transfer_request_endpoints(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('5.0000', '30.0000');
        $order = $this->confirmedPosOrder($owner, $org, $store, $showroom, $variant, '10.0000');
        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();

        // member with only view — cannot prepare or receive
        $clerk = User::factory()->create();
        $this->addOrganizationMember($org, $clerk, ['inventory.transfer_requests.view'], roleName: 'Viewer');
        $this->addStoreMember($store, $clerk);
        $this->activate($clerk, $org, $store);
        $this->actingAs($clerk)->post(route('inventory.transfer-requests.prepare', $request))->assertForbidden();
        $this->actingAs($clerk)->post(route('inventory.transfer-requests.receive', $request))->assertForbidden();
        $this->actingAs($clerk)->get(route('inventory.transfer-requests.show', $request))->assertOk();

        // foreign tenant — hidden 404
        $outsider = User::factory()->create();
        $otherOrg = $this->createOrganization($outsider);
        $otherStore = $this->createStore($otherOrg, $outsider);
        $this->activate($outsider, $otherOrg, $otherStore);
        $this->actingAs($outsider)->get(route('inventory.transfer-requests.show', $request))->assertNotFound();
        $this->actingAs($outsider)->post(route('inventory.transfer-requests.prepare', $request))->assertNotFound();
    }

    // === Replenishment gap fix ==========================================

    // §13.1 + §13.3 + §13.13
    public function test_fully_local_order_still_triggers_minimum_replenishment(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('5.0000', '30.0000');
        $this->enableReplenishment($org, $showroom, '2.0000');

        // Order 5 is 100% Showroom → no ORDER_FULFILLMENT demand at all.
        $order = $this->confirmedPosOrder($owner, $org, $store, $showroom, $variant, '5.0000');
        $this->assertSame('0.0000', $this->balance($org, $showroom, $variant)->available);

        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();
        $lines = $request->lines()->get();
        $this->assertCount(1, $lines);
        $this->assertSame(TransferRequestReason::MinimumReplenishment, $lines[0]->reason);
        $this->assertSame('2.0000', $lines[0]->quantity);
        $this->assertSame($depot->id, (int) $request->source_warehouse_id);
        $this->assertSame(0, $request->lines()->where('reason', 'order_fulfillment')->count());
        // still a logistics instruction only
        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertDatabaseMissing('inventory_movements', ['movement_type' => 'transfer_in']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auto_replenishment.created']);
    }

    // §13.2
    public function test_partial_local_depletion_requests_only_the_exact_gap(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('5.0000', '30.0000');
        $this->enableReplenishment($org, $showroom, '2.0000');

        // Order 4 → projected Showroom availability = 1 → deficit = 1.
        $order = $this->confirmedPosOrder($owner, $org, $store, $showroom, $variant, '4.0000');
        $this->assertSame('1.0000', $this->balance($org, $showroom, $variant)->available);

        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();
        $line = $request->lines()->firstOrFail();
        $this->assertSame(TransferRequestReason::MinimumReplenishment, $line->reason);
        $this->assertSame('1.0000', $line->quantity);
        $this->assertSame(0, $request->lines()->where('reason', 'order_fulfillment')->count());
    }

    // §13.4 — the remote scenario is unchanged
    public function test_remote_order_ten_still_yields_five_plus_two(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('5.0000', '30.0000');
        $this->enableReplenishment($org, $showroom, '2.0000');
        $order = $this->confirmedPosOrder($owner, $org, $store, $showroom, $variant, '10.0000');

        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();
        $byReason = $request->lines()->get()->keyBy(fn ($l) => $l->reason->value);
        $this->assertSame('5.0000', $byReason['order_fulfillment']->quantity);
        $this->assertSame('2.0000', $byReason['minimum_replenishment']->quantity);
        $this->assertSame(7.0, $request->lines()->get()->reduce(fn ($c, $l) => $c + (float) $l->quantity, 0.0));
        $this->assertSame($depot->id, (int) $request->source_warehouse_id);
    }

    // §13.6 — fully-local trigger also respects incoming dedup
    public function test_local_order_replenishment_respects_active_incoming(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('5.0000', '30.0000');
        $this->enableReplenishment($org, $showroom, '2.0000');

        // 2 already inbound for replenishment
        $seed = new TransferRequest;
        $seed->organization_id = $org->id;
        $seed->request_number = 'TRQ-SEEDLOCAL';
        $seed->source_warehouse_id = $depot->id;
        $seed->destination_warehouse_id = $showroom->id;
        $seed->status = TransferRequestStatus::Requested;
        $seed->requested_at = now();
        $seed->save();
        $seed->lines()->create([
            'organization_id' => $org->id,
            'product_variant_id' => $variant->id,
            'quantity' => '2.0000',
            'reason' => TransferRequestReason::MinimumReplenishment->value,
        ]);

        $order = $this->confirmedPosOrder($owner, $org, $store, $showroom, $variant, '5.0000');

        // projected 0 + incoming 2 = 2 = minimum → nothing new
        $this->assertNull(TransferRequest::query()->where('sales_order_id', $order->id)->first());
        $this->assertSame('2.0000', $seed->fresh()->lines()->firstOrFail()->quantity);
    }

    // §13.5 + §13.7 — no Showroom balance row, discovered via warehouse default
    public function test_variant_with_no_showroom_balance_row_is_discovered_by_default_minimum(): void
    {
        $owner = User::factory()->create();
        $org = $this->createOrganization($owner);
        $store = $this->createStore($org, $owner);
        $showroom = $this->createWarehouse($org, 'Showroom');
        $depot = $this->createWarehouse($org, 'Depot');
        $variant = $this->createProduct($org, 'Never Stocked Here', 'NSH')->variants->first();
        $this->activate($owner, $org, $store);
        $this->openStock($owner, $org, $depot, $variant, '30.0000'); // Showroom: NO balance row at all
        $this->enableReplenishment($org, $showroom, '2.0000');

        $this->assertDatabaseMissing('inventory_balances', ['warehouse_id' => $showroom->id, 'product_variant_id' => $variant->id]);

        $created = app(PlanShowroomReplenishmentAction::class)->execute($owner, $org, $showroom);

        $this->assertCount(1, $created);
        $request = TransferRequest::query()->whereKey($created[0])->firstOrFail();
        $this->assertSame('2.0000', $request->lines()->firstOrFail()->quantity);
        $this->assertSame($depot->id, (int) $request->source_warehouse_id);
    }

    // §13.8 — never fabricate stock
    public function test_no_company_stock_creates_no_impossible_request(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('0.0000', '0.0000');
        $this->enableReplenishment($org, $showroom, '2.0000');
        $this->override($org, $showroom, $variant, '2.0000');

        $created = app(PlanShowroomReplenishmentAction::class)->execute($owner, $org, $showroom);
        $this->assertCount(0, $created);
        $this->assertSame(0, TransferRequest::query()->where('organization_id', $org->id)->count());
    }

    // §13.9 — same variant on two order lines
    public function test_same_variant_on_two_order_lines_aggregates_safely(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('0.0000', '30.0000');

        $draft = $this->createPosDraft($owner, $org, $store, $showroom);
        $this->addCatalogLine($owner, $draft, $variant, $showroom, ['quantity' => '2.0000']);
        $this->addCatalogLine($owner, $draft, $variant, $showroom, ['quantity' => '3.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $draft->fresh());

        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();
        // one aggregated ORDER_FULFILLMENT line — the (org, request, variant, reason) index holds
        $of = $request->lines()->where('reason', 'order_fulfillment')->get();
        $this->assertCount(1, $of);
        $this->assertSame('5.0000', $of[0]->quantity);

        $this->drive($owner, $request);

        // traceability preserved: each SalesOrderLine keeps its own Showroom allocation
        $showroomAllocs = SalesOrderInventoryAllocation::query()
            ->whereHas('salesOrderLine', fn ($q) => $q->where('sales_order_id', $order->id))
            ->where('warehouse_id', $showroom->id)->get();
        $this->assertCount(2, $showroomAllocs);
        $this->assertEqualsCanonicalizing(['2.0000', '3.0000'], $showroomAllocs->pluck('quantity')->all());
        $this->assertSame(0, SalesOrderInventoryAllocation::query()
            ->whereHas('salesOrderLine', fn ($q) => $q->where('sales_order_id', $order->id))
            ->where('warehouse_id', $depot->id)->count());

        $fulfilled = app(FulfillSalesOrderAction::class)->execute($owner, $order->fresh());
        $this->assertSame('fulfilled', $fulfilled->fulfillment_status->value);
        $this->assertSame('0.0000', $this->balance($org, $showroom, $variant)->on_hand);
    }

    // §13.10 + §13.11 — receive merges into an existing Showroom allocation
    public function test_receive_merges_into_existing_showroom_allocation_and_leaves_no_stale_row(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('5.0000', '30.0000');
        $order = $this->confirmedPosOrder($owner, $org, $store, $showroom, $variant, '10.0000');
        $line = $order->lines()->firstOrFail();

        // before receipt: two allocations for the one line
        $this->assertCount(2, $line->allocations()->get());

        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();
        $this->drive($owner, $request);

        $allocs = $line->fresh()->allocations()->with('inventoryReservation')->get();
        $this->assertCount(1, $allocs);
        $merged = $allocs->first();
        $this->assertSame($showroom->id, (int) $merged->warehouse_id);
        $this->assertSame('10.0000', $merged->quantity);
        $this->assertNotNull($merged->inventory_reservation_id);
        $this->assertSame('10.0000', $merged->inventoryReservation->quantity);
        $this->assertSame('active', $merged->inventoryReservation->status->value);
        // no stale Depot allocation / reservation
        $this->assertSame(0, SalesOrderInventoryAllocation::query()
            ->where('sales_order_line_id', $line->id)->where('warehouse_id', $depot->id)->count());

        // quantity conserved and the order is now fulfillable at the Showroom
        $this->assertSame('25.0000', $this->balance($org, $depot, $variant)->on_hand);
        $this->assertSame('10.0000', $this->balance($org, $showroom, $variant)->on_hand);
        $this->assertSame('0.0000', $this->balance($org, $showroom, $variant)->available);
        app(FulfillSalesOrderAction::class)->execute($owner, $order->fresh());
        $this->assertSame('0.0000', $this->balance($org, $showroom, $variant)->on_hand);
    }

    // §13.12 + §13.14 — combined-reason receipt still conserves quantity
    public function test_combined_reason_request_receipt_conserves_quantity(): void
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context('5.0000', '30.0000');
        $this->enableReplenishment($org, $showroom, '2.0000');
        $order = $this->confirmedPosOrder($owner, $org, $store, $showroom, $variant, '10.0000');
        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();

        // 5 order + 2 replenishment = 7 physical units
        $this->assertSame(7.0, $request->lines()->get()->reduce(fn ($c, $l) => $c + (float) $l->quantity, 0.0));
        $this->assertDatabaseCount('stock_transfers', 0);

        $this->drive($owner, $request);

        // Depot 30 → 23, Showroom 5 → 12 ; order holds 10, 2 free for display
        $this->assertSame('23.0000', $this->balance($org, $depot, $variant)->on_hand);
        $this->assertSame('12.0000', $this->balance($org, $showroom, $variant)->on_hand);
        $this->assertSame('2.0000', $this->balance($org, $showroom, $variant)->available);
        $this->assertDatabaseCount('stock_transfers', 1);
    }
}
