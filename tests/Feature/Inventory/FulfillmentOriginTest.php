<?php

namespace Tests\Feature\Inventory;

use App\Actions\Fulfillment\HandFulfillmentToCarrierAction;
use App\Actions\Fulfillment\StartFulfillmentPreparationAction;
use App\Actions\Inventory\CreateOrderTransferRequestsAction;
use App\Actions\Inventory\PlanShowroomReplenishmentAction;
use App\Actions\Inventory\PrepareTransferRequestAction;
use App\Actions\Inventory\ReceiveTransferRequestAction;
use App\Actions\Inventory\ShipTransferRequestAction;
use App\Actions\Sales\CancelSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\ConvertOrderToShowroomOriginAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Enums\PosFulfillmentOrigin;
use App\Enums\SalesOrderFulfillmentStatus;
use App\Enums\TransferRequestStatus;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\Store;
use App\Models\TransferRequest;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentSetting;
use App\Services\ShowroomReplenishmentPlanner;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\PosTestCase;

/**
 * Per-order fulfillment origin (Showroom vs direct dispatch), independent of
 * pickup / delivery, through the REAL POS checkout endpoints. Scenario of the
 * live case SO-000041: Shure BETA 58A, Showroom 1, SP 4, Showroom minimum 2,
 * open general replenishment of 1, customer order of 3.
 */
class FulfillmentOriginTest extends PosTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private Warehouse $showroom;

    private Warehouse $sp;

    private ProductVariant $beta;

    private ProductVariant $other;

    private function world(int $showroom = 1, int $sp = 4): void
    {
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->org, $this->owner);
        $this->showroom = $this->createWarehouse($this->org, 'Showroom');
        $this->sp = $this->createWarehouse($this->org, 'Entrepôt SP');
        $tax = $this->createTaxRate($this->org, 'TVA 20', '20.0000');
        $this->activate($this->owner, $this->org, $this->store);
        $this->beta = $this->createProduct($this->org, 'Shure BETA 58A', 'BETA58A', ['default_sale_price' => '100.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->other = $this->createProduct($this->org, 'Shure SM57', 'SM57', ['default_sale_price' => '80.0000', 'tax_rate_id' => $tax->id])->variants->first();
        if ($showroom > 0) {
            $this->openStock($this->owner, $this->org, $this->showroom, $this->beta, "{$showroom}.0000");
        }
        $this->openStock($this->owner, $this->org, $this->sp, $this->beta, "{$sp}.0000");
        $this->openStock($this->owner, $this->org, $this->sp, $this->other, '5.0000');

        $setting = new WarehouseReplenishmentSetting;
        $setting->organization_id = $this->org->id;
        $setting->warehouse_id = $this->showroom->id;
        $setting->auto_replenish = true;
        $setting->default_minimum_quantity = '2.0000';
        $setting->save();
    }

    /** Existing general replenishment (minimum sweep): BETA 1, SM57 2 — both SP → Showroom. */
    private function generalRequest(): TransferRequest
    {
        $ids = app(PlanShowroomReplenishmentAction::class)->execute($this->owner, $this->org, $this->showroom);
        $this->assertCount(1, $ids);

        return TransferRequest::query()->findOrFail($ids[0]);
    }

    /** Real POS flow: draft → line (Showroom warehouse) → delivery method / origin → checkout. */
    private function checkout(string $mode, ?string $origin, string $qty = '3', ?string $operationId = null): SalesOrder
    {
        $draft = $this->createPosDraft($this->owner, $this->org, $this->store, $this->showroom);
        $this->actingAs($this->owner)->postJson(route('pos.drafts.lines.store', $draft), $this->posCatalogLine($this->beta, ['quantity' => $qty]))->assertOk();
        $this->actingAs($this->owner)->patchJson(route('pos.drafts.update', $draft), array_filter([
            'fulfillment_mode' => $mode,
            'fulfillment_origin' => $origin,
            'delivery_address' => $mode === 'delivery' ? 'Rue 1, Casablanca' : null,
            'delivery_phone' => $mode === 'delivery' ? '0600000000' : null,
        ]))->assertOk();
        $this->actingAs($this->owner)->post(route('pos.sales.store'), $this->posDraftCheckoutPayload($draft->fresh(), [], array_filter([
            'client_operation_id' => $operationId ?? (string) Str::uuid(),
            'fulfillment_mode' => $mode,
        ])))->assertSessionHasNoErrors();

        return SalesOrder::query()->findOrFail($draft->id);
    }

    /** @return array<string, string> "reason" => qty on the order's SP request */
    private function orderLines(SalesOrder $order): array
    {
        $request = TransferRequest::query()->where('sales_order_id', $order->id)->where('source_warehouse_id', $this->sp->id)->with('lines')->first();

        return $request ? $request->lines->where('product_variant_id', $this->beta->id)
            ->mapWithKeys(fn ($l) => [$l->reason->value => $l->quantity])->sortKeys()->all() : [];
    }

    private function orderRequest(SalesOrder $order): TransferRequest
    {
        return TransferRequest::query()->where('sales_order_id', $order->id)->where('source_warehouse_id', $this->sp->id)->firstOrFail();
    }

    /** Units still to move out of SP for BETA (active requests only). */
    private function activeFromSp(?ProductVariant $variant = null): float
    {
        return (float) TransferRequest::query()->where('organization_id', $this->org->id)
            ->where('source_warehouse_id', $this->sp->id)
            ->whereIn('status', ShowroomReplenishmentPlanner::ACTIVE_STATUSES)
            ->with('lines')->get()->flatMap->lines
            ->where('product_variant_id', ($variant ?? $this->beta)->id)->sum(fn ($l) => (float) $l->quantity);
    }

    /** @return array<int, array{qty: string, reservation: string}> warehouse id => allocation for the order */
    private function allocations(SalesOrder $order): array
    {
        return SalesOrderInventoryAllocation::query()
            ->whereIn('sales_order_line_id', $order->lines()->pluck('id'))
            ->with('inventoryReservation')->get()
            ->mapWithKeys(fn ($a) => [(int) $a->warehouse_id => ['qty' => $a->quantity, 'reservation' => $a->inventoryReservation?->status->value]])
            ->all();
    }

    private function moveThrough(TransferRequest $request): void
    {
        app(PrepareTransferRequestAction::class)->execute($this->owner, $request->fresh());
        app(ShipTransferRequestAction::class)->execute($this->owner, $request->fresh());
        app(ReceiveTransferRequestAction::class)->execute($this->owner, $request->fresh());
    }

    private function carrier(): OrganizationContact
    {
        $carrier = new OrganizationContact;
        $carrier->forceFill(['organization_id' => $this->org->id, 'full_name' => 'Amana', 'contact_type' => OrganizationContact::TYPE_TRANSPORT, 'active' => true])->save();

        return $carrier;
    }

    // === 1 + 5. Pickup + Showroom — the exact scenario ======================

    public function test_pickup_from_showroom_transfers_two_for_the_customer_and_two_for_the_minimum(): void
    {
        $this->world();
        $general = $this->generalRequest();
        $order = $this->checkout('pickup', null);

        $this->assertSame(PosFulfillmentOrigin::Showroom, $order->pos_fulfillment_origin);
        $this->assertSame(['minimum_replenishment' => '2.0000', 'order_fulfillment' => '2.0000'], $this->orderLines($order));
        $this->assertSame(4.0, $this->activeFromSp(), 'Total from SP must be 4, never 5.');
        // General: BETA moved (not duplicated), unrelated SM57 untouched.
        $general->refresh()->load('lines');
        $this->assertSame(TransferRequestStatus::Requested, $general->status);
        $this->assertNull($general->lines->firstWhere('product_variant_id', $this->beta->id));
        $this->assertSame('2.0000', $general->lines->firstWhere('product_variant_id', $this->other->id)->quantity);
        // Customer reservations: 1 Showroom + 2 SP, never duplicated.
        $this->assertSame([$this->showroom->id => ['qty' => '1.0000', 'reservation' => 'active'], $this->sp->id => ['qty' => '2.0000', 'reservation' => 'active']], $this->allocations($order));
        $this->assertSame('2.0000', $this->balance($this->org, $this->sp, $this->beta)->reserved);
        $this->assertSame('1.0000', $this->balance($this->org, $this->showroom, $this->beta)->reserved);
        $this->assertSame(SalesOrderFulfillmentStatus::Unfulfilled, $order->fulfillment_status);
        // Audit: origin recorded on the order.
        $log = AuditLog::query()->where('event', 'sales_order.pos_fulfillment_origin_selected')->where('auditable_id', $order->id)->firstOrFail();
        $this->assertSame(['pickup', 'showroom'], [$log->new_values['fulfillment_mode'], $log->new_values['fulfillment_origin']]);
    }

    public function test_detail_page_shows_the_authoritative_breakdown_for_showroom_origin(): void
    {
        $this->world();
        $this->generalRequest();
        $order = $this->checkout('delivery', 'showroom');

        $this->actingAs($this->owner)->get(route('inventory.transfer-requests.show', $this->orderRequest($order)))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('request.customer_transfer', true)
                ->where('request.sales_order.fulfillment_mode', 'delivery')
                ->where('request.sales_order.fulfillment_origin', 'showroom')
                ->where('breakdown.0.customer_quantity', '3.0000')
                ->where('breakdown.0.covered_at_destination', '1.0000')
                ->where('breakdown.0.reserved_at_source', '2.0000')
                ->where('breakdown.0.for_customer', '2.0000')
                ->where('breakdown.0.replenishment', '2.0000')
                ->where('breakdown.0.reallocated', '1.0000')
                ->where('breakdown.0.total', '4.0000')
                ->where('breakdown.0.projected_after_fulfillment', '2.0000')
                ->where('breakdown.0.minimum', '2.0000')
                ->where('breakdown.0.direct_dispatch', null)
                ->where('breakdown.0.source_transferable', '2.0000')
                ->where('breakdown.0.shortage', '0.0000')
                ->etc());
    }

    // === 2. Delivery + Showroom ==============================================

    public function test_delivery_from_showroom_routes_the_remote_units_and_blocks_dispatch_until_received(): void
    {
        $this->world();
        $this->generalRequest();
        $order = $this->checkout('delivery', 'showroom');

        $this->assertSame(['minimum_replenishment' => '2.0000', 'order_fulfillment' => '2.0000'], $this->orderLines($order));
        $this->assertSame(4.0, $this->activeFromSp());
        $this->assertTrue($order->awaitingReplenishment());

        // 8. No preparation / hand-over / direct delivery before the transfer arrives.
        foreach ([
            fn () => app(StartFulfillmentPreparationAction::class)->execute($this->owner, $order->fresh(), [$order->lines()->value('id') => '1']),
            fn () => app(HandFulfillmentToCarrierAction::class)->execute($this->owner, $order->fresh(), ['carrier_contact_id' => $this->carrier()->id, 'handed_at' => now()->subMinute()->toDateTimeString(), 'items' => [$order->lines()->value('id') => '3']]),
            fn () => app(FulfillSalesOrderAction::class)->execute($this->owner, $order->fresh()),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Dispatch before the customer transfer is received must be refused.');
            } catch (ValidationException) {
            }
        }
        $this->assertSame('4.0000', $this->balance($this->org, $this->sp, $this->beta)->on_hand, 'No stock left SP.');
    }

    // === 7. Reservation relocation after receipt, fulfilled at the Showroom ====

    public function test_receipt_relocates_the_customer_reservation_and_fulfillment_consumes_it_at_the_showroom(): void
    {
        $this->world();
        $this->generalRequest();
        $order = $this->checkout('delivery', 'showroom');
        $this->moveThrough($this->orderRequest($order));

        // The 2 customer units now sit at the Showroom, still reserved for the SAME order.
        $this->assertSame([$this->showroom->id => ['qty' => '3.0000', 'reservation' => 'active']], $this->allocations($order));
        $this->assertSame('0.0000', $this->balance($this->org, $this->sp, $this->beta)->reserved);
        $this->assertSame('0.0000', $this->balance($this->org, $this->sp, $this->beta)->on_hand);
        $this->assertSame('5.0000', $this->balance($this->org, $this->showroom, $this->beta)->on_hand);
        $this->assertSame('3.0000', $this->balance($this->org, $this->showroom, $this->beta)->reserved);
        $this->assertFalse($order->fresh()->awaitingReplenishment());

        // 10. Partial dispatch (1), then the rest (2) — consumed at the Showroom only, once.
        $line = $order->lines()->value('id');
        $carrier = $this->carrier();
        app(HandFulfillmentToCarrierAction::class)->execute($this->owner, $order->fresh(), ['carrier_contact_id' => $carrier->id, 'handed_at' => now()->subMinute()->toDateTimeString(), 'items' => [$line => '1']]);
        $this->assertSame(SalesOrderFulfillmentStatus::PartiallyFulfilled, $order->fresh()->fulfillment_status);
        $this->assertSame('4.0000', $this->balance($this->org, $this->showroom, $this->beta)->on_hand);
        app(HandFulfillmentToCarrierAction::class)->execute($this->owner, $order->fresh(), ['carrier_contact_id' => $carrier->id, 'handed_at' => now()->subMinute()->toDateTimeString(), 'items' => [$line => '2']]);
        $this->assertSame(SalesOrderFulfillmentStatus::Fulfilled, $order->fresh()->fulfillment_status);
        $this->assertSame('2.0000', $this->balance($this->org, $this->showroom, $this->beta)->on_hand, 'Showroom keeps its minimum of 2.');
        $this->assertSame('0.0000', $this->balance($this->org, $this->showroom, $this->beta)->reserved);
        $this->assertSame('0.0000', $this->balance($this->org, $this->sp, $this->beta)->on_hand, 'No double deduction at SP.');
    }

    // === 3 + 9. Delivery + Direct dispatch ===================================

    public function test_delivery_direct_dispatch_creates_no_customer_transfer_and_keeps_replenishment_separate(): void
    {
        $this->world();
        $general = $this->generalRequest();
        $order = $this->checkout('delivery', null); // delivery default = direct (historical behaviour)

        $this->assertSame(PosFulfillmentOrigin::Direct, $order->pos_fulfillment_origin);
        $this->assertSame(['minimum_replenishment' => '1.0000'], $this->orderLines($order));
        // The general BETA line is NOT pulled onto the order: no customer transfer will physically happen.
        $this->assertSame('1.0000', $general->fresh('lines')->lines->firstWhere('product_variant_id', $this->beta->id)->quantity);
        $this->assertSame(2.0, $this->activeFromSp(), 'Replenishment only: general 1 + top-up 1.');
        $this->assertFalse($order->awaitingReplenishment());

        $this->actingAs($this->owner)->get(route('inventory.transfer-requests.show', $this->orderRequest($order)))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('request.customer_transfer', false)
                ->where('request.sales_order.fulfillment_origin', 'direct')
                ->where('breakdown.0.customer_quantity', '3.0000')
                ->where('breakdown.0.covered_at_destination', '1.0000') // never "3 covered by Showroom"
                ->where('breakdown.0.direct_dispatch', '2.0000')
                ->where('breakdown.0.for_customer', '0.0000')
                ->where('breakdown.0.customer_allocations.1.warehouse', 'Entrepôt SP')
                ->where('breakdown.0.customer_allocations.1.reserved', '2.0000')
                ->etc());

        // 9. Dispatch consumes the reservations where they are: SP 2 + Showroom 1.
        app(FulfillSalesOrderAction::class)->execute($this->owner, $order->fresh());
        $this->assertSame(['2.0000', '0.0000'], [$this->balance($this->org, $this->sp, $this->beta)->on_hand, $this->balance($this->org, $this->sp, $this->beta)->reserved]);
        $this->assertSame(['0.0000', '0.0000'], [$this->balance($this->org, $this->showroom, $this->beta)->on_hand, $this->balance($this->org, $this->showroom, $this->beta)->reserved]);
        $this->assertSame(0, TransferRequest::query()->where('organization_id', $this->org->id)->whereHas('lines', fn ($q) => $q->where('reason', 'order_fulfillment'))->count(), 'No phantom customer transfer.');
    }

    // === 4. Pickup + Direct is refused, never silently switched ==============

    public function test_pickup_with_direct_dispatch_is_rejected_with_an_explanation(): void
    {
        $this->world();
        $draft = $this->createPosDraft($this->owner, $this->org, $this->store, $this->showroom);
        $this->actingAs($this->owner)->postJson(route('pos.drafts.lines.store', $draft), $this->posCatalogLine($this->beta, ['quantity' => '3']))->assertOk();

        $this->actingAs($this->owner)->patchJson(route('pos.drafts.update', $draft), ['fulfillment_mode' => 'pickup', 'fulfillment_origin' => 'direct'])
            ->assertUnprocessable()->assertJsonValidationErrors('fulfillment_origin');
        $this->assertNull($draft->fresh()->pos_fulfillment_origin);

        $this->actingAs($this->owner)->post(route('pos.sales.store'), $this->posDraftCheckoutPayload($draft->fresh(), [], ['fulfillment_origin' => 'direct']))
            ->assertSessionHasErrors('fulfillment_origin');
        $this->assertSame('draft', $draft->fresh()->status->value);
        $this->assertSame('0.0000', $this->balance($this->org, $this->sp, $this->beta)->reserved);

        // Switching to delivery resets to its default; Showroom stays selectable for delivery.
        $this->actingAs($this->owner)->patchJson(route('pos.drafts.update', $draft), ['fulfillment_mode' => 'delivery'])
            ->assertOk()->assertJsonPath('active_sale.checkout.fulfillment_origin', 'direct');
        $this->actingAs($this->owner)->patchJson(route('pos.drafts.update', $draft), ['fulfillment_origin' => 'showroom'])
            ->assertOk()->assertJsonPath('active_sale.checkout.fulfillment_origin', 'showroom');
        $this->actingAs($this->owner)->patchJson(route('pos.drafts.update', $draft), ['fulfillment_mode' => 'pickup'])
            ->assertOk()->assertJsonPath('active_sale.checkout.fulfillment_origin', 'showroom');
    }

    // === 6 + 13. Reconciliation & retries never duplicate ===================

    public function test_repeated_checkout_and_planning_never_duplicate_quantities_or_reservations(): void
    {
        $this->world();
        $this->generalRequest();
        $operation = (string) Str::uuid();
        $order = $this->checkout('delivery', 'showroom', '3', $operation);

        // Same client operation replayed: same order, nothing new.
        $this->actingAs($this->owner)->post(route('pos.sales.store'), $this->posDraftCheckoutPayload($order->fresh(), [], ['client_operation_id' => $operation, 'fulfillment_mode' => 'delivery']));
        // The planner itself re-run (retried confirmation): idempotent.
        app(CreateOrderTransferRequestsAction::class)->createForConfirmedOrder($this->owner, $order->fresh(['lines.allocations', 'organization', 'store']));
        app(PlanShowroomReplenishmentAction::class)->execute($this->owner, $this->org, $this->showroom);

        $this->assertSame(1, SalesOrder::query()->where('organization_id', $this->org->id)->where('status', 'confirmed')->count());
        $this->assertSame(['minimum_replenishment' => '2.0000', 'order_fulfillment' => '2.0000'], $this->orderLines($order));
        $this->assertSame(4.0, $this->activeFromSp());
        $this->assertSame(2.0, $this->activeFromSp($this->other), 'Unrelated SM57 general line unchanged.');
        $this->assertSame('2.0000', $this->balance($this->org, $this->sp, $this->beta)->reserved);
        $this->assertSame(2, \App\Models\InventoryReservation::query()->where('organization_id', $this->org->id)->where('status', 'active')->count());
    }

    // === 10. Cancellation of a Showroom-origin order ==========================

    public function test_cancelling_a_showroom_origin_order_releases_reservations_and_drops_only_the_customer_portion(): void
    {
        $this->world();
        $this->generalRequest();
        $order = $this->checkout('delivery', 'showroom');
        $request = $this->orderRequest($order);

        app(CancelSalesOrderAction::class)->execute($this->owner, $order->fresh(), 'Client a annulé');

        $request->refresh()->load('lines');
        $this->assertNull($request->sales_order_id, 'Replenishment survives, detached from the cancelled order.');
        $this->assertSame(['minimum_replenishment'], $request->lines->pluck('reason')->map->value->unique()->values()->all());
        $this->assertSame('0.0000', $this->balance($this->org, $this->sp, $this->beta)->reserved);
        $this->assertSame('0.0000', $this->balance($this->org, $this->showroom, $this->beta)->reserved);
    }

    // === 11. Historical orders keep their behaviour ===========================

    public function test_legacy_orders_without_a_stored_origin_keep_their_historical_routing(): void
    {
        $this->world(1, 8);
        // Delivery first (2 = Showroom 1 + SP 1): direct, only the minimum top-up.
        // Then pickup (3, all from SP now): Showroom, a protected customer line;
        // the minimum is already covered by the delivery order's top-up.
        foreach (['delivery' => ['minimum_replenishment' => '2.0000'], 'pickup' => ['order_fulfillment' => '3.0000']] as $mode => $expected) {
            $draft = $this->createPosDraft($this->owner, $this->org, $this->store, $this->showroom);
            $this->addCatalogLine($this->owner, $draft, $this->beta, $this->showroom, ['quantity' => $mode === 'pickup' ? '3.0000' : '2.0000']);
            $draft->pos_fulfillment_mode = $mode;
            $draft->save(); // origin stays NULL, as for every order confirmed before the field existed
            $order = app(ConfirmSalesOrderAction::class)->execute($this->owner, $draft->fresh());

            $this->assertNull($order->fresh()->pos_fulfillment_origin);
            $this->assertSame($mode === 'pickup' ? PosFulfillmentOrigin::Showroom : PosFulfillmentOrigin::Direct, $order->fresh()->fulfillmentOrigin());
            $this->assertSame($expected, $this->orderLines($order), "legacy {$mode}");
        }
    }

    // === 8 (repair). Explicit conversion of an existing direct order (SO-000041) ===

    public function test_existing_direct_order_can_be_converted_explicitly_with_preview_and_audit(): void
    {
        $this->world();
        $general = $this->generalRequest();
        // Reproduces SO-000041: confirmed as delivery before the choice existed.
        $draft = $this->createPosDraft($this->owner, $this->org, $this->store, $this->showroom);
        $this->addCatalogLine($this->owner, $draft, $this->beta, $this->showroom, ['quantity' => '3.0000']);
        $draft->pos_fulfillment_mode = 'delivery';
        $draft->save();
        $order = app(ConfirmSalesOrderAction::class)->execute($this->owner, $draft->fresh());
        $this->assertSame(['minimum_replenishment' => '1.0000'], $this->orderLines($order)); // the live wrong picture

        // Preview: reports the intended change, saves nothing.
        $preview = app(ConvertOrderToShowroomOriginAction::class)->execute($this->owner, $order, apply: false);
        $this->assertFalse($preview['applied']);
        $after = collect($preview['after'])->firstWhere('sales_order_id', $order->id);
        $this->assertEqualsCanonicalizing(['order_fulfillment:2.0000', 'minimum_replenishment:2.0000'], collect($after['lines'])->map(fn ($l) => "{$l['reason']}:{$l['quantity']}")->all());
        $this->assertSame(['minimum_replenishment' => '1.0000'], $this->orderLines($order));
        $this->assertNull($order->fresh()->pos_fulfillment_origin);
        $this->assertSame(0, AuditLog::query()->where('event', 'sales_order.fulfillment_origin_converted')->count());

        // Apply via the artisan command (explicit, single order, named actor).
        $this->artisan('sales:convert-to-showroom-origin', ['order_number' => $order->order_number, '--organization' => $this->org->id, '--actor' => $this->owner->email, '--apply' => true])
            ->assertSuccessful();
        $this->assertSame(PosFulfillmentOrigin::Showroom, $order->fresh()->pos_fulfillment_origin);
        $this->assertSame(['minimum_replenishment' => '2.0000', 'order_fulfillment' => '2.0000'], $this->orderLines($order));
        $this->assertSame(4.0, $this->activeFromSp());
        $this->assertNull($general->fresh('lines')->lines->firstWhere('product_variant_id', $this->beta->id), 'General BETA moved, not duplicated.');
        $this->assertSame('2.0000', $general->fresh('lines')->lines->firstWhere('product_variant_id', $this->other->id)->quantity);
        $this->assertSame('2.0000', $this->balance($this->org, $this->sp, $this->beta)->reserved, 'Reservations preserved, none added.');
        $log = AuditLog::query()->where('event', 'sales_order.fulfillment_origin_converted')->firstOrFail();
        $this->assertSame('showroom', $log->new_values['fulfillment_origin']);
        $this->assertNotEmpty($log->old_values['transfer_requests']);

        // Repeated conversion is refused (already Showroom) — never duplicated.
        $this->artisan('sales:convert-to-showroom-origin', ['order_number' => $order->order_number, '--organization' => $this->org->id, '--actor' => $this->owner->email, '--apply' => true])
            ->assertFailed();
        $this->assertSame(4.0, $this->activeFromSp());
    }

    public function test_conversion_is_refused_once_logistics_started_or_without_permission(): void
    {
        $this->world();
        $draft = $this->createPosDraft($this->owner, $this->org, $this->store, $this->showroom);
        $this->addCatalogLine($this->owner, $draft, $this->beta, $this->showroom, ['quantity' => '3.0000']);
        $draft->pos_fulfillment_mode = 'delivery';
        $draft->save();
        $order = app(ConfirmSalesOrderAction::class)->execute($this->owner, $draft->fresh());
        app(PrepareTransferRequestAction::class)->execute($this->owner, $this->orderRequest($order));

        try {
            app(ConvertOrderToShowroomOriginAction::class)->execute($this->owner, $order, apply: true);
            $this->fail('Conversion must be refused while a request is in preparation.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('préparation', collect($e->errors())->flatten()->implode(' '));
        }
        $this->assertNull($order->fresh()->pos_fulfillment_origin);

        // 14. A user of another organization cannot convert this order.
        $intruder = User::factory()->create();
        $this->createOrganization($intruder, 'Autre');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(ConvertOrderToShowroomOriginAction::class)->execute($intruder, $order, apply: true);
    }

    // === 14. Tenant isolation on the draft origin ==============================

    public function test_another_organization_cannot_change_the_origin_of_a_draft(): void
    {
        $this->world();
        $draft = $this->createPosDraft($this->owner, $this->org, $this->store, $this->showroom);

        $intruder = User::factory()->create();
        $otherOrg = $this->createOrganization($intruder, 'Autre');
        $otherStore = $this->createStore($otherOrg, $intruder);
        $this->activate($intruder, $otherOrg, $otherStore);

        $status = $this->actingAs($intruder)->patchJson(route('pos.drafts.update', $draft), ['fulfillment_mode' => 'delivery', 'fulfillment_origin' => 'showroom'])->status();
        $this->assertContains($status, [403, 404]);
        $this->assertSame('pickup', $draft->fresh()->pos_fulfillment_mode);
        $this->assertNull($draft->fresh()->pos_fulfillment_origin);
    }

    // === Sales order page: routing card ======================================

    public function test_order_page_shows_delivery_method_origin_and_reservations_by_warehouse(): void
    {
        $this->world();
        $order = $this->checkout('delivery', 'showroom');

        $this->actingAs($this->owner)->get(route('sales.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fulfillmentRouting.mode', 'delivery')
                ->where('fulfillmentRouting.origin', 'showroom')
                ->where('fulfillmentRouting.explicit', true)
                ->where('fulfillmentRouting.reserved_outside_showroom', true)
                ->where('fulfillmentRouting.warehouses.0.reserved', '1.0000')
                ->where('fulfillmentRouting.warehouses.1.reserved', '2.0000')
                ->where('awaitingReplenishment', true)
                ->etc());
    }
}
