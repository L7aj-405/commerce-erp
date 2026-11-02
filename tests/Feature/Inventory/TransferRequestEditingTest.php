<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\CancelTransferRequestAction;
use App\Actions\Inventory\CreateManualTransferRequestAction;
use App\Actions\Inventory\CreateOrderTransferRequestsAction;
use App\Actions\Inventory\PlanShowroomReplenishmentAction;
use App\Actions\Inventory\PrepareTransferRequestAction;
use App\Actions\Inventory\ReceiveTransferRequestAction;
use App\Actions\Inventory\RestoreTransferRequestAction;
use App\Actions\Inventory\ShipTransferRequestAction;
use App\Actions\Inventory\UpdateTransferRequestLinesAction;
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
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\PosTestCase;

/**
 * Manual transfer requests, line editing, preparation review, cancellation /
 * restoration and replenishment determinism.
 */
class TransferRequestEditingTest extends PosTestCase
{
    private User $owner;

    private Organization $org;

    private Store $store;

    private Warehouse $showroom;

    private Warehouse $depot;

    /** @var list<ProductVariant> */
    private array $variants = [];

    /**
     * Showroom + depot, five catalogue variants. `$showroomStock[i]` /
     * `$depotStock[i]` seed variant i.
     *
     * @param  list<int>  $showroomStock
     * @param  list<int>  $depotStock
     */
    private function scenario(array $showroomStock = [0, 1, 2, 0, 1], array $depotStock = [10, 10, 10, 10, 10], ?string $defaultMinimum = '3.0000'): void
    {
        $this->owner = User::factory()->create();
        $this->org = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->org, $this->owner);
        $this->showroom = $this->createWarehouse($this->org, 'Showroom');
        $this->depot = $this->createWarehouse($this->org, 'Depot');
        $tax = $this->createTaxRate($this->org, 'TVA 20', '20.0000');
        $this->activate($this->owner, $this->org, $this->store);

        $this->variants = [];
        foreach ($showroomStock as $i => $qty) {
            $variant = $this->createProduct($this->org, "Produit {$i}", "SKU-{$i}", ['default_sale_price' => '100.0000', 'tax_rate_id' => $tax->id])->variants->first();
            $this->variants[] = $variant;
            if ($qty > 0) {
                $this->openStock($this->owner, $this->org, $this->showroom, $variant, "{$qty}.0000");
            }
            if (($depotStock[$i] ?? 0) > 0) {
                $this->openStock($this->owner, $this->org, $this->depot, $variant, "{$depotStock[$i]}.0000");
            }
        }

        if ($defaultMinimum !== null) {
            $setting = new WarehouseReplenishmentSetting;
            $setting->organization_id = $this->org->id;
            $setting->warehouse_id = $this->showroom->id;
            $setting->auto_replenish = true;
            $setting->default_minimum_quantity = $defaultMinimum;
            $setting->save();
        }
    }

    private function sweep(): array
    {
        return app(PlanShowroomReplenishmentAction::class)->execute($this->owner, $this->org, $this->showroom);
    }

    /** @return array<int, string> variant id => qty over the given requests */
    private function quantities(array $requestIds, ?string $reason = null): array
    {
        $out = [];
        foreach (TransferRequest::query()->whereIn('id', $requestIds)->with('lines')->get() as $request) {
            foreach ($request->lines as $line) {
                if ($reason !== null && $line->reason->value !== $reason) {
                    continue;
                }
                $out[(int) $line->product_variant_id] = number_format((float) ($out[(int) $line->product_variant_id] ?? 0) + (float) $line->quantity, 4, '.', '');
            }
        }
        ksort($out);

        return $out;
    }

    private function manual(array $lines, ?string $key = null, ?Warehouse $source = null, ?Warehouse $destination = null): TransferRequest
    {
        return app(CreateManualTransferRequestAction::class)->execute(
            $this->owner, $this->org,
            ($source ?? $this->depot)->id, ($destination ?? $this->showroom)->id,
            $lines, 'Mise en avant vitrine', $key,
        );
    }

    private function pickupOrder(ProductVariant $variant, string $qty): SalesOrder
    {
        $draft = $this->createPosDraft($this->owner, $this->org, $this->store, $this->showroom);
        $this->addCatalogLine($this->owner, $draft, $variant, $this->showroom, ['quantity' => $qty]);

        return app(ConfirmSalesOrderAction::class)->execute($this->owner, $draft->fresh());
    }

    private function assertValidationFails(callable $callback, ?string $key = null): ValidationException
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            if ($key !== null) {
                $this->assertArrayHasKey($key, $exception->errors(), json_encode($exception->errors()));
            }

            return $exception;
        }
        $this->fail('Expected a validation failure.');
    }

    // === 1. Manual creation ================================================

    public function test_manual_request_is_created_with_manual_lines_and_audit(): void
    {
        $this->scenario(defaultMinimum: null);
        [$a, $b] = $this->variants;

        $request = $this->manual([
            ['product_variant_id' => $a->id, 'quantity' => '4'],
            ['product_variant_id' => $b->id, 'quantity' => '2.5'],
        ]);

        $this->assertSame(TransferRequestStatus::Requested, $request->status);
        $this->assertNull($request->sales_order_id);
        $this->assertSame('Mise en avant vitrine', $request->note);
        $this->assertSame(['manual'], $request->lines()->pluck('reason')->map(fn ($r) => $r->value)->unique()->values()->all());
        $this->assertSame([$a->id => '4.0000', $b->id => '2.5000'], $this->quantities([$request->id]));
        $this->assertDatabaseHas('audit_logs', ['event' => 'transfer_request.created', 'auditable_id' => $request->id]);
        // no inventory effect before receipt
        $this->assertSame('10.0000', $this->balance($this->org, $this->depot, $a)->on_hand);
    }

    public function test_manual_request_endpoint_creates_and_redirects(): void
    {
        $this->scenario(defaultMinimum: null);

        $this->actingAs($this->owner)->post(route('inventory.transfer-requests.store'), [
            'source_warehouse_id' => $this->depot->id,
            'destination_warehouse_id' => $this->showroom->id,
            'note' => 'Vitrine',
            'operation_id' => 'op-123',
            'lines' => [['product_variant_id' => $this->variants[0]->id, 'quantity' => 3]],
        ])->assertRedirect();

        $request = TransferRequest::query()->where('idempotency_key', 'op-123')->firstOrFail();
        $this->assertSame([$this->variants[0]->id => '3.0000'], $this->quantities([$request->id]));
    }

    public function test_manual_request_rejects_quantities_above_transferable_stock_and_same_route(): void
    {
        $this->scenario(defaultMinimum: null);
        $variant = $this->variants[0];

        $this->assertValidationFails(fn () => $this->manual([['product_variant_id' => $variant->id, 'quantity' => '11']]), 'lines.0.quantity');
        $this->assertValidationFails(fn () => $this->manual([['product_variant_id' => $variant->id, 'quantity' => '1']], source: $this->depot, destination: $this->depot), 'destination_warehouse_id');

        // 7 already promised by another active request → only 3 transferable now
        $this->manual([['product_variant_id' => $variant->id, 'quantity' => '7']]);
        $this->assertValidationFails(fn () => $this->manual([['product_variant_id' => $variant->id, 'quantity' => '4']]), 'lines.0.quantity');
        $this->manual([['product_variant_id' => $variant->id, 'quantity' => '3']]);
        $this->assertSame(2, TransferRequest::query()->where('organization_id', $this->org->id)->count());
    }

    public function test_duplicate_manual_submission_with_same_operation_id_creates_one_request(): void
    {
        $this->scenario(defaultMinimum: null);
        $lines = [['product_variant_id' => $this->variants[0]->id, 'quantity' => '2']];

        $first = $this->manual($lines, 'op-dup');
        $second = $this->manual($lines, 'op-dup');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, TransferRequest::query()->where('organization_id', $this->org->id)->count());
    }

    public function test_manual_request_counts_as_incoming_so_sweep_does_not_duplicate_it(): void
    {
        $this->scenario([0], [10], '3.0000');
        $this->manual([['product_variant_id' => $this->variants[0]->id, 'quantity' => '3']]);

        $this->assertSame([], $this->sweep());
    }

    // === 2. Editing before preparation =====================================

    public function test_edit_changes_quantities_removes_and_adds_lines(): void
    {
        $this->scenario(defaultMinimum: null);
        [$a, $b, $c] = $this->variants;
        $request = $this->manual([
            ['product_variant_id' => $a->id, 'quantity' => '4'],
            ['product_variant_id' => $b->id, 'quantity' => '2'],
        ]);
        $lineA = $request->lines()->where('product_variant_id', $a->id)->firstOrFail();

        $updated = app(UpdateTransferRequestLinesAction::class)->execute($this->owner, $request, 0, [
            ['id' => $lineA->id, 'product_variant_id' => $a->id, 'quantity' => '1'],   // reduce
            ['product_variant_id' => $c->id, 'quantity' => '5'],                       // add; b removed
        ]);

        $this->assertSame([$a->id => '1.0000', $c->id => '5.0000'], $this->quantities([$updated->id]));
        $this->assertSame(1, (int) $updated->lock_version);
        $this->assertDatabaseHas('audit_logs', ['event' => 'transfer_request.lines_updated', 'auditable_id' => $request->id]);
    }

    public function test_edit_endpoint_and_form_page(): void
    {
        $this->scenario(defaultMinimum: null);
        $a = $this->variants[0];
        $request = $this->manual([['product_variant_id' => $a->id, 'quantity' => '4']]);
        $line = $request->lines()->firstOrFail();

        $this->actingAs($this->owner)->get(route('inventory.transfer-requests.edit', $request))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inventory/TransferRequests/Form')
                ->where('mode', 'edit')
                ->where('request.lock_version', 0)
                ->where('request.lines.0.stock.transferable', '10.0000') // own promise excluded
                ->has('searchResults'));

        $this->actingAs($this->owner)->put(route('inventory.transfer-requests.lines', $request), [
            'lock_version' => 0,
            'lines' => [['id' => $line->id, 'product_variant_id' => $a->id, 'quantity' => 6]],
        ])->assertRedirect(route('inventory.transfer-requests.show', $request));

        $this->assertSame('6.0000', $line->fresh()->quantity);
    }

    public function test_stale_edit_is_rejected(): void
    {
        $this->scenario(defaultMinimum: null);
        $a = $this->variants[0];
        $request = $this->manual([['product_variant_id' => $a->id, 'quantity' => '4']]);
        $line = $request->lines()->firstOrFail();
        $payload = [['id' => $line->id, 'product_variant_id' => $a->id, 'quantity' => '2']];

        app(UpdateTransferRequestLinesAction::class)->execute($this->owner, $request, 0, $payload);
        // a second editor still holding version 0
        $this->assertValidationFails(fn () => app(UpdateTransferRequestLinesAction::class)->execute($this->owner, $request->fresh(), 0, [
            ['id' => $line->id, 'product_variant_id' => $a->id, 'quantity' => '9'],
        ]), 'lock_version');
        $this->assertSame('2.0000', $line->fresh()->quantity);
    }

    public function test_edit_is_refused_once_preparing_and_edit_cannot_exceed_stock_or_empty_the_request(): void
    {
        $this->scenario(defaultMinimum: null);
        $a = $this->variants[0];
        $request = $this->manual([['product_variant_id' => $a->id, 'quantity' => '4']]);
        $line = $request->lines()->firstOrFail();

        $this->assertValidationFails(fn () => app(UpdateTransferRequestLinesAction::class)->execute($this->owner, $request, 0, [
            ['id' => $line->id, 'product_variant_id' => $a->id, 'quantity' => '11'],
        ]), 'lines.0.quantity');
        $this->assertValidationFails(fn () => app(UpdateTransferRequestLinesAction::class)->execute($this->owner, $request, 0, []), 'lines');

        app(PrepareTransferRequestAction::class)->execute($this->owner, $request->fresh());
        $this->assertValidationFails(fn () => app(UpdateTransferRequestLinesAction::class)->execute($this->owner, $request->fresh(), (int) $request->fresh()->lock_version, [
            ['id' => $line->id, 'product_variant_id' => $a->id, 'quantity' => '1'],
        ]), 'status');
    }

    public function test_order_fulfillment_lines_cannot_be_reduced_or_removed_by_editing(): void
    {
        $this->scenario([5], [30], null);
        $variant = $this->variants[0];
        $order = $this->pickupOrder($variant, '10.0000'); // 5 local + 5 remote
        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();
        $orderLine = $request->lines()->where('reason', 'order_fulfillment')->firstOrFail();
        $this->assertSame('5.0000', $orderLine->quantity);

        $this->assertValidationFails(fn () => app(UpdateTransferRequestLinesAction::class)->execute($this->owner, $request, (int) $request->lock_version, [
            ['id' => $orderLine->id, 'product_variant_id' => $variant->id, 'quantity' => '2'],
        ]), 'lines.0.quantity');

        // omitting it does not remove it: only free lines are managed by the editor
        app(UpdateTransferRequestLinesAction::class)->execute($this->owner, $request, (int) $request->fresh()->lock_version, [
            ['product_variant_id' => $variant->id, 'quantity' => '1'],
        ]);
        $this->assertSame('5.0000', $orderLine->fresh()->quantity);
        $this->assertSame(['manual' => '1.0000', 'order_fulfillment' => '5.0000'], $request->lines()->get()->mapWithKeys(fn ($l) => [$l->reason->value => $l->quantity])->sortKeys()->all());
    }

    // === Preparation review ================================================

    public function test_preparer_confirms_what_is_actually_sent(): void
    {
        $this->scenario(defaultMinimum: null);
        [$a, $b] = $this->variants;
        $request = $this->manual([
            ['product_variant_id' => $a->id, 'quantity' => '4'],
            ['product_variant_id' => $b->id, 'quantity' => '2'],
        ]);
        $lineA = $request->lines()->where('product_variant_id', $a->id)->firstOrFail();
        $lineB = $request->lines()->where('product_variant_id', $b->id)->firstOrFail();
        app(PrepareTransferRequestAction::class)->execute($this->owner, $request->fresh());
        $version = (int) $request->fresh()->lock_version;

        // every line must be confirmed; more than requested is refused
        $this->assertValidationFails(fn () => app(ShipTransferRequestAction::class)->execute($this->owner, $request->fresh(), [], [$lineA->id => '4'], $version), "lines.{$lineB->id}");
        $this->assertValidationFails(fn () => app(ShipTransferRequestAction::class)->execute($this->owner, $request->fresh(), [], [$lineA->id => '5', $lineB->id => '2'], $version), "lines.{$lineA->id}");

        app(ShipTransferRequestAction::class)->execute($this->owner, $request->fresh(), [], [$lineA->id => '3', $lineB->id => '0'], $version);

        $this->assertSame(TransferRequestStatus::Shipped, $request->fresh()->status);
        $this->assertSame('3.0000', $lineA->fresh()->quantity);
        $this->assertSame('4.0000', $lineA->fresh()->requested_quantity);
        $this->assertNull($lineB->fresh()); // 0 → not sent
        $this->actingAs($this->owner)->get(route('inventory.transfer-requests.show', $request))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('request.lines', 1)
                ->where('request.lines.0.quantity', '3.0000')
                ->where('request.lines.0.requested_quantity', '4.0000'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'transfer_request.preparation_adjusted', 'auditable_id' => $request->id]);

        app(ReceiveTransferRequestAction::class)->execute($this->owner, $request->fresh());
        $this->assertSame('7.0000', $this->balance($this->org, $this->depot, $a)->on_hand);
        $this->assertSame('3.0000', $this->balance($this->org, $this->showroom, $a)->on_hand);
        $this->assertSame('10.0000', $this->balance($this->org, $this->depot, $b)->on_hand);
    }

    public function test_review_cannot_short_ship_a_customer_order(): void
    {
        $this->scenario([5], [30], null);
        $order = $this->pickupOrder($this->variants[0], '10.0000');
        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();
        $line = $request->lines()->where('reason', 'order_fulfillment')->firstOrFail();
        app(PrepareTransferRequestAction::class)->execute($this->owner, $request->fresh());

        $this->assertValidationFails(fn () => app(ShipTransferRequestAction::class)->execute($this->owner, $request->fresh(), [], [$line->id => '3'], (int) $request->fresh()->lock_version), "lines.{$line->id}");
        $this->assertSame(TransferRequestStatus::Preparing, $request->fresh()->status);
    }

    // === 3 + 4. Replenishment determinism ==================================

    public function test_repeated_replenishment_without_inventory_change_creates_nothing_new(): void
    {
        $this->scenario();
        $first = $this->sweep();
        $snapshot = $this->quantities($first);

        $this->assertSame([], $this->sweep());
        $this->assertSame([], $this->sweep());
        $this->assertSame($snapshot, $this->quantities($first));
    }

    public function test_cancel_then_replenish_reproduces_exactly_the_same_quantities(): void
    {
        $this->scenario();
        $first = $this->sweep();
        $expected = $this->quantities($first);
        // showroom 0,1,2,0,1 against minimum 3 → 3,2,1,3,2 = 11 units
        $this->assertSame('11', (string) array_sum(array_map('floatval', $expected)));

        foreach ($first as $id) {
            app(CancelTransferRequestAction::class)->execute($this->owner, TransferRequest::query()->findOrFail($id), 'Erreur de saisie');
        }
        $second = $this->sweep();
        $this->assertSame($expected, $this->quantities($second));

        // and once more — cancelled requests never count as incoming
        foreach ($second as $id) {
            app(CancelTransferRequestAction::class)->execute($this->owner, TransferRequest::query()->findOrFail($id), 'Encore');
        }
        $this->assertSame($expected, $this->quantities($this->sweep()));
    }

    public function test_replenishment_recalculates_after_a_manual_edit(): void
    {
        $this->scenario([0], [10], '3.0000');
        $first = $this->sweep();
        $request = TransferRequest::query()->findOrFail($first[0]);
        $line = $request->lines()->firstOrFail();
        $this->assertSame('3.0000', $line->quantity);

        // manager trims the suggestion to 1 → the sweep tops it back up to the minimum, on the same request
        app(UpdateTransferRequestLinesAction::class)->execute($this->owner, $request, (int) $request->lock_version, [
            ['id' => $line->id, 'product_variant_id' => $this->variants[0]->id, 'quantity' => '1'],
        ]);
        $this->assertSame($first, $this->sweep());
        $this->assertSame('3.0000', $line->fresh()->quantity);
        $this->assertSame([], $this->sweep());
    }

    /**
     * The reported 1 → 2: the sweep used to merge its lines into an already
     * open request on the same route (here a manual one). That request's
     * other demand counted as incoming, so the generated line was smaller;
     * cancelling "the generated request" also cancelled that demand and the
     * next run produced a bigger line for the same inventory.
     */
    public function test_sweep_never_merges_into_a_request_carrying_other_demand(): void
    {
        $this->scenario([0], [10], '3.0000');
        $variant = $this->variants[0];
        $manual = $this->manual([['product_variant_id' => $variant->id, 'quantity' => '2']]);

        $first = $this->sweep();
        $this->assertNotContains($manual->id, $first);
        $this->assertSame([$variant->id => '1.0000'], $this->quantities($first));
        $this->assertSame([$variant->id => '2.0000'], $this->quantities([$manual->id])); // untouched

        foreach ($first as $id) {
            app(CancelTransferRequestAction::class)->execute($this->owner, TransferRequest::query()->findOrFail($id), 'Erreur');
        }
        $second = $this->sweep();
        $this->assertSame($this->quantities($first), $this->quantities($second)); // still 1, not 3
        $this->assertSame([], $this->sweep());
    }

    public function test_sweep_still_groups_into_its_own_open_replenishment_request(): void
    {
        $this->scenario([0, 0], [10, 10], '3.0000');
        $first = $this->sweep();
        $request = TransferRequest::query()->findOrFail($first[0]);
        $line = $request->lines()->where('product_variant_id', $this->variants[0]->id)->firstOrFail();
        app(UpdateTransferRequestLinesAction::class)->execute($this->owner, $request, (int) $request->lock_version, $request->lines()->get()
            ->map(fn ($l) => ['id' => $l->id, 'product_variant_id' => $l->product_variant_id, 'quantity' => $l->id === $line->id ? '1' : $l->quantity])->all());

        $this->assertSame($first, $this->sweep()); // same request extended, one line per variant
        $this->assertSame(2, $request->lines()->count());
        $this->assertSame('3.0000', $line->fresh()->quantity);
    }

    // === Promised stock vs direct transfers / adjustments ==================

    public function test_direct_transfer_cannot_consume_stock_promised_to_open_requests(): void
    {
        $this->scenario(defaultMinimum: null);
        $variant = $this->variants[0];
        $this->manual([['product_variant_id' => $variant->id, 'quantity' => '7']]);
        $other = $this->createWarehouse($this->org, 'Autre');

        $exception = $this->assertValidationFails(fn () => app(\App\Actions\Inventory\CreateStockTransferAction::class)
            ->execute($this->owner, $this->org, $this->depot, $other, [['variant' => $variant, 'quantity' => '4']], 'Direct'), 'lines.0.quantity');
        $this->assertStringContainsString('3', $exception->errors()['lines.0.quantity'][0]);
        $this->assertSame('10.0000', $this->balance($this->org, $this->depot, $variant)->on_hand); // rolled back
        $this->assertDatabaseCount('stock_transfers', 0);

        app(\App\Actions\Inventory\CreateStockTransferAction::class)
            ->execute($this->owner, $this->org, $this->depot, $other, [['variant' => $variant, 'quantity' => '3']], 'Direct');
        $this->assertSame('7.0000', $this->balance($this->org, $this->depot, $variant)->on_hand);

        // HTTP path returns the error on the line
        $this->actingAs($this->owner)->post(route('inventory.transfers.store'), [
            'source_warehouse_id' => $this->depot->id, 'destination_warehouse_id' => $other->id, 'reason' => 'Direct',
            'lines' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('lines.0.quantity');
    }

    public function test_receipt_of_a_promised_request_is_never_blocked_by_the_promise_guard(): void
    {
        $this->scenario(defaultMinimum: null);
        $variant = $this->variants[0];
        $a = $this->manual([['product_variant_id' => $variant->id, 'quantity' => '6']]);
        $this->manual([['product_variant_id' => $variant->id, 'quantity' => '4']], destination: $this->createWarehouse($this->org, 'Autre'));
        app(PrepareTransferRequestAction::class)->execute($this->owner, $a->fresh());
        app(ShipTransferRequestAction::class)->execute($this->owner, $a->fresh());
        // physical loss after shipping: remaining 7 no longer covers both promises (6 + 4)
        app(\App\Actions\Inventory\AdjustInventoryAction::class)->execute($this->owner, $this->org, $this->depot, $variant, \App\Enums\InventoryMovementType::AdjustmentOut, '3.0000', 'Casse');

        app(ReceiveTransferRequestAction::class)->execute($this->owner, $a->fresh());
        $this->assertSame(TransferRequestStatus::Received, $a->fresh()->status);
        $this->assertSame('1.0000', $this->balance($this->org, $this->depot, $variant)->on_hand);
    }

    public function test_stock_out_adjustment_is_recorded_and_warns_about_short_requests(): void
    {
        $this->scenario(defaultMinimum: null);
        $variant = $this->variants[0];
        $request = $this->manual([['product_variant_id' => $variant->id, 'quantity' => '8']]);

        $this->actingAs($this->owner)->post(route('inventory.adjustments.store'), [
            'warehouse_id' => $this->depot->id, 'product_variant_id' => $variant->id,
            'type' => 'adjustment_out', 'quantity' => '4', 'reason' => 'Casse',
        ])->assertRedirect()->assertSessionHas('success', fn ($message) => str_contains($message, $request->request_number) && str_contains($message, 'manque 2'));

        $this->assertSame('6.0000', $this->balance($this->org, $this->depot, $variant)->on_hand); // accounting unchanged
        // no new promise can be made on that variant at that source
        $this->assertValidationFails(fn () => $this->manual([['product_variant_id' => $variant->id, 'quantity' => '1']], destination: $this->createWarehouse($this->org, 'Autre')), 'lines.0.quantity');
    }

    public function test_reduced_preparation_leaves_the_remaining_demand_to_the_next_sweep(): void
    {
        $this->scenario([0], [10], '3.0000');
        $request = TransferRequest::query()->findOrFail($this->sweep()[0]);
        $line = $request->lines()->firstOrFail();
        app(PrepareTransferRequestAction::class)->execute($this->owner, $request->fresh());
        app(ShipTransferRequestAction::class)->execute($this->owner, $request->fresh(), [], [$line->id => '1'], (int) $request->fresh()->lock_version);

        $this->assertSame('1.0000', $line->fresh()->quantity);
        $this->assertSame('3.0000', $line->fresh()->requested_quantity);
        $topUp = $this->sweep();
        $this->assertNotContains($request->id, $topUp);
        $this->assertSame([$this->variants[0]->id => '2.0000'], $this->quantities($topUp)); // 3 − 1 in transit
        $this->assertSame([], $this->sweep());
    }

    public function test_source_stock_is_never_promised_twice_to_two_destinations(): void
    {
        $this->scenario([0], [3], '2.0000');
        $showroom2 = $this->createWarehouse($this->org, 'Showroom 2');
        $setting = new WarehouseReplenishmentSetting;
        $setting->organization_id = $this->org->id;
        $setting->warehouse_id = $showroom2->id;
        $setting->auto_replenish = true;
        $setting->default_minimum_quantity = '2.0000';
        $setting->save();

        $a = $this->sweep();
        $b = app(PlanShowroomReplenishmentAction::class)->execute($this->owner, $this->org, $showroom2);

        $this->assertSame([$this->variants[0]->id => '2.0000'], $this->quantities($a));
        $this->assertSame([$this->variants[0]->id => '1.0000'], $this->quantities($b)); // only 3 in the depot
    }

    // === 5. Cancellation & restoration =====================================

    public function test_cancellation_requires_a_reason_and_is_kept_in_history(): void
    {
        $this->scenario(defaultMinimum: null);
        $request = $this->manual([['product_variant_id' => $this->variants[0]->id, 'quantity' => '2']]);

        $this->assertValidationFails(fn () => app(CancelTransferRequestAction::class)->execute($this->owner, $request, '  '), 'reason');
        $this->actingAs($this->owner)->post(route('inventory.transfer-requests.cancel', $request), [])->assertSessionHasErrors('reason');

        app(CancelTransferRequestAction::class)->execute($this->owner, $request, 'Doublon');
        app(CancelTransferRequestAction::class)->execute($this->owner, $request->fresh(), 'Double clic'); // repeated: no-op

        $request->refresh();
        $this->assertSame(TransferRequestStatus::Cancelled, $request->status);
        $this->assertSame('Doublon', $request->cancellation_reason);
        $this->assertSame(1, $request->lines()->count());
        $this->assertSame(1, \App\Models\AuditLog::query()->where('event', 'transfer_request.cancelled')->where('auditable_id', $request->id)->count());
    }

    public function test_restore_succeeds_when_nothing_changed(): void
    {
        $this->scenario([0], [10], '3.0000');
        $request = TransferRequest::query()->findOrFail($this->sweep()[0]);
        app(PrepareTransferRequestAction::class)->execute($this->owner, $request->fresh());
        app(CancelTransferRequestAction::class)->execute($this->owner, $request->fresh(), 'Annulée par erreur');

        $assessment = app(RestoreTransferRequestAction::class)->assess($request->fresh());
        $this->assertTrue($assessment['eligible'], json_encode($assessment));

        // Regression: the detail page loads warehouses with a partial column
        // list; the assessment it shows must still see them as active.
        $this->actingAs($this->owner)->get(route('inventory.transfer-requests.show', $request))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('restore.eligible', true)->where('restore.blockers', []));

        $this->actingAs($this->owner)->post(route('inventory.transfer-requests.restore', $request))->assertRedirect();
        $request->refresh();
        $this->assertSame(TransferRequestStatus::Requested, $request->status);
        $this->assertNotNull($request->restored_at);
        $this->assertNull($request->prepared_at);
        $this->assertSame('Annulée par erreur', $request->cancellation_reason); // history kept
        $this->assertDatabaseHas('audit_logs', ['event' => 'transfer_request.restored', 'auditable_id' => $request->id]);

        // restored demand counts again → no duplicate on the next sweep
        $this->assertSame([], $this->sweep());
    }

    public function test_restore_is_blocked_when_a_fresh_replenishment_already_covers_it(): void
    {
        $this->scenario([0], [10], '3.0000');
        $old = TransferRequest::query()->findOrFail($this->sweep()[0]);
        app(CancelTransferRequestAction::class)->execute($this->owner, $old, 'Erreur');
        $fresh = $this->sweep();
        $this->assertNotSame([$old->id], $fresh);

        $assessment = app(RestoreTransferRequestAction::class)->assess($old->fresh());
        $this->assertFalse($assessment['eligible']);
        $this->assertStringContainsString('déjà couvert', implode(' ', $assessment['blockers']));
        $this->assertValidationFails(fn () => app(RestoreTransferRequestAction::class)->execute($this->owner, $old->fresh()), 'restore');
        $this->assertSame(TransferRequestStatus::Cancelled, $old->fresh()->status);

        // the user is offered a new request pre-filled from the cancelled one
        $this->actingAs($this->owner)->get(route('inventory.transfer-requests.create', ['from' => $old->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inventory/TransferRequests/Form')
                ->where('prefill.request_number', $old->request_number)
                ->has('prefill.lines', 1));
    }

    public function test_restore_is_blocked_when_source_stock_was_promised_elsewhere(): void
    {
        $this->scenario(defaultMinimum: null);
        $variant = $this->variants[0];
        $request = $this->manual([['product_variant_id' => $variant->id, 'quantity' => '8']]);
        app(CancelTransferRequestAction::class)->execute($this->owner, $request, 'Erreur');
        $this->manual([['product_variant_id' => $variant->id, 'quantity' => '5']], destination: $this->createWarehouse($this->org, 'Autre'));

        $assessment = app(RestoreTransferRequestAction::class)->assess($request->fresh());
        $this->assertFalse($assessment['eligible']);
        $this->assertStringContainsString('stock transférable insuffisant', implode(' ', $assessment['blockers']));
    }

    public function test_shipped_or_received_requests_are_never_restored_or_cancelled(): void
    {
        $this->scenario(defaultMinimum: null);
        $request = $this->manual([['product_variant_id' => $this->variants[0]->id, 'quantity' => '2']]);
        app(PrepareTransferRequestAction::class)->execute($this->owner, $request->fresh());
        app(ShipTransferRequestAction::class)->execute($this->owner, $request->fresh());

        $this->assertValidationFails(fn () => app(CancelTransferRequestAction::class)->execute($this->owner, $request->fresh(), 'Trop tard'), 'status');
        $this->assertFalse(app(RestoreTransferRequestAction::class)->assess($request->fresh())['eligible']);
        $this->assertValidationFails(fn () => app(RestoreTransferRequestAction::class)->execute($this->owner, $request->fresh()), 'restore');
    }

    // === 6. Customer orders =================================================

    public function test_order_linked_request_cannot_be_cancelled_from_logistics(): void
    {
        $this->scenario([5], [30], null);
        $order = $this->pickupOrder($this->variants[0], '10.0000');
        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();

        $this->assertValidationFails(fn () => app(CancelTransferRequestAction::class)->execute($this->owner, $request, 'Pas besoin'), 'reason');
        $this->assertSame(TransferRequestStatus::Requested, $request->fresh()->status);
        $this->assertSame('25.0000', $this->balance($this->org, $this->depot, $this->variants[0])->available); // reservation untouched
    }

    public function test_order_and_replenishment_stay_distinct_and_reservations_are_not_counted_twice(): void
    {
        $this->scenario([5], [30], '2.0000');
        $variant = $this->variants[0];
        $order = $this->pickupOrder($variant, '10.0000'); // 5 local (reserved) + 5 depot (reserved)
        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();

        $byReason = $request->lines()->get()->mapWithKeys(fn ($l) => [$l->reason->value => $l->quantity])->all();
        $this->assertSame('5.0000', $byReason['order_fulfillment']);
        $this->assertSame('2.0000', $byReason['minimum_replenishment']);

        // depot: 30 on hand − 5 reserved for the order = 25 available; 2 promised to replenishment
        $stock = app(\App\Services\ShowroomReplenishmentPlanner::class)->transferableMap($this->org->id, $this->depot->id, [$variant->id]);
        $this->assertSame('25.0000', $stock[$variant->id]['available']);
        $this->assertSame('2.0000', $stock[$variant->id]['committed']);
        $this->assertSame('23.0000', $stock[$variant->id]['transferable']);

        // a manual sweep adds nothing: the order's replenishment line already covers the minimum
        $this->assertSame([], $this->sweep());
    }

    public function test_reconfirmed_order_reopens_its_request_without_stale_lines(): void
    {
        $this->scenario([5], [30], '2.0000');
        $variant = $this->variants[0];
        $order = $this->pickupOrder($variant, '10.0000');
        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();

        // simulate a cancelled lifecycle followed by a retried confirmation hook
        $request->status = TransferRequestStatus::Cancelled;
        $request->cancelled_at = now();
        $request->cancellation_reason = 'test';
        $request->save();

        app(CreateOrderTransferRequestsAction::class)->createForConfirmedOrder($this->owner, $order->fresh(['lines.allocations', 'organization', 'store']));

        $request->refresh();
        $this->assertSame(TransferRequestStatus::Requested, $request->status);
        $byReason = $request->lines()->get()->mapWithKeys(fn ($l) => [$l->reason->value => $l->quantity])->all();
        $this->assertSame(['minimum_replenishment' => '2.0000', 'order_fulfillment' => '5.0000'], collect($byReason)->sortKeys()->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'transfer_request.reopened_by_order', 'auditable_id' => $request->id]);
    }

    // === 7. Concurrency / repeated submissions ==============================

    public function test_repeated_restore_and_ship_submissions_are_harmless(): void
    {
        $this->scenario(defaultMinimum: null);
        $request = $this->manual([['product_variant_id' => $this->variants[0]->id, 'quantity' => '2']]);
        $line = $request->lines()->firstOrFail();
        app(CancelTransferRequestAction::class)->execute($this->owner, $request, 'Erreur');

        app(RestoreTransferRequestAction::class)->execute($this->owner, $request->fresh());
        app(RestoreTransferRequestAction::class)->execute($this->owner, $request->fresh());
        $this->assertSame(1, \App\Models\AuditLog::query()->where('event', 'transfer_request.restored')->where('auditable_id', $request->id)->count());

        app(PrepareTransferRequestAction::class)->execute($this->owner, $request->fresh());
        $version = (int) $request->fresh()->lock_version;
        app(ShipTransferRequestAction::class)->execute($this->owner, $request->fresh(), [], [$line->id => '2'], $version);
        app(ShipTransferRequestAction::class)->execute($this->owner, $request->fresh(), [], [$line->id => '2'], $version); // retried POST
        $this->assertSame(1, \App\Models\AuditLog::query()->where('event', 'transfer_request.shipped')->where('auditable_id', $request->id)->count());
    }

    public function test_concurrent_stock_change_is_caught_at_edit_and_review_time(): void
    {
        $this->scenario(defaultMinimum: null);
        $variant = $this->variants[0];
        $request = $this->manual([['product_variant_id' => $variant->id, 'quantity' => '6']]);
        $line = $request->lines()->firstOrFail();

        // meanwhile, 7 of the 10 depot units are promised to another destination
        $this->manual([['product_variant_id' => $variant->id, 'quantity' => '4']], destination: $this->createWarehouse($this->org, 'Autre'));

        // editing up to 7 now exceeds what is left (10 − 4)
        $this->assertValidationFails(fn () => app(UpdateTransferRequestLinesAction::class)->execute($this->owner, $request, 0, [
            ['id' => $line->id, 'product_variant_id' => $variant->id, 'quantity' => '7'],
        ]), 'lines.0.quantity');
        app(UpdateTransferRequestLinesAction::class)->execute($this->owner, $request, 0, [
            ['id' => $line->id, 'product_variant_id' => $variant->id, 'quantity' => '6'],
        ]);

        // and at review time the depot physically lost stock (adjustment) → shipping 6 is refused
        app(\App\Actions\Inventory\AdjustInventoryAction::class)->execute($this->owner, $this->org, $this->depot, $variant, \App\Enums\InventoryMovementType::AdjustmentOut, '3.0000', 'Casse');
        app(PrepareTransferRequestAction::class)->execute($this->owner, $request->fresh());
        $this->assertValidationFails(fn () => app(ShipTransferRequestAction::class)->execute($this->owner, $request->fresh(), [], [$line->id => '6'], (int) $request->fresh()->lock_version));
        app(ShipTransferRequestAction::class)->execute($this->owner, $request->fresh(), [], [$line->id => '3'], (int) $request->fresh()->lock_version);
        $this->assertSame('3.0000', $line->fresh()->quantity);
    }

    // === 8. Picker data (search + selected lines) ===========================

    public function test_create_form_returns_bounded_search_results_with_stock_and_selected_line_stock(): void
    {
        $this->scenario([0, 1, 2, 0, 1], [10, 10, 10, 10, 0], null);
        $this->manual([['product_variant_id' => $this->variants[0]->id, 'quantity' => '4']]);

        $this->actingAs($this->owner)->get(route('inventory.transfer-requests.create', [
            'source_warehouse_id' => $this->depot->id,
            'destination_warehouse_id' => $this->showroom->id,
            'selected' => (string) $this->variants[1]->id,
        ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inventory/TransferRequests/Form')
                ->where('mode', 'create')
                ->has('operationId')
                ->has('searchResults', 4) // variant 4 has no depot stock
                ->where('searchResults.0.stock.committed', '4.0000')
                ->where('searchResults.0.stock.transferable', '6.0000')
                ->where('searchResults.1.stock.destination_on_hand', '1.0000')
                ->where('selectedStock.'.$this->variants[1]->id.'.stock.transferable', '10.0000'));

        $this->actingAs($this->owner)->get(route('inventory.transfer-requests.create', [
            'source_warehouse_id' => $this->depot->id,
            'search' => 'SKU-2',
        ]))->assertInertia(fn (AssertableInertia $page) => $page->has('searchResults', 1)->where('searchResults.0.sku', 'SKU-2'));
    }

    public function test_request_form_endpoints_are_permission_and_tenant_safe(): void
    {
        $this->scenario(defaultMinimum: null);
        $request = $this->manual([['product_variant_id' => $this->variants[0]->id, 'quantity' => '2']]);

        $viewer = User::factory()->create();
        $this->addOrganizationMember($this->org, $viewer, ['inventory.transfer_requests.view'], roleName: 'Viewer');
        $this->addStoreMember($this->store, $viewer);
        $this->activate($viewer, $this->org, $this->store);
        $this->actingAs($viewer)->get(route('inventory.transfer-requests.create'))->assertForbidden();
        $this->actingAs($viewer)->get(route('inventory.transfer-requests.edit', $request))->assertForbidden();
        $this->actingAs($viewer)->post(route('inventory.transfer-requests.restore', $request))->assertForbidden();

        $outsider = User::factory()->create();
        $otherOrg = $this->createOrganization($outsider);
        $otherStore = $this->createStore($otherOrg, $outsider);
        $this->activate($outsider, $otherOrg, $otherStore);
        $this->actingAs($outsider)->get(route('inventory.transfer-requests.edit', $request))->assertNotFound();
        $this->actingAs($outsider)->put(route('inventory.transfer-requests.lines', $request), ['lock_version' => 0, 'lines' => []])->assertNotFound();

        // a foreign warehouse id is rejected, never used
        $foreignWarehouse = $this->createWarehouse($otherOrg, 'Foreign');
        $this->actingAs($this->owner)->post(route('inventory.transfer-requests.store'), [
            'source_warehouse_id' => $foreignWarehouse->id,
            'destination_warehouse_id' => $this->showroom->id,
            'lines' => [['product_variant_id' => $this->variants[0]->id, 'quantity' => 1]],
        ])->assertNotFound();
    }
}
