<?php

namespace Tests\Feature\Sales;

use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Actions\Sales\StartSalesOrderCorrectionAction;
use App\Enums\InventoryReservationStatus;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryReservationManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DocumentTestCase;

/**
 * UAT incident (19 Nov 2026, SO-000007): an unchanged "Corriger la commande" left the order confirmed and paid
 * but with 0 reserved. Root cause: StartSalesOrderCorrectionAction releases the reservations and keeps the
 * allocation rows of a POS-origin order; re-confirmation then asked InventoryReservationManager::reserve()
 * for the same (warehouse, variant, allocation) reference, which returned the RELEASED reservation as an
 * idempotent hit — nothing was reserved again, no audit row was written.
 *
 * Every scenario runs for both order sources: 'manual' (allocation rows are rebuilt at confirmation) and
 * 'pos' (allocation rows survive the correction — the incident path).
 */
class SalesOrderCorrectionReservationTest extends DocumentTestCase
{
    public static function sources(): array
    {
        return ['manual order' => ['manual'], 'POS-origin order' => ['pos']];
    }

    /** @return array{User, Organization, Store, Warehouse, ProductVariant} */
    private function world(string $stock = '45.0000'): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $warehouse = $this->createWarehouse($organization);
        $variant = $this->createProduct($organization, 'Accessoire', 'QA-ACC-010', ['default_sale_price' => '100.0000'])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $warehouse, $variant, $stock);

        return [$owner, $organization, $store, $warehouse, $variant];
    }

    private function confirmedOrder(User $owner, Organization $organization, Store $store, Warehouse $warehouse, ProductVariant $variant, string $quantity, string $source): array
    {
        $order = $this->createDraftOrder($owner, $organization, $store);
        if ($source === 'pos') {
            DB::table('sales_orders')->where('id', $order->id)->update(['source' => 'pos']);
            $order = $order->fresh();
        }
        $line = $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => $quantity]);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh();

        return [$order, $line->fresh()];
    }

    private function correct(User $owner, SalesOrder $order, ?SalesOrderLine $line = null, ?string $quantity = null): void
    {
        app(StartSalesOrderCorrectionAction::class)->execute($owner, $order->fresh(), 'Correction de test');
        if ($quantity !== null) {
            app(SaveSalesOrderLineAction::class)->execute($owner, $order->fresh(), [
                'line_type' => 'catalog', 'product_variant_id' => $line->product_variant_id,
                'warehouse_id' => $line->allocations()->first()?->warehouse_id ?? $line->fresh()->allocations()->first()?->warehouse_id,
                'quantity' => $quantity, 'discount_type' => 'none', 'discount_value' => '0.0000',
            ], $line->fresh());
        }
    }

    private function reconcile(Organization $organization): array
    {
        $violations = [];
        $balances = InventoryBalance::query()->where('organization_id', $organization->id)->get();
        foreach ($balances as $balance) {
            $active = (string) InventoryReservation::query()->where('organization_id', $organization->id)
                ->where('warehouse_id', $balance->warehouse_id)->where('product_variant_id', $balance->product_variant_id)
                ->where('status', InventoryReservationStatus::Active->value)->sum('quantity');
            if (bccomp((string) $balance->reserved, $active, 4) !== 0) {
                $violations[] = "reserved {$balance->reserved} != active reservations {$active}";
            }
            if (bccomp((string) $balance->reserved, (string) $balance->on_hand, 4) > 0 || bccomp((string) $balance->reserved, '0', 4) < 0) {
                $violations[] = "reserved {$balance->reserved} outside [0, on_hand {$balance->on_hand}]";
            }
        }

        return $violations;
    }

    private function reservedOf(Organization $organization, Warehouse $warehouse, ProductVariant $variant): string
    {
        return (string) $this->balance($organization, $warehouse, $variant)->reserved;
    }

    private function activeReservations(SalesOrder $order): Collection
    {
        return InventoryReservation::query()->where('organization_id', $order->organization_id)
            ->where('status', InventoryReservationStatus::Active->value)
            ->whereIn('reference_id', $order->lines()->with('allocations')->get()->flatMap->allocations->pluck('id'))->get();
    }

    /** 1 — no-op correction keeps one active reservation. */
    #[DataProvider('sources')]
    public function test_noop_correction_restores_the_reservation(string $source): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->world();
        [$order, $line] = $this->confirmedOrder($owner, $organization, $store, $warehouse, $variant, '1.0000', $source);
        $before = InventoryMovement::query()->where('organization_id', $organization->id)->count();

        $this->correct($owner, $order);
        $this->assertSame('0.0000', $this->reservedOf($organization, $warehouse, $variant), 'reservation is released while the correction is open');
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());

        $order = $order->fresh();
        $this->assertSame('confirmed', $order->status->value);
        $this->assertSame('unfulfilled', $order->fulfillment_status->value);
        $this->assertSame('1.0000', $this->reservedOf($organization, $warehouse, $variant));
        $this->assertCount(1, $this->activeReservations($order));
        $this->assertSame('45.0000', (string) $this->balance($organization, $warehouse, $variant)->on_hand);
        $this->assertSame($before, InventoryMovement::query()->where('organization_id', $organization->id)->count());
        $this->assertSame([], $this->reconcile($organization));
        // Audit history: one release at start, one new reservation at completion — no silent reuse.
        $this->assertSame(2, DB::table('audit_logs')->where('organization_id', $organization->id)->where('event', 'inventory.reservation.created')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('organization_id', $organization->id)->where('event', 'inventory.reservation.released')->count());
    }

    /** 2 — the incident: paid order, issued invoice, unchanged correction (through the real HTTP routes). */
    #[DataProvider('sources')]
    public function test_noop_correction_with_paid_order_and_issued_invoice_keeps_reservation_and_invoice(string $source): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->world();
        [$order] = $this->confirmedOrder($owner, $organization, $store, $warehouse, $variant, '1.0000', $source);
        $account = $this->createFinancialAccount($organization, ['name' => 'Caisse', 'code' => 'C1']);
        $payment = $this->recordPayment($owner, $order, $account, (string) $order->total_incl_tax);
        $invoice = app(IssueInvoiceAction::class)->execute($owner, $this->createInvoice($owner, $order->fresh()));
        $invoiceBefore = $invoice->fresh()->only(['invoice_number', 'status', 'subtotal_excl_tax', 'tax_total', 'total_incl_tax', 'version']);

        $this->actingAs($owner)->post(route('sales.orders.corrections.store', $order), ['reason' => 'Aucun changement'])
            ->assertRedirect(route('sales.orders.edit', $order));
        $this->actingAs($owner)->post(route('sales.orders.confirm', $order))->assertSessionHasNoErrors();

        $order = $order->fresh();
        $this->assertSame('confirmed', $order->status->value);
        $this->assertSame('completed', $order->currentRevision->status);
        $this->assertSame('1.0000', $this->reservedOf($organization, $warehouse, $variant));
        $this->assertCount(1, $this->activeReservations($order));
        $this->assertSame($invoiceBefore, $invoice->fresh()->only(array_keys($invoiceBefore)), 'issued invoice is never touched');
        $this->assertSame('posted', $payment->fresh()->status->value ?? $payment->fresh()->status);
        $this->assertSame([], $this->reconcile($organization));
    }

    /** 3 — quantity increase reserves the additional stock. */
    #[DataProvider('sources')]
    public function test_quantity_increase_reserves_the_new_total_once(string $source): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->world();
        [$order, $line] = $this->confirmedOrder($owner, $organization, $store, $warehouse, $variant, '1.0000', $source);

        $this->correct($owner, $order, $line, '3.0000');
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());

        $this->assertSame('3.0000', $this->reservedOf($organization, $warehouse, $variant));
        $this->assertSame(0, bccomp('3.0000', (string) $this->activeReservations($order->fresh())->sum('quantity'), 4));
        $this->assertSame([], $this->reconcile($organization));
    }

    /** 4 — quantity decrease keeps only the remaining quantity reserved. */
    #[DataProvider('sources')]
    public function test_quantity_decrease_releases_only_the_excess(string $source): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->world();
        [$order, $line] = $this->confirmedOrder($owner, $organization, $store, $warehouse, $variant, '3.0000', $source);

        $this->correct($owner, $order, $line, '1.0000');
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());

        $this->assertSame('1.0000', $this->reservedOf($organization, $warehouse, $variant));
        $this->assertSame('45.0000', (string) $this->balance($organization, $warehouse, $variant)->on_hand);
        $this->assertSame([], $this->reconcile($organization));
    }

    /** 5 + 7 — competing order takes the stock while the correction is open: safe rejection, no partial state, retry works. */
    #[DataProvider('sources')]
    public function test_insufficient_stock_rejects_safely_and_leaves_no_partial_state(string $source): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->world('2.0000');
        [$order, $line] = $this->confirmedOrder($owner, $organization, $store, $warehouse, $variant, '2.0000', $source);

        $this->correct($owner, $order);
        // A competitor reserves the stock freed by the open correction.
        [$rival] = $this->confirmedOrder($owner, $organization, $store, $warehouse, $variant, '2.0000', 'manual');
        $this->assertSame('2.0000', $this->reservedOf($organization, $warehouse, $variant));

        try {
            app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
            $this->fail('Confirmation must be refused: the freed stock was taken.');
        } catch (ValidationException) {
            // expected
        }

        $order = $order->fresh();
        $this->assertSame('draft', $order->status->value, 'never left confirmed-but-unreserved');
        $this->assertSame('in_progress', $order->currentRevision->status);
        $this->assertCount(0, $this->activeReservations($order));
        $this->assertSame('2.0000', $this->reservedOf($organization, $warehouse, $variant), 'only the rival holds stock');
        $this->assertSame([], $this->reconcile($organization));

        // The rival's stock is freed: the same correction can now be completed — repeatable.
        foreach ($this->activeReservations($rival->fresh()) as $held) {
            app(InventoryReservationManager::class)->release($owner, $organization, $held);
        }
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
        $this->assertSame('2.0000', $this->reservedOf($organization, $warehouse, $variant));
        $this->assertSame([], $this->reconcile($organization));
    }

    /** 7 — a failure raised after the reservations were taken rolls the whole confirmation back. */
    public function test_failure_after_partial_reservation_rolls_everything_back(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->world('5.0000');
        $other = $this->createProduct($organization, 'Autre', 'QA-OTHER', ['default_sale_price' => '10.0000'])->variants->first();
        $this->openStock($owner, $organization, $warehouse, $other, '1.0000');
        $order = $this->createDraftOrder($owner, $organization, $store);
        DB::table('sales_orders')->where('id', $order->id)->update(['source' => 'pos']);
        $order = $order->fresh();
        $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '1.0000']);
        $lineB = $this->addCatalogLine($owner, $order, $other, $warehouse, ['quantity' => '1.0000']);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());

        $this->correct($owner, $order);
        // Second line's stock disappears while the correction is open.
        DB::table('inventory_balances')->where('organization_id', $organization->id)->where('product_variant_id', $other->id)->update(['on_hand' => '0.0000']);

        try {
            app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
            $this->fail('Confirmation must fail on the second line.');
        } catch (ValidationException) {
        }

        $this->assertSame('draft', $order->fresh()->status->value);
        $this->assertSame('0.0000', $this->reservedOf($organization, $warehouse, $variant), 'the first line must not keep a reservation from the failed attempt');
        $this->assertSame(0, InventoryReservation::query()->where('organization_id', $organization->id)->where('status', 'active')->count());
        $this->assertSame('in_progress', $order->fresh()->currentRevision->status);
    }

    /** 8 — organization / warehouse isolation. */
    public function test_correction_never_touches_other_organizations_or_other_warehouses(): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->world();
        $second = $this->createWarehouse($organization, 'Second', 'WH-SECOND');
        $this->openStock($owner, $organization, $second, $variant, '9.0000');
        [$otherOwner, $otherOrg, $otherStore, $otherWarehouse, $otherVariant] = $this->world('7.0000');
        [$otherOrder] = $this->confirmedOrder($otherOwner, $otherOrg, $otherStore, $otherWarehouse, $otherVariant, '2.0000', 'pos');
        $this->activate($owner, $organization, $store);

        [$order] = $this->confirmedOrder($owner, $organization, $store, $warehouse, $variant, '1.0000', 'pos');
        $this->correct($owner, $order);
        app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());

        $this->assertSame('1.0000', $this->reservedOf($organization, $warehouse, $variant), 'reservation returns to its own warehouse');
        $this->assertSame('0.0000', $this->reservedOf($organization, $second, $variant));
        $this->assertSame('2.0000', $this->reservedOf($otherOrg, $otherWarehouse, $otherVariant), 'other organization untouched');
        $this->assertSame('confirmed', $otherOrder->fresh()->status->value);
        $this->assertSame([], $this->reconcile($organization));
        $this->assertSame([], $this->reconcile($otherOrg));
    }

    /** 9 — repeated corrections never accumulate or lose reservations. */
    #[DataProvider('sources')]
    public function test_repeated_corrections_are_stable(string $source): void
    {
        [$owner, $organization, $store, $warehouse, $variant] = $this->world();
        [$order] = $this->confirmedOrder($owner, $organization, $store, $warehouse, $variant, '2.0000', $source);

        foreach (range(1, 3) as $cycle) {
            $this->correct($owner, $order);
            app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh());
            $this->assertSame('2.0000', $this->reservedOf($organization, $warehouse, $variant), "cycle {$cycle}");
            $this->assertCount(1, $this->activeReservations($order->fresh()), "cycle {$cycle}");
        }
        $this->assertSame(3, InventoryReservation::query()->where('organization_id', $organization->id)->where('status', InventoryReservationStatus::Released->value)->count());
        $this->assertSame([], $this->reconcile($organization));
    }

    /** Defense in depth: a dead reservation is never handed back as if stock were held. */
    public function test_reserve_refuses_to_return_a_released_reservation_for_a_reused_reference(): void
    {
        [$owner, $organization, , $warehouse, $variant] = $this->world();
        $manager = app(InventoryReservationManager::class);
        $reservation = $manager->reserve($owner, $organization, $warehouse, $variant, '1.0000', 'Test\\Ref', 77, 'ref');
        $manager->release($owner, $organization, $reservation);

        try {
            $manager->reserve($owner, $organization, $warehouse, $variant, '1.0000', 'Test\\Ref', 77, 'ref');
            $this->fail('A released reservation must not be returned as an idempotent hit.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reference_id', $e->errors());
        }
        $this->assertSame('0.0000', $this->reservedOf($organization, $warehouse, $variant));
        // A live reservation is still an idempotent hit.
        $live = $manager->reserve($owner, $organization, $warehouse, $variant, '1.0000', 'Test\\Ref', 78, 'ref');
        $this->assertSame($live->id, $manager->reserve($owner, $organization, $warehouse, $variant, '1.0000', 'Test\\Ref', 78, 'ref')->id);
    }
}
