<?php

namespace Tests\Feature\Fulfillment;

use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Fulfillment\CancelFulfillmentAction;
use App\Actions\Fulfillment\HandFulfillmentToCarrierAction;
use App\Actions\Fulfillment\MarkFulfillmentDeliveredAction;
use App\Actions\Fulfillment\MarkFulfillmentInTransitAction;
use App\Actions\Fulfillment\MarkFulfillmentReadyAction;
use App\Actions\Fulfillment\AttachFulfillmentProofAction;
use App\Actions\Fulfillment\ShareFulfillmentWithCustomerAction;
use App\Actions\Fulfillment\StartFulfillmentPreparationAction;
use App\Actions\Fulfillment\UpdateFulfillmentShipmentDetailsAction;
use App\Actions\Sales\CancelSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Actions\Sales\StartSalesOrderCorrectionAction;
use App\Enums\FulfillmentStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\SalesOrderFulfillmentStatus;
use App\Mail\FulfillmentStatusMail;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\Fulfillment;
use App\Models\FulfillmentAttachment;
use App\Models\FulfillmentCustomerNotification;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Fulfillment\FulfillmentCustomerNotifier;
use App\Services\Timeline\BusinessTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DocumentTestCase;

/**
 * F1.2 shipment tracking & delivery lifecycle. Reference scenario (SO-000027
 * shape, not that order): a confirmed, fully paid and invoiced order of two
 * stock lines with an issued Bon de livraison is handed to the carrier — the
 * one and only stock OUT — then tracked, put in transit and delivered, which
 * are logistics only.
 */
class ShipmentLifecycleTest extends DocumentTestCase
{
    /** Client operation ids of distinct "Partager au client" submits. */
    private const OP = ['0b6f3c1e-5a4d-4e2b-9c7a-1d2e3f4a5b60', '0b6f3c1e-5a4d-4e2b-9c7a-1d2e3f4a5b61', '0b6f3c1e-5a4d-4e2b-9c7a-1d2e3f4a5b62', '0b6f3c1e-5a4d-4e2b-9c7a-1d2e3f4a5b63'];

    private User $owner;

    private Organization $organization;

    private Store $store;

    private Warehouse $warehouse;

    private ProductVariant $speaker;

    private ProductVariant $stands;

    private Customer $customer;

    private OrganizationContact $carrier;

    private SalesOrder $order;

    /** @var array<string, SalesOrderLine> */
    private array $lines;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
        Storage::fake('local');
        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->organization, $this->owner);
        $this->warehouse = $this->createWarehouse($this->organization);
        $this->activate($this->owner, $this->organization, $this->store);
        $this->speaker = $this->createProduct($this->organization, 'HK Audio PR:O 12', 'SHK-PRO-12', ['default_sale_price' => '5169.1667'])->variants->first();
        $this->stands = $this->createProduct($this->organization, 'Pack RTX Supports', 'TRTSS01-PACK', ['default_sale_price' => '750.0000'])->variants->first();
        $this->openStock($this->owner, $this->organization, $this->warehouse, $this->speaker, '6.0000');
        $this->openStock($this->owner, $this->organization, $this->warehouse, $this->stands, '9.0000');
        $this->carrier = $this->contact('Amana Express');
        $this->customer = $this->createCustomer($this->organization, 'Client Test F1', ['email' => 'client.f1@example.test']);

        $order = $this->createDraftOrder($this->owner, $this->organization, $this->store, $this->customer);
        $this->lines = [
            'speaker' => $this->addCatalogLine($this->owner, $order, $this->speaker, $this->warehouse, ['quantity' => '2']),
            'stands' => $this->addCatalogLine($this->owner, $order, $this->stands, $this->warehouse, ['quantity' => '1']),
        ];
        $this->order = app(ConfirmSalesOrderAction::class)->execute($this->owner, $order->fresh())->fresh();
    }

    public function test_hand_over_is_the_only_stock_out_and_tracking_and_delivery_move_nothing(): void
    {
        [$invoice, $note] = $this->paidInvoicedWithDeliveryNote();
        $this->assertStock($this->speaker, onHand: '6.0000', reserved: '2.0000');
        $this->assertSame(0, $this->stockOuts());

        $shipment = $this->handAll(['tracking_number' => 'AMX-1', 'package_count' => 2]);

        // Exactly one stock OUT per line, at hand-over: 6 → 4 and 9 → 8, nothing left reserved.
        $this->assertSame(2, $this->stockOuts());
        $this->assertStock($this->speaker, onHand: '4.0000', reserved: '0.0000');
        $this->assertStock($this->stands, onHand: '8.0000', reserved: '0.0000');
        $this->assertSame(SalesOrderFulfillmentStatus::Fulfilled, $this->order->fresh()->fulfillment_status);
        $this->assertNotNull($shipment->stock_consumed_at);
        $handedAt = $shipment->handed_to_carrier_at->toDateTimeString();
        $frozen = $this->frozenState($invoice, $note);

        // "Modifier le suivi": carrier, tracking number/link, packages — zero stock movements, same status.
        $other = $this->contact('CTM Messagerie');
        $updated = app(UpdateFulfillmentShipmentDetailsAction::class)->execute($this->owner, $this->order, $shipment, [
            'carrier_contact_id' => $other->id, 'tracking_number' => 'CTM-778', 'tracking_url' => 'https://track.example.test/CTM-778', 'package_count' => 3,
        ], 'handed_to_carrier');
        $this->assertSame([FulfillmentStatus::HandedToCarrier, $other->id, 'CTM-778', 'https://track.example.test/CTM-778', 3],
            [$updated->status, $updated->carrier_contact_id, $updated->tracking_number, $updated->tracking_url, $updated->package_count]);
        $event = AuditLog::query()->where('event', 'fulfillment.tracking_updated')->sole();
        $this->assertSame(['carrier_contact_id' => $this->carrier->id, 'tracking_number' => 'AMX-1', 'tracking_url' => null, 'package_count' => 2], $event->old_values);
        $this->assertSame('CTM Messagerie', $event->new_values['carrier_name']);
        $this->assertSame($frozen, $this->frozenState($invoice, $note), 'Tracking is logistics only.');

        // "Marquer comme livré": delivered_at recorded, handed_to_carrier_at kept, nothing else moves.
        $delivered = app(MarkFulfillmentDeliveredAction::class)->execute($this->owner, $this->order, $shipment, '2026-10-29 09:50:00', 'handed_to_carrier');
        $this->assertSame([FulfillmentStatus::Delivered, '2026-10-29 09:50:00', $handedAt], [$delivered->status, $delivered->delivered_at->toDateTimeString(), $delivered->fresh()->handed_to_carrier_at->toDateTimeString()]);
        $this->assertSame($frozen, $this->frozenState($invoice, $note), 'Delivery is logistics only.');
        $this->assertSame('delivered', app(\App\Services\Fulfillment\FulfillmentQuery::class)->forOrder($this->order->fresh(), $this->owner)['delivery_state']);

        // Double-click / retry: no second event, no timestamp change, still no movement.
        $again = app(MarkFulfillmentDeliveredAction::class)->execute($this->owner, $this->order, $shipment, null, 'handed_to_carrier');
        $this->assertSame('2026-10-29 09:50:00', $again->delivered_at->toDateTimeString());
        $this->assertSame(1, AuditLog::query()->where('event', 'fulfillment.delivered')->count());
        $this->assertSame($frozen, $this->frozenState($invoice, $note));
        $this->assertSame(1, Fulfillment::query()->count());
    }

    public function test_advanced_mode_in_transit_and_delivery_move_no_stock(): void
    {
        $this->setMode(Fulfillment::MODE_ADVANCED);
        $shipment = app(StartFulfillmentPreparationAction::class)->execute($this->owner, $this->order->fresh(), [$this->lines['speaker']->id => '2', $this->lines['stands']->id => '1']);
        app(MarkFulfillmentReadyAction::class)->execute($this->owner, $this->order->fresh(), $shipment);
        $this->assertSame(0, $this->stockOuts(), 'Preparing / ready never move stock.');
        $shipment = app(HandFulfillmentToCarrierAction::class)->execute($this->owner, $this->order->fresh(), ['carrier_contact_id' => $this->carrier->id, 'handed_at' => '2026-10-29 09:00:00'], $shipment, 'ready');
        $this->assertSame(2, $this->stockOuts());
        $movements = InventoryMovement::query()->count();

        $transit = app(MarkFulfillmentInTransitAction::class)->execute($this->owner, $this->order->fresh(), $shipment, 'handed_to_carrier');
        $this->assertSame(FulfillmentStatus::InTransit, $transit->status);
        $this->assertNotNull($transit->in_transit_at);
        $this->assertSame($movements, InventoryMovement::query()->count(), 'In transit moves no stock.');

        $delivered = app(MarkFulfillmentDeliveredAction::class)->execute($this->owner, $this->order->fresh(), $shipment, null, 'in_transit');
        $this->assertSame(FulfillmentStatus::Delivered, $delivered->status);
        $this->assertSame('2026-10-29 09:00:00', $delivered->handed_to_carrier_at->toDateTimeString());
        $this->assertSame($movements, InventoryMovement::query()->count(), 'Delivered moves no stock.');
        $this->assertStock($this->speaker, onHand: '4.0000', reserved: '0.0000');
    }

    public function test_the_customer_is_emailed_once_at_hand_over_and_once_at_delivery(): void
    {
        Mail::fake();
        $this->configureOrganizationMail($this->organization);
        $operation = '7d0b5c4e-1f2a-4b3c-9d8e-0f1a2b3c4d5e';
        $shipment = $this->handAll(['tracking_number' => 'AMX-1', 'tracking_url' => 'https://track.example.test/AMX-1', 'package_count' => 2], $operation);
        $this->handAll(['tracking_number' => 'AMX-1'], $operation); // retried submit: same shipment, no second email

        Mail::assertSent(FulfillmentStatusMail::class, 1);
        Mail::assertSent(FulfillmentStatusMail::class, function (FulfillmentStatusMail $mail) {
            $html = $mail->render();

            return $mail->hasTo('client.f1@example.test') && $mail->event === 'handed_to_carrier'
                && str_contains($html, 'Votre commande '.$this->order->order_number.' a été confiée au transporteur.')
                && str_contains($html, 'Amana Express') && str_contains($html, 'AMX-1') && str_contains($html, 'https://track.example.test/AMX-1')
                && str_contains($html, 'Nombre de colis : 2');
        });

        app(MarkFulfillmentDeliveredAction::class)->execute($this->owner, $this->order, $shipment);
        app(MarkFulfillmentDeliveredAction::class)->execute($this->owner, $this->order, $shipment);
        Mail::assertSent(FulfillmentStatusMail::class, 2);
        Mail::assertSent(FulfillmentStatusMail::class, fn (FulfillmentStatusMail $mail) => $mail->event === 'delivered' && str_contains($mail->render(), 'a été livrée'));

        $this->assertSame([['handed_to_carrier', 'sent'], ['delivered', 'sent']],
            FulfillmentCustomerNotification::query()->orderBy('id')->get()->map(fn ($row) => [$row->event, $row->status])->all());
        $this->assertSame(2, AuditLog::query()->where('event', 'fulfillment.customer_notified')->count());
        $page = app(\App\Services\Fulfillment\FulfillmentQuery::class)->forOrder($this->order->fresh(), $this->owner);
        $this->assertSame(['handed_to_carrier', 'delivered'], array_column($page['fulfillments'][0]['customer_notifications'], 'event'));
    }

    public function test_notifications_are_skipped_without_outbound_mail_or_customer_email_or_when_disabled_and_never_for_direct_hand_over(): void
    {
        Mail::fake();
        $this->handAll();
        $this->assertSame(['skipped', 'mail_not_configured'], $this->notification('handed_to_carrier'));

        $this->configureOrganizationMail($this->organization);
        $this->customer->forceFill(['email' => null])->save();
        $this->order->forceFill(['customer_email' => null])->save();
        app(MarkFulfillmentDeliveredAction::class)->execute($this->owner, $this->order, Fulfillment::query()->sole());
        $this->assertSame(['skipped', 'no_customer_email'], $this->notification('delivered'));
        Mail::assertNothingSent();

        // Organization setting off → skipped, even with mail and an email.
        $this->actingAs($this->owner)->put('/organization-fulfillment-mode', ['fulfillment_mode' => 'simple', 'fulfillment_customer_notifications' => false])->assertSessionHasNoErrors();
        $this->assertFalse($this->organization->fresh()->settings['fulfillment_customer_notifications']);
        [$order, $line] = $this->secondOrder('client.two@example.test');
        $second = app(HandFulfillmentToCarrierAction::class)->execute($this->owner, $order, ['carrier_contact_id' => $this->carrier->id, 'handed_at' => '2026-10-29 09:00:00', 'items' => [$line->id => '1']]);
        $this->assertSame(['skipped', 'disabled'], FulfillmentCustomerNotification::query()->where('fulfillment_id', $second->id)->get(['status', 'skip_reason'])->map(fn ($row) => [$row->status, $row->skip_reason])->sole());

        // A direct hand-over (counter / pickup) is handed to the customer in person: no notification row at all.
        [$direct] = $this->secondOrder('client.three@example.test');
        app(FulfillSalesOrderAction::class)->execute($this->owner, $direct);
        $this->assertSame(0, FulfillmentCustomerNotification::query()->where('sales_order_id', $direct->id)->count());
        Mail::assertNothingSent();
    }

    public function test_after_hand_over_nothing_can_silently_reverse_the_stock_out(): void
    {
        [$invoice, $note] = $this->paidInvoicedWithDeliveryNote();
        $shipment = $this->handAll();
        $frozen = $this->frozenState($invoice, $note);

        $this->assertValidation('status', fn () => app(CancelFulfillmentAction::class)->execute($this->owner, $this->order, $shipment), 'A handed-over shipment cannot be cancelled.');
        $this->assertValidation('order', fn () => app(CancelSalesOrderAction::class)->execute($this->owner, $this->order->fresh(), 'Erreur'), 'A shipped order cannot be cancelled.');
        $this->assertValidation('order', fn () => app(StartSalesOrderCorrectionAction::class)->execute($this->owner, $this->order->fresh(), 'Erreur'), 'A shipped order cannot be corrected.');
        $this->assertValidation('items.'.$this->lines['speaker']->id, fn () => $this->handAll(), 'Nothing is left to ship: no second stock OUT.');

        $this->assertSame($frozen, $this->frozenState($invoice, $note));
        $this->assertSame([InventoryReservationStatus::Consumed], InventoryReservation::query()->pluck('status')->unique()->values()->all());
    }

    public function test_tracking_input_is_validated_and_closed_shipments_are_frozen(): void
    {
        $shipment = $this->handAll();
        $this->assertValidation('tracking_url', fn () => app(UpdateFulfillmentShipmentDetailsAction::class)->execute($this->owner, $this->order, $shipment, ['tracking_url' => 'javascript:alert(1)']));
        $foreign = $this->contact('Foreign carrier', $this->createOrganization(User::factory()->create(), 'Other'));
        $this->assertValidation('carrier_contact_id', fn () => app(UpdateFulfillmentShipmentDetailsAction::class)->execute($this->owner, $this->order, $shipment, ['carrier_contact_id' => $foreign->id]));
        $this->assertValidation('tracking_url', fn () => app(HandFulfillmentToCarrierAction::class)->execute($this->owner, $this->order->fresh(), ['carrier_contact_id' => $this->carrier->id, 'handed_at' => '2026-10-29 09:00:00', 'tracking_url' => 'ftp://x']));

        // An unchanged submit writes nothing.
        app(UpdateFulfillmentShipmentDetailsAction::class)->execute($this->owner, $this->order, $shipment, ['tracking_number' => null, 'carrier_contact_id' => $this->carrier->id]);
        $this->assertSame(0, AuditLog::query()->where('event', 'fulfillment.tracking_updated')->count());

        app(MarkFulfillmentDeliveredAction::class)->execute($this->owner, $this->order, $shipment);
        app(\App\Actions\Fulfillment\CloseFulfillmentAction::class)->execute($this->owner, $this->order, $shipment);
        $this->assertValidation('status', fn () => app(UpdateFulfillmentShipmentDetailsAction::class)->execute($this->owner, $this->order, $shipment, ['tracking_number' => 'LATE']));
    }

    public function test_the_sales_order_page_and_http_routes_expose_tracking_and_delivery(): void
    {
        $shipment = $this->handAll(['package_count' => 2]);
        $this->actingAs($this->owner)->patch("/sales/orders/{$this->order->id}/fulfillments/{$shipment->id}", [
            'tracking_number' => 'AMX-9', 'tracking_url' => 'https://track.example.test/AMX-9', 'expected_status' => 'handed_to_carrier',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->get("/sales/orders/{$this->order->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('fulfillment.fulfillments.0.status', 'handed_to_carrier')
            ->where('fulfillment.fulfillments.0.tracking_url', 'https://track.example.test/AMX-9')
            ->where('fulfillment.fulfillments.0.package_count', 2)
            ->where('fulfillment.fulfillments.0.actions', ['mark_delivered', 'edit_details', 'share_with_customer'])
            ->where('fulfillment.fulfillments.0.customer_notifications.0.status', 'skipped'));

        $this->actingAs($this->owner)->post("/sales/orders/{$this->order->id}/fulfillments/{$shipment->id}/delivered", ['expected_status' => 'handed_to_carrier'])->assertSessionHasNoErrors();
        // The double-click a slow page sends with the old status is a no-op success.
        $this->actingAs($this->owner)->post("/sales/orders/{$this->order->id}/fulfillments/{$shipment->id}/delivered", ['expected_status' => 'handed_to_carrier'])->assertSessionHasNoErrors();
        $this->assertSame(FulfillmentStatus::Delivered, $shipment->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('event', 'fulfillment.delivered')->count());
        $this->assertSame(2, $this->stockOuts());
    }

    public function test_share_with_customer_by_email_previews_the_content_attaches_proofs_moves_nothing_and_never_sends_twice(): void
    {
        Mail::fake();
        $this->configureOrganizationMail($this->organization);
        [$invoice, $note] = $this->paidInvoicedWithDeliveryNote();
        $shipment = $this->handAll(['tracking_number' => 'AMX-1', 'tracking_url' => 'https://track.example.test/AMX-1', 'package_count' => 2]);
        Mail::assertSent(FulfillmentStatusMail::class, 1); // the automatic hand-over email
        $proof = $this->proof($shipment);
        $frozen = $this->frozenState($invoice, $note);

        // The dialog shows exactly what will be shared.
        $preview = app(ShareFulfillmentWithCustomerAction::class)->preview($this->owner, $this->order->fresh(), $shipment);
        $this->assertSame('client.f1@example.test', $preview['email']['to']);
        foreach (['Bonjour Client Test F1,', 'Votre commande '.$this->order->order_number.' a été confiée au transporteur.', 'Amana Express', 'AMX-1',
            'https://track.example.test/AMX-1', 'Nombre de colis : 2', 'preuve.pdf'] as $text) {
            $this->assertStringContainsString($text, $preview['email']['html']);
        }
        $this->assertSame([[(string) $proof->id, true]], array_map(fn (array $row) => [$row['id'], $row['selected']], $preview['proofs']));
        $this->assertNull($preview['email']['already_sent'], 'With the proof it is not the message the automatic email carried.');

        // Without the proof it is the automatic email again: refused without an explicit resend.
        $this->assertValidation('resend', fn () => $this->share($shipment, ['channel' => 'email', 'client_operation_id' => self::OP[0], 'email' => 'client.f1@example.test']));
        Mail::assertSent(FulfillmentStatusMail::class, 1);

        $sent = $this->share($shipment, ['channel' => 'email', 'client_operation_id' => self::OP[1], 'email' => 'Client.F1@example.test', 'attachment_ids' => [$proof->id]]);
        $this->share($shipment, ['channel' => 'email', 'client_operation_id' => self::OP[1], 'email' => 'Client.F1@example.test', 'attachment_ids' => [$proof->id]]); // retried submit
        Mail::assertSent(FulfillmentStatusMail::class, 2);
        Mail::assertSent(FulfillmentStatusMail::class, fn (FulfillmentStatusMail $mail) => $mail->hasTo('client.f1@example.test') && count($mail->proofs) === 1 && count($mail->attachments()) === 1);
        $this->assertSame(['manual', 'email', 'sent', 'client.f1@example.test', $this->owner->id, [(string) $proof->id]],
            [$sent->origin, $sent->channel, $sent->status, $sent->recipient, (int) $sent->sent_by_user_id, $sent->attachment_ids]);

        // The same message again needs "Renvoyer quand même".
        $this->assertValidation('resend', fn () => $this->share($shipment, ['channel' => 'email', 'client_operation_id' => self::OP[2], 'email' => 'client.f1@example.test', 'attachment_ids' => [$proof->id]]));
        $this->share($shipment, ['channel' => 'email', 'client_operation_id' => self::OP[3], 'email' => 'client.f1@example.test', 'attachment_ids' => [$proof->id], 'resend' => true]);
        Mail::assertSent(FulfillmentStatusMail::class, 3);

        // Communication only, and recorded in the shipment history and the order's business timeline.
        $this->assertSame($frozen, $this->frozenState($invoice, $note), 'Sharing moves no stock and changes no order, invoice, payment or delivery note.');
        $this->assertSame(FulfillmentStatus::HandedToCarrier, $shipment->fresh()->status);
        $this->assertSame(2, AuditLog::query()->where('event', 'fulfillment.shared_with_customer')->count());
        $this->assertSame(2, AuditLog::query()->where('event', 'sales_order.shipment_shared_email')->where('auditable_type', SalesOrder::class)->where('auditable_id', $this->order->id)->count());
        $timeline = app(BusinessTimeline::class)->page($this->owner, $this->organization, 'sales_order', $this->order->id);
        $this->assertContains('sales_order.shipment_shared_email', array_column($timeline['entries'], 'event'));
    }

    public function test_share_on_whatsapp_is_recorded_as_opened_never_as_sent_with_signed_proof_links_and_moves_nothing(): void
    {
        Mail::fake();
        $this->order->forceFill(['customer_phone' => '+212 6 12 34 56 78'])->save();
        $shipment = $this->handAll(['tracking_number' => 'AMX-1']);
        $proof = $this->proof($shipment);
        $movements = InventoryMovement::query()->count();

        $preview = app(ShareFulfillmentWithCustomerAction::class)->preview($this->owner, $this->order->fresh(), $shipment);
        $message = $preview['whatsapp']['message'];
        $this->assertTrue($preview['whatsapp']['destination']['available']);
        foreach (['Bonjour Client Test F1,', $this->order->order_number, 'Amana Express', 'AMX-1'] as $text) $this->assertStringContainsString($text, $message);
        $this->assertSame(1, preg_match('#https?://\S+/shared/fulfillment-proofs/\S+#', $message, $link));

        // The customer opens the proof without an account; an unsigned link is refused.
        $this->get($link[0])->assertOk()->assertDownload('preuve.pdf');
        $this->get(strtok($link[0], '?'))->assertForbidden();

        $row = $this->share($shipment, ['channel' => 'whatsapp', 'client_operation_id' => self::OP[0], 'message' => $message, 'attachment_ids' => [$proof->id]]);
        $this->share($shipment, ['channel' => 'whatsapp', 'client_operation_id' => self::OP[0], 'message' => $message, 'attachment_ids' => [$proof->id]]); // retried submit
        $this->assertSame(['whatsapp', 'opened', '+'.$preview['whatsapp']['destination']['destination'], $message],
            [$row->channel, $row->status, $row->recipient, $row->message]);
        $this->assertSame(1, FulfillmentCustomerNotification::query()->where('origin', 'manual')->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'sales_order.shipment_shared_whatsapp')->count());
        Mail::assertNothingSent();
        $this->assertSame($movements, InventoryMovement::query()->count());
        $this->assertSame(FulfillmentStatus::HandedToCarrier, $shipment->fresh()->status);

        // No phone and no WhatsApp username: nothing to open.
        $this->order->forceFill(['customer_phone' => null, 'customer_whatsapp_username' => null])->save();
        $this->assertValidation('phone', fn () => $this->share($shipment, ['channel' => 'whatsapp', 'client_operation_id' => self::OP[1], 'message' => $message]));
    }

    public function test_share_supersedes_a_queued_automatic_email_needs_permission_and_only_applies_between_hand_over_and_delivery(): void
    {
        Mail::fake();
        Queue::fake();
        $this->configureOrganizationMail($this->organization);
        $shipment = $this->handAll(['tracking_number' => 'AMX-1']);
        $automatic = FulfillmentCustomerNotification::query()->where('origin', 'automatic')->sole();
        $this->assertSame('pending', $automatic->status);

        // The operator shares first: the queued automatic email is superseded, the customer gets one email.
        $this->share($shipment, ['channel' => 'email', 'client_operation_id' => self::OP[0], 'email' => 'client.f1@example.test']);
        $this->assertSame(['skipped', 'already_shared'], [$automatic->fresh()->status, $automatic->fresh()->skip_reason]);
        app(FulfillmentCustomerNotifier::class)->deliver($automatic->id); // the queued job runs later
        Mail::assertSent(FulfillmentStatusMail::class, 1);

        // Sharing is a dispatcher / manager action.
        $clerk = User::factory()->create();
        $this->addOrganizationMember($this->organization, $clerk, ['sales_orders.view', 'fulfillment.view']);
        $this->addStoreMember($this->store, $clerk);
        try {
            $this->share($shipment, ['channel' => 'email', 'client_operation_id' => self::OP[1], 'email' => 'client.f1@example.test', 'resend' => true], $clerk);
            $this->fail('A viewer cannot share.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        // Not before hand-over (nothing left yet) …
        $this->setMode(Fulfillment::MODE_ADVANCED);
        [$order, $line] = $this->secondOrder('client.two@example.test');
        $preparing = app(StartFulfillmentPreparationAction::class)->execute($this->owner, $order, [$line->id => '1']);
        $this->assertValidation('status', fn () => app(ShareFulfillmentWithCustomerAction::class)->execute($this->owner, $order->fresh(), $preparing,
            ['channel' => 'email', 'client_operation_id' => self::OP[2], 'email' => 'client.two@example.test']));
        // … and not once delivered.
        app(MarkFulfillmentDeliveredAction::class)->execute($this->owner, $this->order->fresh(), $shipment);
        $this->assertValidation('status', fn () => $this->share($shipment, ['channel' => 'email', 'client_operation_id' => self::OP[3], 'email' => 'client.f1@example.test', 'resend' => true]));
        Mail::assertSent(FulfillmentStatusMail::class, 1);
    }

    public function test_share_routes_preview_and_send(): void
    {
        Mail::fake();
        $this->configureOrganizationMail($this->organization);
        $shipment = $this->handAll(['tracking_number' => 'AMX-1']);
        $proof = $this->proof($shipment);
        $base = "/sales/orders/{$this->order->id}/fulfillments/{$shipment->id}/share";

        $this->actingAs($this->owner)->getJson($base)->assertOk()->assertJsonPath('email.to', 'client.f1@example.test')->assertJsonPath('proofs.0.selected', true);
        $this->actingAs($this->owner)->getJson($base.'?proofs_chosen=1')->assertOk()->assertJsonPath('proofs.0.selected', false);
        $this->actingAs($this->owner)->post($base, ['channel' => 'email', 'client_operation_id' => self::OP[0], 'email' => 'client.f1@example.test', 'attachment_ids' => [$proof->id]])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post($base, ['channel' => 'email', 'client_operation_id' => 'not-a-uuid', 'email' => 'client.f1@example.test'])->assertSessionHasErrors('client_operation_id');
        $this->actingAs($this->owner)->get("/sales/orders/{$this->order->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('fulfillment.fulfillments.0.customer_notifications.1.origin', 'manual')
            ->where('fulfillment.fulfillments.0.customer_notifications.1.channel', 'email')
            ->where('fulfillment.fulfillments.0.customer_notifications.1.sent_by', $this->owner->name));
    }


    /* ---------------------------------------------------------------- helpers */

    /** @return array{Invoice, DeliveryNote} paid in full, invoice issued, Bon de livraison issued */
    private function paidInvoicedWithDeliveryNote(): array
    {
        $bank = $this->createFinancialAccount($this->organization, ['type' => 'bank', 'name' => 'Banque', 'code' => 'BANK']);
        $this->recordPayment($this->owner, $this->order, $bank, (string) $this->order->fresh()->total_incl_tax, ['method' => 'bank_transfer']);
        $invoice = app(IssueInvoiceAction::class)->execute($this->owner, $this->createInvoice($this->owner, $this->order->fresh()))->fresh();
        $note = $this->issueDeliveryNote($this->owner, $this->createDeliveryNote($this->owner, $this->order->fresh()));

        return [$invoice, $note];
    }

    /** @param array<string, mixed> $data */
    private function handAll(array $data = [], ?string $operation = null): Fulfillment
    {
        return app(HandFulfillmentToCarrierAction::class)->execute($this->owner, $this->order->fresh(), [
            'carrier_contact_id' => $this->carrier->id, 'handed_at' => '2026-10-29 09:00:00',
            'items' => [$this->lines['speaker']->id => '2', $this->lines['stands']->id => '1'], ...$data,
        ], null, null, $operation);
    }

    /** Everything that must never change after hand-over. @return array<string, mixed> */
    private function frozenState(Invoice $invoice, DeliveryNote $note): array
    {
        $order = $this->order->fresh();

        return [
            'movements' => InventoryMovement::query()->orderBy('id')->get(['id', 'movement_type', 'quantity'])->toArray(),
            'balances' => InventoryBalance::query()->orderBy('id')->get(['id', 'on_hand', 'reserved'])->toArray(),
            'reservations' => InventoryReservation::query()->orderBy('id')->get(['id', 'status', 'quantity'])->toArray(),
            'order' => [$order->status->value, $order->fulfillment_status->value, $order->payment_status->value, (string) $order->total_incl_tax, $order->updated_at?->toDateTimeString()],
            'lines' => SalesOrderLine::query()->where('sales_order_id', $order->id)->orderBy('id')->get(['id', 'quantity', 'unit_price_excl_tax', 'total_incl_tax'])->toArray(),
            'invoice' => Invoice::query()->whereKey($invoice->id)->get(['status', 'invoice_number', 'total_incl_tax', 'updated_at'])->toArray(),
            'delivery_note' => DeliveryNote::query()->whereKey($note->id)->get(['status', 'delivery_note_number', 'updated_at'])->toArray(),
            'payments' => Payment::query()->orderBy('id')->get(['id', 'status', 'amount'])->toArray(),
        ];
    }

    private function proof(Fulfillment $shipment): FulfillmentAttachment
    {
        return app(AttachFulfillmentProofAction::class)->execute($this->owner, $this->order->fresh(), $shipment, [UploadedFile::fake()->create('preuve.pdf', 12, 'application/pdf')])[0];
    }

    /** @param array<string, mixed> $data */
    private function share(Fulfillment $shipment, array $data, ?User $actor = null): FulfillmentCustomerNotification
    {
        return app(ShareFulfillmentWithCustomerAction::class)->execute($actor ?? $this->owner, $this->order->fresh(), $shipment, $data);
    }

    private function stockOuts(): int
    {
        return InventoryMovement::query()->where('movement_type', InventoryMovementType::ReservationConsumed)->count();
    }

    private function assertStock(ProductVariant $variant, string $onHand, string $reserved): void
    {
        $balance = InventoryBalance::query()->where('warehouse_id', $this->warehouse->id)->where('product_variant_id', $variant->id)->sole();
        $this->assertSame([$onHand, $reserved], [(string) $balance->on_hand, (string) $balance->reserved]);
    }

    /** @return array{0: string, 1: ?string} */
    private function notification(string $event): array
    {
        $row = FulfillmentCustomerNotification::query()->where('sales_order_id', $this->order->id)->where('event', $event)
            ->where('origin', FulfillmentCustomerNotification::ORIGIN_AUTOMATIC)->sole();

        return [$row->status, $row->skip_reason];
    }

    /** @return array{SalesOrder, SalesOrderLine} a confirmed one-line order of a new customer */
    private function secondOrder(string $email): array
    {
        $order = $this->createDraftOrder($this->owner, $this->organization, $this->store, $this->createCustomer($this->organization, $email, ['email' => $email]));
        $line = $this->addCatalogLine($this->owner, $order, $this->stands, $this->warehouse, ['quantity' => '1']);

        return [app(ConfirmSalesOrderAction::class)->execute($this->owner, $order->fresh())->fresh(), $line->fresh()];
    }

    private function contact(string $name, ?Organization $organization = null): OrganizationContact
    {
        $contact = new OrganizationContact;
        $contact->forceFill(['organization_id' => ($organization ?? $this->organization)->id, 'full_name' => $name, 'company_name' => $name, 'contact_type' => OrganizationContact::TYPE_TRANSPORT, 'active' => true])->save();

        return $contact;
    }

    private function setMode(string $mode): void
    {
        $settings = $this->organization->fresh()->settings ?? [];
        $settings['fulfillment_mode'] = $mode;
        $this->organization->forceFill(['settings' => $settings])->save();
        $this->organization->refresh();
        $this->order->setRelation('organization', $this->organization);
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
}
