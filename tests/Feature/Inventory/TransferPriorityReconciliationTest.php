<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\CancelTransferRequestAction;
use App\Actions\Inventory\CreateOrderTransferRequestsAction;
use App\Actions\Inventory\PlanShowroomReplenishmentAction;
use App\Actions\Inventory\PrepareTransferRequestAction;
use App\Actions\Inventory\ShipTransferRequestAction;
use App\Actions\Inventory\UpdateTransferRequestLinesAction;
use App\Actions\Sales\CancelSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Enums\TransferRequestReason;
use App\Enums\TransferRequestStatus;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\TransferRequest;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentSetting;
use App\Services\ShowroomReplenishmentPlanner;
use Inertia\Testing\AssertableInertia;
use Tests\Support\PosTestCase;

/**
 * Customer orders take priority over general minimum replenishment, and an
 * open general replenishment is reconciled (moved, never duplicated) onto the
 * customer request when an order needs the same product on the same route.
 */
class TransferPriorityReconciliationTest extends PosTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private Warehouse $showroom;

    private Warehouse $sp;

    private ?Warehouse $depot2 = null;

    /** @var list<ProductVariant> */
    private array $variants = [];

    /**
     * @param  list<int>  $showroom  showroom on-hand per variant
     * @param  list<int>  $sp  SP on-hand per variant
     * @param  list<int>|null  $depot2  optional second depot on-hand per variant
     */
    private function world(array $showroom, array $sp, ?array $depot2 = null, string $minimum = '2.0000'): void
    {
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->org, $this->owner);
        $this->showroom = $this->createWarehouse($this->org, 'Showroom');
        $this->sp = $this->createWarehouse($this->org, 'SP');
        if ($depot2 !== null) {
            $this->depot2 = $this->createWarehouse($this->org, 'Dépôt 2');
        }
        $tax = $this->createTaxRate($this->org, 'TVA 20', '20.0000');
        $this->activate($this->owner, $this->org, $this->store);

        $names = ['Shure BETA 58A', 'Shure SM57', 'Sennheiser e835'];
        $this->variants = [];
        foreach ($showroom as $i => $qty) {
            $variant = $this->createProduct($this->org, $names[$i] ?? "Produit {$i}", "SKU-{$i}", ['default_sale_price' => '100.0000', 'tax_rate_id' => $tax->id])->variants->first();
            $this->variants[] = $variant;
            if ($qty > 0) {
                $this->openStock($this->owner, $this->org, $this->showroom, $variant, "{$qty}.0000");
            }
            if (($sp[$i] ?? 0) > 0) {
                $this->openStock($this->owner, $this->org, $this->sp, $variant, "{$sp[$i]}.0000");
            }
            if ($depot2 !== null && ($depot2[$i] ?? 0) > 0) {
                $this->openStock($this->owner, $this->org, $this->depot2, $variant, "{$depot2[$i]}.0000");
            }
        }

        $setting = new WarehouseReplenishmentSetting;
        $setting->organization_id = $this->org->id;
        $setting->warehouse_id = $this->showroom->id;
        $setting->auto_replenish = true;
        $setting->default_minimum_quantity = $minimum;
        $setting->save();
    }

    private function sweep(): array
    {
        return app(PlanShowroomReplenishmentAction::class)->execute($this->owner, $this->org, $this->showroom);
    }

    private function pickupOrder(ProductVariant $variant, string $qty): SalesOrder
    {
        $draft = $this->createPosDraft($this->owner, $this->org, $this->store, $this->showroom);
        $this->addCatalogLine($this->owner, $draft, $variant, $this->showroom, ['quantity' => $qty]);

        return app(ConfirmSalesOrderAction::class)->execute($this->owner, $draft->fresh());
    }

    /** @return array<string, string> "status:reason" => qty for one variant across all requests from a source */
    private function demand(ProductVariant $variant, ?Warehouse $source = null): array
    {
        $out = [];
        foreach (TransferRequest::query()->where('organization_id', $this->org->id)
            ->when($source, fn ($q) => $q->where('source_warehouse_id', $source->id))
            ->with('lines')->orderBy('id')->get() as $request) {
            foreach ($request->lines->where('product_variant_id', $variant->id) as $line) {
                $key = $request->request_number.':'.$request->status->value.':'.$line->reason->value;
                $out[$key] = $line->quantity;
            }
        }

        return $out;
    }

    /** Units still to move out of a source for a variant (active requests only). */
    private function activeTotal(ProductVariant $variant, Warehouse $source): float
    {
        return (float) TransferRequest::query()->where('organization_id', $this->org->id)
            ->where('source_warehouse_id', $source->id)
            ->whereIn('status', ShowroomReplenishmentPlanner::ACTIVE_STATUSES)
            ->with('lines')->get()
            ->flatMap->lines->where('product_variant_id', $variant->id)->sum(fn ($l) => (float) $l->quantity);
    }

    private function orderRequest(SalesOrder $order, ?Warehouse $source = null): TransferRequest
    {
        return TransferRequest::query()->where('sales_order_id', $order->id)
            ->where('source_warehouse_id', ($source ?? $this->sp)->id)->with('lines')->firstOrFail();
    }

    /** @return array<string, string> reason => qty */
    private function byReason(TransferRequest $request, ProductVariant $variant): array
    {
        return $request->fresh('lines')->lines->where('product_variant_id', $variant->id)
            ->mapWithKeys(fn ($l) => [$l->reason->value => $l->quantity])->sortKeys()->all();
    }

    // === 1 + 2. Exact scenario =============================================

    public function test_exact_scenario_requires_four_units_from_sp_not_five(): void
    {
        $this->world([1], [4]);
        $beta = $this->variants[0];

        // Existing general replenishment: minimum 2, showroom 1 → 1 requested.
        $generalId = $this->sweep()[0];
        $general = TransferRequest::query()->findOrFail($generalId);
        $this->assertSame(['minimum_replenishment' => '1.0000'], $this->byReason($general, $beta));

        $order = $this->pickupOrder($beta, '3.0000'); // 1 from showroom + 2 from SP

        $request = $this->orderRequest($order);
        $this->assertSame(['minimum_replenishment' => '2.0000', 'order_fulfillment' => '2.0000'], $this->byReason($request, $beta));
        $this->assertSame(4.0, $this->activeTotal($beta, $this->sp), 'Total required from SP must be 4, not 5.');

        // The general 1 was moved (not duplicated): its request is cancelled with an explicit reason.
        $general->refresh();
        $this->assertSame(TransferRequestStatus::Cancelled, $general->status);
        $this->assertStringContainsString($request->request_number, $general->cancellation_reason);
        $this->assertStringContainsString($order->order_number, $general->cancellation_reason);
        $mr = $request->lines->firstWhere('reason', TransferRequestReason::MinimumReplenishment);
        $this->assertSame('1.0000', $mr->reallocated_quantity);

        // Audit on both sides with old / new quantities and the order.
        $this->assertDatabaseHas('audit_logs', ['event' => 'transfer_request.replenishment_reallocated_to_order', 'auditable_id' => $general->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'transfer_request.replenishment_received_from_general', 'auditable_id' => $request->id]);
        $log = \App\Models\AuditLog::query()->where('event', 'transfer_request.replenishment_reallocated_to_order')->firstOrFail();
        $this->assertSame('1.0000', $log->old_values['quantity']);
        $this->assertSame('0.0000', $log->new_values['quantity']);
        $this->assertSame($order->order_number, $log->new_values['sales_order_number']);

        // Customer reservation at SP untouched; nothing left to promise there.
        $this->assertSame('2.0000', $this->balance($this->org, $this->sp, $beta)->reserved);
        $stock = app(ShowroomReplenishmentPlanner::class)->transferableMap($this->org->id, $this->sp->id, [$beta->id]);
        $this->assertSame('0.0000', $stock[$beta->id]['transferable']);

        // A fresh sweep adds nothing: the minimum is covered once the customer is served.
        $this->assertSame([], $this->sweep());
    }

    public function test_detail_page_shows_the_quantity_breakdown_and_projection(): void
    {
        $this->world([1], [4]);
        $beta = $this->variants[0];
        $this->sweep();
        $order = $this->pickupOrder($beta, '3.0000');
        $request = $this->orderRequest($order);

        $this->actingAs($this->owner)->get(route('inventory.transfer-requests.show', $request))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inventory/TransferRequests/Show')
                ->where('request.type', 'order')
                ->where('request.priority', 'high')
                ->where('request.sales_order.order_number', $order->order_number)
                ->where('breakdown.0.customer_quantity', '3.0000')
                ->where('breakdown.0.covered_at_destination', '1.0000')
                ->where('breakdown.0.for_customer', '2.0000')
                ->where('breakdown.0.replenishment', '2.0000')
                ->where('breakdown.0.reallocated', '1.0000')
                ->where('breakdown.0.total', '4.0000')
                ->where('breakdown.0.other_active_incoming', '0.0000')
                ->where('breakdown.0.minimum', '2.0000')
                ->where('breakdown.0.projected_after_fulfillment', '2.0000')
                ->where('breakdown.0.source_on_hand', '4.0000')
                ->where('breakdown.0.source_reserved', '2.0000')
                ->where('breakdown.0.source_transferable', '2.0000')
                ->where('breakdown.0.shortage', '0.0000')
                ->has('history', fn (AssertableInertia $history) => $history->etc())
            );
    }

    // === 3. Unrelated products of the general request stay untouched =======

    public function test_unrelated_lines_of_the_general_request_remain_unchanged(): void
    {
        $this->world([1, 0], [4, 5]);
        [$beta, $sm57] = $this->variants;
        $general = TransferRequest::query()->findOrFail($this->sweep()[0]);
        $this->assertSame(['minimum_replenishment' => '2.0000'], $this->byReason($general, $sm57));

        $this->pickupOrder($beta, '3.0000');

        $general->refresh();
        $this->assertSame(TransferRequestStatus::Requested, $general->status, 'Request with other products is kept.');
        $this->assertSame([], $this->byReason($general, $beta), 'Only the matching product line moved.');
        $this->assertSame(['minimum_replenishment' => '2.0000'], $this->byReason($general, $sm57));
        $this->assertSame(4.0, $this->activeTotal($beta, $this->sp));
    }

    public function test_whole_general_line_moves_and_only_the_missing_top_up_is_added(): void
    {
        // Minimum 5: general asks 4. Order of 2 (1 showroom + 1 SP) → showroom
        // needs 5 after service; the move is bounded by that need.
        $this->world([1], [10], null, '5.0000');
        $beta = $this->variants[0];
        $general = TransferRequest::query()->findOrFail($this->sweep()[0]);
        $this->assertSame(['minimum_replenishment' => '4.0000'], $this->byReason($general, $beta));

        $order = $this->pickupOrder($beta, '2.0000');
        $request = $this->orderRequest($order);

        // Need after service = 5 − 0 = 5 ≥ 4 → the whole 4 moves, +1 top-up.
        $this->assertSame(['minimum_replenishment' => '5.0000', 'order_fulfillment' => '1.0000'], $this->byReason($request, $beta));
        $this->assertSame(TransferRequestStatus::Cancelled, $general->fresh()->status);
        $this->assertSame(6.0, $this->activeTotal($beta, $this->sp));
    }

    // === 4. Partial source stock and multi-warehouse sourcing ==============

    public function test_partial_source_stock_keeps_customer_first_and_reports_the_shortage(): void
    {
        $this->world([1], [3]);
        $beta = $this->variants[0];
        $this->sweep(); // general 1
        $order = $this->pickupOrder($beta, '3.0000'); // 2 reserved at SP → 1 free

        $request = $this->orderRequest($order);
        // Customer 2 + the 1 already-requested replenishment moved over; no free stock for more.
        $this->assertSame(['minimum_replenishment' => '1.0000', 'order_fulfillment' => '2.0000'], $this->byReason($request, $beta));
        $this->assertSame(3.0, $this->activeTotal($beta, $this->sp));
        $this->assertSame([], app(ShowroomReplenishmentPlanner::class)->promiseShortfalls($this->org->id, $this->sp->id, [$beta->id]));

        $this->actingAs($this->owner)->get(route('inventory.transfer-requests.show', $request))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('breakdown.0.projected_after_fulfillment', '1.0000') // below minimum 2 — shown, not fabricated
                ->where('breakdown.0.minimum', '2.0000'));
    }

    public function test_insufficient_source_stock_makes_the_general_replenishment_yield_to_the_customer(): void
    {
        $this->world([1], [2]);
        $beta = $this->variants[0];
        $general = TransferRequest::query()->findOrFail($this->sweep()[0]); // promises 1 of SP's 2
        $order = $this->pickupOrder($beta, '3.0000'); // reserves both SP units for the customer

        $general->refresh();
        $this->assertSame(TransferRequestStatus::Cancelled, $general->status);
        $this->assertStringContainsString('priorité', $general->cancellation_reason);
        $this->assertDatabaseHas('audit_logs', ['event' => 'transfer_request.replenishment_yielded_to_order', 'auditable_id' => $general->id]);

        $request = $this->orderRequest($order);
        $this->assertSame(['order_fulfillment' => '2.0000'], $this->byReason($request, $beta));
        $this->assertSame(2.0, $this->activeTotal($beta, $this->sp));
        $this->assertSame([], app(ShowroomReplenishmentPlanner::class)->promiseShortfalls($this->org->id, $this->sp->id, [$beta->id]));
    }

    public function test_multiwarehouse_order_never_over_promises_any_source(): void
    {
        $this->world([1], [1], [6]);
        $beta = $this->variants[0];
        $this->sweep(); // general replenishment of 1 from the first source with free stock
        $order = $this->pickupOrder($beta, '4.0000');

        $planner = app(ShowroomReplenishmentPlanner::class);
        foreach ([$this->sp, $this->depot2] as $source) {
            $this->assertSame([], $planner->promiseShortfalls($this->org->id, $source->id, [$beta->id]), "Over-promised at {$source->name}");
        }
        // Every customer unit is routed: allocations at remote sources == OF lines.
        $order->load('lines.allocations');
        $remoteAllocated = $order->lines->flatMap->allocations->where('warehouse_id', '!=', $this->showroom->id)->sum(fn ($a) => (float) $a->quantity);
        $ofRouted = TransferRequest::query()->where('sales_order_id', $order->id)->with('lines')->get()
            ->flatMap->lines->where('reason', TransferRequestReason::OrderFulfillment)->sum(fn ($l) => (float) $l->quantity);
        $this->assertSame($remoteAllocated, $ofRouted);

        // After service the showroom reaches exactly its minimum: free stock + all free incoming = 2.
        $incoming = $planner->incomingFreeMap($this->org->id, $this->showroom->id, [$beta->id]);
        $this->assertSame(2.0, (float) $planner->availableAt($this->org->id, $this->showroom->id, $beta->id) + (float) ($incoming[$beta->id] ?? 0));
        $this->assertSame([], $this->sweep());
    }

    // === 5. Requested / Preparing / Shipped general requests ===============

    public function test_preparing_and_shipped_general_requests_are_never_altered(): void
    {
        foreach (['preparing', 'shipped'] as $state) {
            $this->world([1], [4]);
            $beta = $this->variants[0];
            $general = TransferRequest::query()->findOrFail($this->sweep()[0]);
            app(PrepareTransferRequestAction::class)->execute($this->owner, $general);
            if ($state === 'shipped') {
                app(ShipTransferRequestAction::class)->execute($this->owner, $general->fresh());
            }

            $order = $this->pickupOrder($beta, '3.0000');

            $general->refresh();
            $this->assertSame($state, $general->status->value);
            $this->assertSame(['minimum_replenishment' => '1.0000'], $this->byReason($general, $beta), "{$state} quantity must not change");
            // Its unit still counts as incoming → the order request tops up 1, total still 4.
            $this->assertSame(['minimum_replenishment' => '1.0000', 'order_fulfillment' => '2.0000'], $this->byReason($this->orderRequest($order), $beta));
            $this->assertSame(4.0, $this->activeTotal($beta, $this->sp), $state);
        }
    }

    // === 6 + 7. Repeated / concurrent-style operations =====================

    public function test_repeated_confirmation_and_evaluation_never_duplicate_quantities(): void
    {
        $this->world([1], [4]);
        $beta = $this->variants[0];
        $this->sweep();
        $order = $this->pickupOrder($beta, '3.0000');
        $before = $this->demand($beta);

        $hook = app(CreateOrderTransferRequestsAction::class);
        for ($i = 0; $i < 3; $i++) {
            \Illuminate\Support\Facades\DB::transaction(fn () => $hook->createForConfirmedOrder($this->owner, $order->fresh(['lines.allocations', 'organization', 'store'])));
            $this->assertSame([], $this->sweep());
        }

        $this->assertSame($before, $this->demand($beta));
        $this->assertSame(2, TransferRequest::query()->where('organization_id', $this->org->id)->count());
    }

    public function test_editing_cancelling_and_reevaluating_after_reconciliation_stays_exact(): void
    {
        $this->world([1], [4]);
        $beta = $this->variants[0];
        $this->sweep();
        $order = $this->pickupOrder($beta, '3.0000');
        $request = $this->orderRequest($order);
        $of = $request->lines->firstWhere('reason', TransferRequestReason::OrderFulfillment);
        $mr = $request->lines->firstWhere('reason', TransferRequestReason::MinimumReplenishment);

        // Authorised user trims the optional replenishment 2 → 1 before preparation.
        app(UpdateTransferRequestLinesAction::class)->execute($this->owner, $request->fresh(), (int) $request->fresh()->lock_version, [
            ['id' => $of->id, 'product_variant_id' => $beta->id, 'quantity' => '2'],
            ['id' => $mr->id, 'product_variant_id' => $beta->id, 'quantity' => '1'],
        ]);
        $this->assertSame(['minimum_replenishment' => '1.0000', 'order_fulfillment' => '2.0000'], $this->byReason($request, $beta));

        // The next evaluation proposes exactly the missing unit — once.
        $created = $this->sweep();
        $this->assertCount(1, $created);
        $this->assertSame(4.0, $this->activeTotal($beta, $this->sp));
        $this->assertSame([], $this->sweep());

        // Cancelling that general top-up and evaluating again reproduces it exactly.
        app(CancelTransferRequestAction::class)->execute($this->owner, TransferRequest::query()->findOrFail($created[0]), 'Erreur de saisie');
        $this->assertSame(3.0, $this->activeTotal($beta, $this->sp));
        $this->sweep();
        $this->assertSame(4.0, $this->activeTotal($beta, $this->sp));
    }

    public function test_stale_editor_on_a_general_request_is_rejected_after_reallocation(): void
    {
        $this->world([1, 0], [4, 5]);
        [$beta, $sm57] = $this->variants;
        $general = TransferRequest::query()->findOrFail($this->sweep()[0]);
        $staleVersion = (int) $general->lock_version;

        $this->pickupOrder($beta, '3.0000'); // moves the BETA line → version bumped

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(UpdateTransferRequestLinesAction::class)->execute($this->owner, $general->fresh(), $staleVersion, [
            ['product_variant_id' => $beta->id, 'quantity' => '1'],
            ['product_variant_id' => $sm57->id, 'quantity' => '2'],
        ]);
    }

    // === 8. Protected customer allocations ================================

    public function test_customer_quantities_stay_protected_and_cancelling_the_order_keeps_the_replenishment(): void
    {
        $this->world([1], [4]);
        $beta = $this->variants[0];
        $this->sweep();
        $order = $this->pickupOrder($beta, '3.0000');
        $request = $this->orderRequest($order);
        $of = $request->lines->firstWhere('reason', TransferRequestReason::OrderFulfillment);
        $mr = $request->lines->firstWhere('reason', TransferRequestReason::MinimumReplenishment);

        // The customer portion cannot be edited away.
        try {
            app(UpdateTransferRequestLinesAction::class)->execute($this->owner, $request->fresh(), (int) $request->fresh()->lock_version, [
                ['id' => $of->id, 'product_variant_id' => $beta->id, 'quantity' => '1'],
                ['id' => $mr->id, 'product_variant_id' => $beta->id, 'quantity' => '2'],
            ]);
            $this->fail('Customer quantity must be protected.');
        } catch (\Illuminate\Validation\ValidationException) {
        }
        $this->assertSame('2.0000', $this->balance($this->org, $this->sp, $beta)->reserved);

        // Cancelling the order drops only the customer portion; the moved
        // replenishment survives (detached) so the showroom minimum is not lost.
        app(CancelSalesOrderAction::class)->execute($this->owner, $order->fresh(), 'Client a changé d’avis');
        $request->refresh();
        $this->assertNull($request->sales_order_id);
        $this->assertSame(TransferRequestStatus::Requested, $request->status);
        $this->assertSame(['minimum_replenishment' => '2.0000'], $this->byReason($request, $beta));
        $this->assertSame('0.0000', $this->balance($this->org, $this->sp, $beta)->reserved);
    }

    // === 10. List filters ==================================================

    public function test_list_filters_by_type_priority_status_and_warehouse(): void
    {
        $this->world([1, 0], [4, 5]);
        [$beta] = $this->variants;
        $this->sweep(); // general (SM57 stays, BETA moves)
        $order = $this->pickupOrder($beta, '3.0000');
        $orderRequest = $this->orderRequest($order);

        $get = fn (array $query) => $this->actingAs($this->owner)->get(route('inventory.transfer-requests.index', $query))->assertOk();

        $get(['type' => 'order'])->assertInertia(fn (AssertableInertia $p) => $p
            ->has('requests.data', 1)->where('requests.data.0.id', $orderRequest->id)
            ->where('requests.data.0.type', 'order')->where('requests.data.0.priority', 'high'));
        $get(['type' => 'replenishment'])->assertInertia(fn (AssertableInertia $p) => $p
            ->has('requests.data', 1)->where('requests.data.0.type', 'replenishment')->where('requests.data.0.priority', 'normal'));
        $get(['priority' => 'high', 'status' => 'open'])->assertInertia(fn (AssertableInertia $p) => $p->has('requests.data', 1));
        $get(['warehouse_id' => $this->sp->id])->assertInertia(fn (AssertableInertia $p) => $p->has('requests.data', 2)
            ->where('requests.data.0.id', $orderRequest->id) // open customer requests first
            ->where('summary.customer_open', 1)->where('summary.requested', 2));
        $get(['type' => 'manual'])->assertInertia(fn (AssertableInertia $p) => $p->has('requests.data', 0));
    }
}
