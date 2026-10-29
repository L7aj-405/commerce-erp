<?php

namespace Tests\Feature\Fulfillment;

use App\Actions\Fulfillment\AttachFulfillmentProofAction;
use App\Actions\Fulfillment\CancelFulfillmentAction;
use App\Actions\Fulfillment\CloseFulfillmentAction;
use App\Actions\Fulfillment\HandFulfillmentToCarrierAction;
use App\Actions\Fulfillment\MarkFulfillmentDeliveredAction;
use App\Actions\Fulfillment\MarkFulfillmentInTransitAction;
use App\Actions\Fulfillment\MarkFulfillmentReadyAction;
use App\Actions\Fulfillment\StartFulfillmentPreparationAction;
use App\Actions\Fulfillment\UpdateFulfillmentShipmentDetailsAction;
use App\Actions\Sales\CancelSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Enums\FulfillmentStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\SalesOrderFulfillmentStatus;
use App\Models\AuditLog;
use App\Models\Fulfillment;
use App\Models\FulfillmentAttachment;
use App\Models\FulfillmentItem;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\SalesOrderLine;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SalesTestCase;

/** F1.1 Fulfillment / Expédition core. */
class FulfillmentTest extends SalesTestCase
{
    private User $owner;

    private Organization $organization;

    private Store $store;

    private Warehouse $warehouse;

    private ProductVariant $variant;

    private SalesOrder $order;

    private SalesOrderLine $line;

    private OrganizationContact $carrier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
        Storage::fake('local');
        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->organization, $this->owner);
        $this->warehouse = $this->createWarehouse($this->organization);
        $this->variant = $this->createProduct($this->organization, 'Micro Shure MV7X', 'SHU-MV7X', ['default_sale_price' => '1000.0000'])->variants->first();
        $this->activate($this->owner, $this->organization, $this->store);
        $this->openStock($this->owner, $this->organization, $this->warehouse, $this->variant, '10.0000');
        $this->carrier = $this->contact($this->organization, 'Amana Express', OrganizationContact::TYPE_TRANSPORT);
        [$this->order, $this->line] = $this->confirmedOrder('5');
    }

    /* ---------------------------------------------------------------- simple mode */

    public function test_simple_mode_hands_a_partial_shipment_to_the_carrier_without_marking_it_delivered(): void
    {
        $handedAt = '2026-10-29 09:30:00';
        $fulfillment = $this->hand(['items' => [$this->line->id => '3'], 'handed_at' => $handedAt, 'tracking_number' => ' TRK-123 ',
            'carrier_reference' => 'AMX-778', 'package_count' => 2, 'note' => 'Fragile']);

        $this->assertSame([FulfillmentStatus::HandedToCarrier, 'simple', 'carrier'], [$fulfillment->status, $fulfillment->workflow_mode, $fulfillment->channel]);
        $this->assertSame([$this->carrier->id, 'TRK-123', 'AMX-778', 2, 'Fragile'], [$fulfillment->carrier_contact_id, $fulfillment->tracking_number, $fulfillment->carrier_reference, $fulfillment->package_count, $fulfillment->note]);
        $this->assertSame($handedAt, $fulfillment->handed_to_carrier_at->toDateTimeString());
        $this->assertNull($fulfillment->delivered_at, 'Handed to the carrier is not delivered.');
        $this->assertSame(SalesOrderFulfillmentStatus::PartiallyFulfilled, $this->order->fresh()->fulfillment_status);

        // Exactly the shipped quantity left stock, the rest stays reserved.
        $this->assertStock(onHand: '7.0000', reserved: '2.0000');
        $movement = InventoryMovement::query()->where('movement_type', InventoryMovementType::ReservationConsumed)->sole();
        $this->assertSame('-3.0000', $movement->quantity);
        $allocation = SalesOrderInventoryAllocation::query()->where('sales_order_line_id', $this->line->id)->sole();
        $this->assertSame(['2.0000', '2.0000', InventoryReservationStatus::Active], [$allocation->quantity, $allocation->inventoryReservation->quantity, $allocation->inventoryReservation->status]);
        $item = FulfillmentItem::query()->sole();
        $consumed = InventoryReservation::query()->where('reference_type', FulfillmentItem::class)->where('reference_id', $item->id)->sole();
        $this->assertSame(['3.0000', InventoryReservationStatus::Consumed], [$consumed->quantity, $consumed->status]);

        $event = AuditLog::query()->where('event', 'fulfillment.handed_to_carrier')->sole();
        $this->assertSame([$this->owner->id, 'web', (string) $fulfillment->id, $this->order->id, 'Amana Express'], [$event->actor_id, $event->actor_channel, $event->new_values['fulfillment_id'], $event->new_values['sales_order_id'], $event->new_values['carrier_name']]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'fulfillment.created', 'auditable_id' => (string) $fulfillment->id]);
    }

    public function test_a_retry_returns_the_same_shipment_and_never_deducts_stock_twice(): void
    {
        $operation = '0b4c1d2e-3f40-4a5b-8c6d-7e8f9a0b1c2d';
        $first = $this->hand(['items' => [$this->line->id => '3']], $operation);
        $retry = $this->hand(['items' => [$this->line->id => '3']], $operation);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, Fulfillment::query()->count());
        $this->assertSame(1, InventoryMovement::query()->where('movement_type', InventoryMovementType::ReservationConsumed)->count());
        $this->assertStock(onHand: '7.0000', reserved: '2.0000');
    }

    public function test_partial_shipments_cannot_overship_and_delivery_then_closing_follow(): void
    {
        $first = $this->hand(['items' => [$this->line->id => '3']]);
        $this->assertValidation('status', fn () => app(CloseFulfillmentAction::class)->execute($this->owner, $this->order, $first), 'Only a delivered shipment can be closed.');

        $second = $this->hand(['items' => [$this->line->id => '2']]);
        $this->assertSame(SalesOrderFulfillmentStatus::Fulfilled, $this->order->fresh()->fulfillment_status);
        $this->assertValidation("items.{$this->line->id}", fn () => $this->hand(['items' => [$this->line->id => '3']]));
        $this->assertValidation("items.{$this->line->id}", fn () => $this->hand(['items' => [$this->line->id => '0.5']]));
        $this->assertStock(onHand: '5.0000', reserved: '0.0000');
        $this->assertSame(0, InventoryReservation::query()->where('status', InventoryReservationStatus::Active)->count());
        $this->assertSame('-5.0000', InventoryMovement::query()->where('movement_type', InventoryMovementType::ReservationConsumed)->get()
            ->reduce(fn ($sum, $movement) => bcadd($sum, (string) $movement->quantity, 4), '0.0000'));

        app(MarkFulfillmentDeliveredAction::class)->execute($this->owner, $this->order, $first, '2026-10-29 09:50:00');
        $this->assertSame(FulfillmentStatus::Delivered, $first->fresh()->status);
        $this->assertSame('partially_delivered', $this->page()['delivery_state']);
        app(MarkFulfillmentDeliveredAction::class)->execute($this->owner, $this->order, $second);
        $this->assertSame('delivered', $this->page()['delivery_state']);
        app(CloseFulfillmentAction::class)->execute($this->owner, $this->order, $first);
        $this->assertSame(FulfillmentStatus::Closed, $first->fresh()->status);
        $this->assertSame(1, InventoryMovement::query()->where('reference_id', '!=', null)->where('movement_type', InventoryMovementType::ReservationConsumed)->where('quantity', '-3.0000')->count(), 'Delivery and closing move no stock.');
    }

    public function test_a_shipment_cannot_be_handed_without_a_transport_contact_of_this_organization_or_a_valid_quantity(): void
    {
        $foreignOwner = User::factory()->create();
        $foreign = $this->createOrganization($foreignOwner, 'Other');
        $foreignCarrier = $this->contact($foreign, 'Foreign carrier', OrganizationContact::TYPE_TRANSPORT);
        $client = $this->contact($this->organization, 'Client contact', OrganizationContact::TYPE_CLIENT);

        foreach ([$foreignCarrier->id, $client->id, 999999] as $contactId) {
            $this->assertValidation('carrier_contact_id', fn () => $this->hand(['items' => [$this->line->id => '1'], 'carrier_contact_id' => $contactId]));
        }
        $this->assertValidation('handed_at', fn () => $this->hand(['items' => [$this->line->id => '1'], 'handed_at' => '2026-10-30 10:00:00']));
        $this->assertValidation('package_count', fn () => $this->hand(['items' => [$this->line->id => '1'], 'package_count' => 0]));
        $this->assertValidation('items', fn () => $this->hand(['items' => []]));
        $this->assertSame(0, Fulfillment::query()->count());
        $this->assertStock(onHand: '10.0000', reserved: '5.0000');
    }

    /* ---------------------------------------------------------------- advanced mode */

    public function test_advanced_mode_runs_preparing_ready_handed_in_transit_delivered_closed(): void
    {
        $this->setMode('advanced');
        $this->assertValidation('mode', fn () => $this->hand(['items' => [$this->line->id => '5']]), 'Advanced mode requires preparation first.');

        $fulfillment = app(StartFulfillmentPreparationAction::class)->execute($this->owner, $this->order, [$this->line->id => '5'], 'Carton A');
        $this->assertSame([FulfillmentStatus::Preparing, 'advanced'], [$fulfillment->status, $fulfillment->workflow_mode]);
        $this->assertStock(onHand: '10.0000', reserved: '5.0000');
        $this->assertValidation("items.{$this->line->id}", fn () => app(StartFulfillmentPreparationAction::class)->execute($this->owner, $this->order, [$this->line->id => '1']), 'Quantities already claimed.');
        $this->assertValidation('status', fn () => $this->hand([], fulfillment: $fulfillment), 'Not ready yet.');
        $this->assertValidation('status', fn () => app(MarkFulfillmentInTransitAction::class)->execute($this->owner, $this->order, $fulfillment));

        app(MarkFulfillmentReadyAction::class)->execute($this->owner, $this->order, $fulfillment, 'preparing');
        $this->hand([], fulfillment: $fulfillment, expected: 'ready');
        $this->assertStock(onHand: '5.0000', reserved: '0.0000');
        $this->assertValidation('status', fn () => app(CancelFulfillmentAction::class)->execute($this->owner, $this->order, $fulfillment), 'No cancellation after dispatch.');
        app(MarkFulfillmentInTransitAction::class)->execute($this->owner, $this->order, $fulfillment, 'handed_to_carrier');
        app(MarkFulfillmentDeliveredAction::class)->execute($this->owner, $this->order, $fulfillment, null, 'in_transit');
        app(CloseFulfillmentAction::class)->execute($this->owner, $this->order, $fulfillment, 'delivered');

        $this->assertSame(FulfillmentStatus::Closed, $fulfillment->fresh()->status);
        $this->assertSame(
            ['fulfillment.created', 'fulfillment.preparation_started', 'fulfillment.ready', 'fulfillment.handed_to_carrier', 'fulfillment.in_transit', 'fulfillment.delivered', 'fulfillment.closed'],
            AuditLog::query()->where('auditable_id', (string) $fulfillment->id)->orderBy('id')->pluck('event')->all(),
        );
        $this->assertSame(1, InventoryMovement::query()->where('movement_type', InventoryMovementType::ReservationConsumed)->count());
    }

    public function test_advanced_mode_allows_the_explicit_handed_to_delivered_shortcut_and_cancelling_before_dispatch(): void
    {
        $this->setMode('advanced');
        $cancelled = app(StartFulfillmentPreparationAction::class)->execute($this->owner, $this->order, [$this->line->id => '5']);
        app(CancelFulfillmentAction::class)->execute($this->owner, $this->order, $cancelled, 'Client a changé d’avis');
        $this->assertSame(FulfillmentStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertStock(onHand: '10.0000', reserved: '5.0000');

        $fulfillment = app(StartFulfillmentPreparationAction::class)->execute($this->owner, $this->order, [$this->line->id => '5']);
        app(MarkFulfillmentReadyAction::class)->execute($this->owner, $this->order, $fulfillment);
        $this->hand([], fulfillment: $fulfillment);
        app(MarkFulfillmentDeliveredAction::class)->execute($this->owner, $this->order, $fulfillment, null, 'handed_to_carrier');
        $this->assertSame(FulfillmentStatus::Delivered, $fulfillment->fresh()->status);
    }

    public function test_a_stale_status_is_rejected(): void
    {
        $fulfillment = $this->hand(['items' => [$this->line->id => '5']]);
        $this->assertValidation('status', fn () => app(MarkFulfillmentDeliveredAction::class)->execute($this->owner, $this->order, $fulfillment, null, 'in_transit'));
        app(MarkFulfillmentDeliveredAction::class)->execute($this->owner, $this->order, $fulfillment, null, 'handed_to_carrier');
        // A second click on "Marquer comme livré" with the now stale status.
        $this->assertValidation('status', fn () => app(MarkFulfillmentDeliveredAction::class)->execute($this->owner, $this->order, $fulfillment, null, 'handed_to_carrier'));
        $this->assertSame(1, AuditLog::query()->where('event', 'fulfillment.delivered')->count());
    }

    /* ---------------------------------------------------------------- engine owns stock-out */

    public function test_the_direct_hand_over_ships_only_what_is_left_and_open_preparations_block_it(): void
    {
        $this->hand(['items' => [$this->line->id => '2']]);
        $order = app(FulfillSalesOrderAction::class)->execute($this->owner, $this->order->fresh());

        $this->assertSame(SalesOrderFulfillmentStatus::Fulfilled, $order->fulfillment_status);
        $direct = Fulfillment::query()->where('channel', 'direct')->sole();
        $this->assertSame([FulfillmentStatus::Delivered, '3.0000'], [$direct->status, $direct->items()->sole()->quantity]);
        $this->assertStock(onHand: '5.0000', reserved: '0.0000');
        $this->assertSame(2, InventoryMovement::query()->where('movement_type', InventoryMovementType::ReservationConsumed)->count());

        [$order2, $line2] = $this->confirmedOrder('2');
        $this->setMode('advanced');
        app(StartFulfillmentPreparationAction::class)->execute($this->owner, $order2, [$line2->id => '1']);
        $this->assertValidation('order', fn () => app(FulfillSalesOrderAction::class)->execute($this->owner, $order2->fresh()));
        $this->assertValidation('order', fn () => app(CancelSalesOrderAction::class)->execute($this->owner, $order2->fresh(), 'Annulation'));
    }

    public function test_the_explicit_pickup_action_is_a_direct_hand_over_without_a_transport_contact_while_a_delivery_note_moves_nothing(): void
    {
        // A Bon de livraison is only a document: no shipment, stock untouched.
        $this->activate($this->owner, $this->organization, $this->store);
        $note = app(\App\Actions\Documents\CreateFullDeliveryNoteFromSalesOrderAction::class)->execute($this->owner, $this->order->fresh(), ['delivery_date' => '2026-10-29']);
        app(\App\Actions\Documents\IssueDeliveryNoteAction::class)->execute($this->owner, $note);
        $this->assertSame(0, Fulfillment::query()->count());
        $this->assertSame(SalesOrderFulfillmentStatus::Unfulfilled, $this->order->fresh()->fulfillment_status);
        $this->assertStock(onHand: '10.0000', reserved: '5.0000');

        // The customer collects the goods: the explicit fulfill action hands them over directly.
        $this->actingAs($this->owner)->post("/sales/orders/{$this->order->id}/fulfill")->assertSessionHasNoErrors();
        $handOver = Fulfillment::query()->sole();
        $this->assertSame(['direct', FulfillmentStatus::Delivered, null], [$handOver->channel, $handOver->status, $handOver->carrier_contact_id]);
        $this->assertStock(onHand: '5.0000', reserved: '0.0000');
        $this->assertSame(SalesOrderFulfillmentStatus::Fulfilled, $this->order->fresh()->fulfillment_status);
    }

    /* ---------------------------------------------------------------- security */

    public function test_permissions_and_store_scope_are_enforced(): void
    {
        $viewer = User::factory()->create();
        $this->addOrganizationMember($this->organization, $viewer, ['sales_orders.view', 'fulfillment.view']);
        $this->addStoreMember($this->store, $viewer);
        $this->activate($viewer, $this->organization, $this->store);
        $this->assertForbidden(fn () => $this->hand(['items' => [$this->line->id => '1']], actor: $viewer));

        $dispatcher = User::factory()->create();
        $this->addOrganizationMember($this->organization, $dispatcher, ['sales_orders.view', 'fulfillment.view', 'fulfillment.dispatch']);
        $this->addStoreMember($this->store, $dispatcher);
        $this->activate($dispatcher, $this->organization, $this->store);
        $fulfillment = $this->hand(['items' => [$this->line->id => '1']], actor: $dispatcher);
        $this->assertForbidden(fn () => app(MarkFulfillmentDeliveredAction::class)->execute($dispatcher, $this->order, $fulfillment));

        // A member of another store of the same organization.
        $otherStore = $this->createStore($this->organization, $this->owner, 'Store B');
        $outsider = User::factory()->create();
        $this->addOrganizationMember($this->organization, $outsider, ['sales_orders.view', 'fulfillment.view', 'fulfillment.dispatch']);
        $this->addStoreMember($otherStore, $outsider);
        $this->activate($outsider, $this->organization, $otherStore);
        $this->assertForbidden(fn () => $this->hand(['items' => [$this->line->id => '1']], actor: $outsider));

        // Another organization's owner, through HTTP.
        $foreignOwner = User::factory()->create();
        $foreign = $this->createOrganization($foreignOwner, 'Other');
        $this->activate($foreignOwner, $foreign, $this->createStore($foreign, $foreignOwner));
        $this->actingAs($foreignOwner)->post("/sales/orders/{$this->order->id}/fulfillments/hand-to-carrier", [
            'items' => [$this->line->id => '1'], 'carrier_contact_id' => $this->carrier->id, 'handed_at' => '2026-10-29 09:00:00',
        ])->assertStatus(404);
        $this->assertSame(1, Fulfillment::query()->count());
    }

    public function test_proofs_are_private_validated_and_only_served_to_authorized_users(): void
    {
        $this->activate($this->owner, $this->organization, $this->store);
        $this->actingAs($this->owner)->post("/sales/orders/{$this->order->id}/fulfillments/hand-to-carrier", [
            'items' => [$this->line->id => '2'], 'carrier_contact_id' => $this->carrier->id, 'handed_at' => '2026-10-29 09:00:00',
            'tracking_number' => 'TRK-9', 'proofs' => [UploadedFile::fake()->image('récépissé transporteur.jpg'), UploadedFile::fake()->create('bordereau.pdf', 120, 'application/pdf')],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $fulfillment = Fulfillment::query()->sole();
        $attachments = FulfillmentAttachment::query()->orderBy('created_at')->get();
        $this->assertCount(2, $attachments);
        foreach ($attachments as $attachment) {
            Storage::disk('local')->assertExists($attachment->path);
            $this->assertStringStartsWith("fulfillments/{$this->organization->id}/{$fulfillment->id}/", $attachment->path);
            $this->assertStringNotContainsString('bordereau', $attachment->path, 'Storage keys never reuse the client filename.');
        }
        $this->assertSame(2, AuditLog::query()->where('event', 'fulfillment.proof_attached')->count());

        // Executables / scripts / oversized files are refused.
        foreach ([UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload'), UploadedFile::fake()->create('note.html', 1, 'text/html'), UploadedFile::fake()->create('huge.pdf', 20000, 'application/pdf')] as $file) {
            $this->assertValidation('files.0', fn () => app(AttachFulfillmentProofAction::class)->execute($this->owner, $this->order, $fulfillment, [$file]));
        }

        $pdf = $attachments->firstWhere('mime_type', 'application/pdf');
        $url = "/sales/orders/{$this->order->id}/fulfillments/{$fulfillment->id}/attachments/{$pdf->id}";
        $this->actingAs($this->owner)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $foreignOwner = User::factory()->create();
        $foreign = $this->createOrganization($foreignOwner, 'Other');
        $this->activate($foreignOwner, $foreign, $this->createStore($foreign, $foreignOwner));
        $this->actingAs($foreignOwner)->get($url)->assertStatus(404);

        $this->activate($this->owner, $this->organization, $this->store);
        $this->actingAs($this->owner)->delete($url)->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($pdf->path);
        $this->assertDatabaseHas('audit_logs', ['event' => 'fulfillment.proof_removed']);
    }

    /* ---------------------------------------------------------------- page & settings */

    public function test_the_sales_order_page_exposes_shipments_and_tracking_changes_are_audited(): void
    {
        $fulfillment = $this->hand(['items' => [$this->line->id => '3']]);
        app(UpdateFulfillmentShipmentDetailsAction::class)->execute($this->owner, $this->order, $fulfillment, ['tracking_number' => 'TRK-NEW', 'package_count' => 3]);
        $event = AuditLog::query()->where('event', 'fulfillment.tracking_updated')->sole();
        $this->assertSame(['tracking_number' => 'TRK-NEW', 'package_count' => 3], array_intersect_key($event->new_values, ['tracking_number' => 1, 'package_count' => 1]));

        $this->actingAs($this->owner)->get("/sales/orders/{$this->order->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('fulfillment.mode', 'simple')
            ->where('fulfillment.state', 'partially_fulfilled')
            ->where('fulfillment.lines.0.shipped', '3.0000')
            ->where('fulfillment.lines.0.shippable', '2.0000')
            ->where('fulfillment.fulfillments.0.status', 'handed_to_carrier')
            ->where('fulfillment.fulfillments.0.carrier.name', 'Amana Express')
            ->where('fulfillment.fulfillments.0.tracking_number', 'TRK-NEW')
            ->where('fulfillment.fulfillments.0.actions', ['mark_delivered', 'edit_details'])
            ->where('fulfillment.actions', ['hand_to_carrier']));

        $this->actingAs($this->owner)->put('/organization-fulfillment-mode', ['fulfillment_mode' => 'advanced'])->assertSessionHasNoErrors();
        $this->assertSame('advanced', $this->organization->fresh()->settings['fulfillment_mode']);
        $this->actingAs($this->owner)->put('/organization-fulfillment-mode', ['fulfillment_mode' => 'bogus'])->assertSessionHasErrors('fulfillment_mode');
    }

    /* ---------------------------------------------------------------- helpers */

    /** @return array{SalesOrder, SalesOrderLine} */
    private function confirmedOrder(string $quantity): array
    {
        $this->activate($this->owner, $this->organization, $this->store);
        $order = $this->createDraftOrder($this->owner, $this->organization, $this->store, $this->createCustomer($this->organization));
        $line = $this->addCatalogLine($this->owner, $order, $this->variant, $this->warehouse, ['quantity' => $quantity]);
        $order = app(ConfirmSalesOrderAction::class)->execute($this->owner, $order->fresh())->fresh();

        return [$order, $line->fresh()];
    }

    /** @param array<string, mixed> $data */
    private function hand(array $data, ?string $operation = null, ?Fulfillment $fulfillment = null, ?string $expected = null, ?User $actor = null): Fulfillment
    {
        return app(HandFulfillmentToCarrierAction::class)->execute($actor ?? $this->owner, $this->order->fresh(), [
            'carrier_contact_id' => $this->carrier->id, 'handed_at' => '2026-10-29 09:00:00', ...$data,
        ], $fulfillment, $expected, $operation);
    }

    /** @return array<string, mixed> */
    private function page(): array
    {
        return app(\App\Services\Fulfillment\FulfillmentQuery::class)->forOrder($this->order->fresh(), $this->owner);
    }

    private function setMode(string $mode): void
    {
        $settings = $this->organization->fresh()->settings ?? [];
        $settings['fulfillment_mode'] = $mode;
        $this->organization->forceFill(['settings' => $settings])->save();
        $this->organization->refresh();
        $this->order->setRelation('organization', $this->organization);
    }

    private function contact(Organization $organization, string $name, string $type): OrganizationContact
    {
        $contact = new OrganizationContact;
        $contact->forceFill(['organization_id' => $organization->id, 'full_name' => $name, 'company_name' => $name, 'contact_type' => $type, 'active' => true])->save();

        return $contact;
    }

    private function assertStock(string $onHand, string $reserved): void
    {
        $balance = InventoryBalance::query()->where('warehouse_id', $this->warehouse->id)->where('product_variant_id', $this->variant->id)->sole();
        $this->assertSame([$onHand, $reserved], [(string) $balance->on_hand, (string) $balance->reserved]);
    }

    private function assertValidation(string $field, callable $callback, string $why = ''): void
    {
        try {
            $callback();
            $this->fail("Expected a validation error on {$field}. {$why}");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors(), json_encode($exception->errors()).' '.$why);
        }
    }

    private function assertForbidden(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a 403.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
