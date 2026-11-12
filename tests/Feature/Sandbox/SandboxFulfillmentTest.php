<?php

namespace Tests\Feature\Sandbox;

use App\Actions\Fulfillment\HandFulfillmentToCarrierAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Jobs\SendFulfillmentCustomerNotificationJob;
use App\Models\Fulfillment;
use App\Models\FulfillmentCustomerNotification;
use App\Models\InventoryBalance;
use App\Models\OrganizationContact;
use App\Services\OrganizationOutboundMailService;
use App\Services\Sandbox\SandboxInventoryGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\QuotationTestCase;

/** Phase 7: Test fulfillment / carrier / hand-over on the Test ledgers; Production untouched, outbound simulated. */
class SandboxFulfillmentTest extends QuotationTestCase
{
    public function test_test_fulfillment_consumes_test_stock_once_simulates_notifications_and_leaves_production_unchanged(): void
    {
        [$owner, $org, $store, $variant] = $this->quotationCatalogFixture();
        $warehouse = $this->createWarehouse($org, 'Main', 'MAIN');
        $this->openStock($owner, $org, $warehouse, $variant, '5.0000');
        $carrier = (new OrganizationContact)->forceFill(['organization_id' => $org->id, 'full_name' => 'Amana', 'company_name' => 'Amana Express',
            'contact_type' => OrganizationContact::TYPE_TRANSPORT, 'active' => true]);
        $carrier->save();
        $this->actingAs($owner);

        // Real outbound services must never be reached from Test Mode.
        $this->mock(OrganizationOutboundMailService::class, fn ($mail) => $mail->shouldNotReceive('send'));
        Mail::fake();
        Queue::fake();

        // A Production order exists before Test Mode (1 unit reserved in Production).
        $prodOrder = $this->createDraftOrder($owner, $org, $store);
        $this->addCatalogLine($owner, $prodOrder, $variant, $warehouse, ['quantity' => '1']);
        $prodOrder = app(ConfirmSalesOrderAction::class)->execute($owner, $prodOrder->fresh())->fresh();
        $productionStock = $this->balance($org, $warehouse, $variant)->only(['on_hand', 'reserved']);
        $productionMovements = DB::table('inventory_movements')->count();

        $this->enterTest();
        $order = $this->createDraftOrder($owner, $org, $store);
        $line = $this->addCatalogLine($owner, $order, $variant, $warehouse, ['quantity' => '3']);
        $this->post("/sales/orders/{$order->id}/confirm")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($order->fresh()->sandbox_id);
        $this->assertSame('5.0000', $this->testStock($warehouse, $variant)->on_hand);

        // 3. A Test shipment can never use a Production order (404, nothing written).
        $this->assertHttpStatus(404, fn () => app(HandFulfillmentToCarrierAction::class)->execute($owner, $prodOrder->fresh(), $this->shipment($carrier, [$prodOrder->lines()->value('id') => '1'])));
        $this->postJson("/sales/orders/{$prodOrder->id}/fulfill")->assertNotFound();
        $this->assertSame(0, Fulfillment::query()->count());

        // Carrier shipment of 2 of 3: Test stock leaves ONCE, a retry is a no-op.
        $operation = '0b4c1d2e-3f40-4a5b-8c6d-7e8f9a0b1c2d';
        $payload = ['client_operation_id' => $operation, 'items' => [$line->id => '2'], 'carrier_contact_id' => $carrier->id,
            'handed_at' => now()->subMinute()->toDateTimeString(), 'tracking_number' => 'TRK-1'];
        $this->post("/sales/orders/{$order->id}/fulfillments/hand-to-carrier", $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post("/sales/orders/{$order->id}/fulfillments/hand-to-carrier", $payload)->assertRedirect();
        $shipment = Fulfillment::query()->sole();
        $this->assertSame('sandbox_fulfillments', $shipment->getTable());
        $this->assertSame('handed_to_carrier', $shipment->status->value);
        $this->assertSame($carrier->id, $shipment->carrier_contact_id);
        $stock = $this->testStock($warehouse, $variant);
        $this->assertSame(['3.0000', '1.0000'], [$stock->on_hand, $stock->reserved]);
        $this->assertSame(1, DB::table('sandbox_inventory_movements')->where('movement_type', 'reservation_consumed')->count());
        $this->assertSame('partially_fulfilled', $order->fresh()->fulfillment_status->value);

        // Shipping proofs (stored files) are not available in Test Mode, and nothing moved.
        $this->post("/sales/orders/{$order->id}/fulfillments/hand-to-carrier", [...$payload, 'client_operation_id' => '1b4c1d2e-3f40-4a5b-8c6d-7e8f9a0b1c2d',
            'proofs' => [\Illuminate\Http\UploadedFile::fake()->image('proof.png')]])->assertSessionHasErrors('proofs');
        $this->assertSame(1, Fulfillment::query()->count());

        // Customer notification: simulated, never queued or mailed.
        $notification = FulfillmentCustomerNotification::query()->sole();
        $this->assertSame('sandbox_fulfillment_customer_notifications', $notification->getTable());
        $this->assertSame(['skipped', 'test_simulated', null], [$notification->status, $notification->skip_reason, $notification->recipient]);
        Queue::assertNotPushed(SendFulfillmentCustomerNotificationJob::class);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();

        // Delivered once (a repeat is a no-op, no second notification), then closed.
        $this->post("/sales/orders/{$order->id}/fulfillments/{$shipment->id}/delivered")->assertRedirect()->assertSessionHasNoErrors();
        $this->post("/sales/orders/{$order->id}/fulfillments/{$shipment->id}/delivered")->assertRedirect();
        $this->assertSame(2, FulfillmentCustomerNotification::query()->count());
        $this->post("/sales/orders/{$order->id}/fulfillments/{$shipment->id}/close")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('closed', $shipment->fresh()->status->value);

        // Direct hand-over of the rest: once only.
        $this->post("/sales/orders/{$order->id}/fulfill")->assertRedirect()->assertSessionHasNoErrors();
        $this->post("/sales/orders/{$order->id}/fulfill")->assertSessionHasErrors();
        $stock = $this->testStock($warehouse, $variant);
        $this->assertSame(['2.0000', '0.0000'], [$stock->on_hand, $stock->reserved]);
        $this->assertSame('fulfilled', $order->fresh()->fulfillment_status->value);
        $this->assertSame(2, Fulfillment::query()->count());
        $this->get("/sales/orders/{$order->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('fulfillment.test', true)->where('fulfillment.can.attach', false));
        // Proofs, customer sharing and returns stay blocked.
        $this->postJson("/sales/orders/{$order->id}/fulfillments/{$shipment->id}/share")->assertForbidden()->assertJsonPath('code', 'SANDBOX_DOMAIN_UNAVAILABLE');
        $this->postJson("/sales/orders/{$order->id}/fulfillments/{$shipment->id}/attachments")->assertForbidden()->assertJsonPath('code', 'SANDBOX_DOMAIN_UNAVAILABLE');
        $this->postJson("/sales/orders/{$order->id}/returns")->assertForbidden()->assertJsonPath('code', 'SANDBOX_DOMAIN_UNAVAILABLE');

        // Raw SQL on Production fulfillment tables is refused in Test Mode.
        try {
            DB::table('fulfillments')->count();
            $this->fail('Production fulfillments readable in Test Mode.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('fulfillments', $e->getMessage());
        }

        // 2. Production is unchanged and cannot see Test shipments.
        $this->leaveTest();
        $this->assertEquals($productionStock, $this->balance($org, $warehouse, $variant)->only(['on_hand', 'reserved']));
        $this->assertSame($productionMovements, DB::table('inventory_movements')->count());
        $this->assertSame(0, DB::table('fulfillments')->count());
        $this->assertSame(0, DB::table('fulfillment_items')->count());
        $this->assertSame(0, DB::table('fulfillment_customer_notifications')->count());
        $this->assertNull(Fulfillment::query()->first());

        // 4. A Production shipment can never use a Test order or a Test shipment.
        $this->assertHttpStatus(404, fn () => app(HandFulfillmentToCarrierAction::class)->execute($owner, $order, $this->shipment($carrier, [$line->id => '1'])));
        $this->postJson("/sales/orders/{$order->id}/fulfill")->assertNotFound();
        $this->post("/sales/orders/{$order->id}/fulfillments/{$shipment->id}/delivered")->assertNotFound();

        // 7. Production fulfillment still behaves unchanged: carrier shipment, Production stock, queued notification.
        $prodLine = $prodOrder->lines()->value('id');
        $this->post("/sales/orders/{$prodOrder->id}/fulfillments/hand-to-carrier", ['items' => [$prodLine => '1'], 'carrier_contact_id' => $carrier->id,
            'handed_at' => now()->subMinute()->toDateTimeString()])->assertRedirect()->assertSessionHasNoErrors();
        $prod = Fulfillment::query()->sole();
        $this->assertSame('fulfillments', $prod->getTable());
        $this->assertSame(['4.0000', '0.0000'], [$this->balance($org, $warehouse, $variant)->on_hand, $this->balance($org, $warehouse, $variant)->reserved]);
        $this->assertSame('pending', FulfillmentCustomerNotification::query()->sole()->status);
        Queue::assertPushed(SendFulfillmentCustomerNotificationJob::class, 1);

        // Test stock is untouched by the Production shipment.
        $this->enterTest();
        $this->assertSame('2.0000', $this->testStock($warehouse, $variant)->on_hand);
        $this->assertSame(2, Fulfillment::query()->count());
    }

    public function test_a_test_shipment_cannot_reference_a_production_order(): void
    {
        [$owner, $org, $store, $variant] = $this->quotationCatalogFixture();
        $warehouse = $this->createWarehouse($org, 'Main', 'MAIN');
        $this->openStock($owner, $org, $warehouse, $variant, '5.0000');
        $this->actingAs($owner);
        $prodOrder = $this->createDraftOrder($owner, $org, $store);
        $this->addCatalogLine($owner, $prodOrder, $variant, $warehouse, ['quantity' => '1']);
        $prodOrder = app(ConfirmSalesOrderAction::class)->execute($owner, $prodOrder->fresh())->fresh();

        $this->enterTest();
        $this->assertHttpStatus(404, function () use ($prodOrder, $org, $store) {
            (new Fulfillment)->forceFill(['organization_id' => $org->id, 'store_id' => $store->id, 'sales_order_id' => $prodOrder->id,
                'status' => 'preparing', 'workflow_mode' => 'simple', 'channel' => 'carrier'])->save();
        });
        $this->assertSame(0, Fulfillment::query()->count());
        $this->leaveTest();
        $this->assertSame(0, SandboxInventoryGuard::bypass(fn () => DB::table('sandbox_fulfillments')->count()));
    }

    /** @param array<int, string> $items */
    private function shipment(OrganizationContact $carrier, array $items): array
    {
        return ['items' => $items, 'carrier_contact_id' => $carrier->id, 'handed_at' => now()->subMinute()->toDateTimeString()];
    }

    private function testStock($warehouse, $variant): InventoryBalance
    {
        return InventoryBalance::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->sole();
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

    private function enterTest(): void
    {
        $this->post('/test-mode/policy', ['enabled' => true])->assertRedirect();
        $this->post('/test-mode/enter')->assertRedirect();
        $this->get('/test-mode')->assertOk();
    }

    private function leaveTest(): void
    {
        $this->post('/test-mode/leave')->assertRedirect();
        $this->get('/test-mode')->assertOk();
    }
}
