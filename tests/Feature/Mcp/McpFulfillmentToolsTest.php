<?php

namespace Tests\Feature\Mcp;

use App\Actions\Fulfillment\HandFulfillmentToCarrierAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Enums\FulfillmentStatus;
use App\Enums\InventoryMovementType;
use App\Models\AuditLog;
use App\Models\Fulfillment;
use App\Models\FulfillmentCustomerNotification;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Mcp\Fulfillment\FulfillmentTools;
use App\Services\Mcp\McpConnectionCredentials;
use App\Services\Mcp\McpExecutionContext;
use Carbon\CarbonImmutable;
use Tests\Support\QuotationTestCase;

/** F1.2 shipment tools: read, tracking update, mark delivered — never a stock OUT. */
class McpFulfillmentToolsTest extends QuotationTestCase
{
    private const SCOPES = ['fulfillment.view', 'fulfillment.manage', 'fulfillment.mark_delivered', 'sales_orders.view'];

    private User $owner;

    private Organization $organization;

    private Store $store;

    private Warehouse $warehouse;

    private ProductVariant $variant;

    private OrganizationContact $carrier;

    private SalesOrder $order;

    private SalesOrderLine $line;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->organization->settings = ['allow_mcp_connections' => true];
        $this->organization->save();
        $this->organization->refresh();
        $this->store = $this->createStore($this->organization, $this->owner, 'Showroom');
        $this->warehouse = $this->createWarehouse($this->organization);
        $this->activate($this->owner, $this->organization, $this->store);
        $this->variant = $this->createProduct($this->organization, 'HK Audio PR:O 12', 'SHK-PRO-12', ['default_sale_price' => '5169.1667'])->variants->first();
        $this->openStock($this->owner, $this->organization, $this->warehouse, $this->variant, '6.0000');
        $this->carrier = $this->carrier('Amana Express');
        $order = $this->createDraftOrder($this->owner, $this->organization, $this->store, $this->createCustomer($this->organization, 'Client', ['email' => 'client@example.test']));
        $this->line = $this->addCatalogLine($this->owner, $order, $this->variant, $this->warehouse, ['quantity' => '2']);
        $this->order = app(ConfirmSalesOrderAction::class)->execute($this->owner, $order->fresh())->fresh();
    }

    public function test_no_tool_can_hand_goods_over_and_tools_follow_scope_and_permission(): void
    {
        $names = $this->listTools($this->owner, self::SCOPES);
        foreach (['get_sales_order_shipments', 'preview_shipment_tracking_update', 'update_shipment_tracking', 'preview_shipment_delivery', 'mark_shipment_delivered'] as $name) {
            $this->assertContains($name, $names);
        }
        $this->assertSame([], array_values(array_filter($names, fn (string $name) => preg_match('/hand|dispatch|fulfil|ship_order|prepar|close_ship|cancel_ship/i', $name) === 1)),
            'The stock OUT (hand-over) is never exposed over MCP.');

        $this->assertSame(['get_sales_order_shipments'], array_values(array_filter($this->listTools($this->owner, ['fulfillment.view']), fn ($name) => str_contains($name, 'shipment'))));

        $viewer = $this->member(['fulfillment.view']);
        $names = $this->listTools($viewer, self::SCOPES);
        $this->assertContains('get_sales_order_shipments', $names);
        $this->assertNotContains('mark_shipment_delivered', $names, 'A scope is a ceiling, never a grant.');
    }

    public function test_read_tracking_update_and_delivery_move_no_stock_and_are_idempotent(): void
    {
        $shipment = $this->hand();
        $this->assertSame(1, $this->stockOuts());
        $movements = InventoryMovement::query()->count();
        $this->connect($this->owner);

        $read = $this->tool('getSalesOrderShipments', ['sales_order_id' => $this->order->id])['data'];
        $this->assertSame(['fulfilled', 'not_delivered'], [$read['fulfillment_status'], $read['delivery_state']]);
        $this->assertSame([$shipment->id, 'handed_to_carrier', 'Amana Express', ['mark_delivered', 'edit_details']],
            [$read['shipments'][0]['id'], $read['shipments'][0]['status'], $read['shipments'][0]['carrier']['name'], $read['shipments'][0]['mcp_actions']]);
        $this->assertArrayNotHasKey('actions', $read['shipments'][0]);

        // "Modifier le suivi"
        $tracking = ['tracking_number' => 'AMX-42', 'tracking_url' => 'https://track.example.test/AMX-42', 'package_count' => 2];
        $preview = $this->tool('previewShipmentTrackingUpdate', ['sales_order_id' => $this->order->id, 'shipment_id' => $shipment->id, 'tracking' => $tracking])['data'];
        $this->assertTrue($preview['valid'], json_encode($preview['issues']));
        $this->assertSame(['tracking_number', 'tracking_url', 'package_count'], $preview['changes']);
        $this->assertSame('none', $preview['stock_effect']);
        $this->assertNull($shipment->fresh()->tracking_number, 'A preview saves nothing.');
        $updated = $this->tool('updateShipmentTracking', ['sales_order_id' => $this->order->id, 'shipment_id' => $shipment->id, 'tracking' => $tracking,
            'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => 'trk-1']);
        $this->assertTrue($updated['ok'], json_encode($updated));
        $this->assertSame(['AMX-42', 'https://track.example.test/AMX-42', 2, 'handed_to_carrier'],
            [$updated['data']['shipment']['tracking_number'], $updated['data']['shipment']['tracking_url'], $updated['data']['shipment']['package_count'], $updated['data']['shipment']['status']]);
        $this->assertSame('mcp', AuditLog::query()->where('event', 'fulfillment.tracking_updated')->sole()->actor_channel);
        $this->assertTrue($this->tool('updateShipmentTracking', ['sales_order_id' => $this->order->id, 'shipment_id' => $shipment->id, 'tracking' => $tracking,
            'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => 'trk-1'])['data']['idempotent_replay']);
        $this->assertSame(1, AuditLog::query()->where('event', 'fulfillment.tracking_updated')->count());

        // "Marquer comme livré"
        $delivery = $this->tool('previewShipmentDelivery', ['sales_order_id' => $this->order->id, 'shipment_id' => $shipment->id, 'delivered_at' => '2026-10-29T09:30:00+00:00'])['data'];
        $this->assertTrue($delivery['valid'], json_encode($delivery['issues']));
        $this->assertSame('delivered', $delivery['after']['status']);
        $this->assertSame(FulfillmentStatus::HandedToCarrier, $shipment->fresh()->status, 'A preview saves nothing.');
        $this->assertSame(0, FulfillmentCustomerNotification::query()->where('event', 'delivered')->count(), 'A preview never notifies.');
        $delivered = $this->tool('markShipmentDelivered', ['sales_order_id' => $this->order->id, 'shipment_id' => $shipment->id, 'delivered_at' => '2026-10-29T09:30:00+00:00',
            'preview_fingerprint' => $delivery['preview_fingerprint'], 'idempotency_key' => 'dlv-1']);
        $this->assertTrue($delivered['ok'], json_encode($delivered));
        $this->assertSame(['delivered', '2026-10-29T09:00:00+00:00', '2026-10-29T09:30:00+00:00'],
            [$delivered['data']['shipment']['status'], $delivered['data']['shipment']['handed_to_carrier_at'], $delivered['data']['shipment']['delivered_at']]);

        // Retry with the same key replays; a fresh preview reports already_delivered; nothing is repeated.
        $this->assertTrue($this->tool('markShipmentDelivered', ['sales_order_id' => $this->order->id, 'shipment_id' => $shipment->id, 'delivered_at' => '2026-10-29T09:30:00+00:00',
            'preview_fingerprint' => $delivery['preview_fingerprint'], 'idempotency_key' => 'dlv-1'])['data']['idempotent_replay']);
        $again = $this->tool('previewShipmentDelivery', ['sales_order_id' => $this->order->id, 'shipment_id' => $shipment->id])['data'];
        $this->assertSame(['already_delivered'], array_column($again['issues'], 'code'));
        $this->assertNull($again['preview_fingerprint']);
        $this->assertSame(1, AuditLog::query()->where('event', 'fulfillment.delivered')->count());
        $this->assertSame(1, FulfillmentCustomerNotification::query()->where('event', 'delivered')->count());

        $this->assertSame($movements, InventoryMovement::query()->count(), 'MCP tracking and delivery never move stock.');
        $this->assertSame(1, $this->stockOuts());
    }

    public function test_stale_previews_invalid_input_and_foreign_orders_are_refused(): void
    {
        $shipment = $this->hand();
        $this->connect($this->owner);

        $preview = $this->tool('previewShipmentTrackingUpdate', ['sales_order_id' => $this->order->id, 'shipment_id' => $shipment->id, 'tracking' => ['tracking_number' => 'A']])['data'];
        // The shipment changes after the preview (e.g. on the web): the fingerprint is stale.
        app(\App\Actions\Fulfillment\UpdateFulfillmentShipmentDetailsAction::class)->execute($this->owner, $this->order, $shipment, ['package_count' => 4]);
        $this->assertSame('MCP_PREVIEW_STALE', $this->tool('updateShipmentTracking', ['sales_order_id' => $this->order->id, 'shipment_id' => $shipment->id, 'tracking' => ['tracking_number' => 'A'],
            'preview_fingerprint' => $preview['preview_fingerprint'], 'idempotency_key' => 'stale'])['error']['code']);

        $this->assertSame('MCP_INVALID_ARGUMENTS', $this->tool('previewShipmentTrackingUpdate', ['sales_order_id' => $this->order->id, 'shipment_id' => $shipment->id, 'tracking' => ['status' => 'delivered']])['error']['code']);
        $this->assertSame('MCP_VALIDATION_FAILED', $this->tool('previewShipmentTrackingUpdate', ['sales_order_id' => $this->order->id, 'shipment_id' => $shipment->id, 'tracking' => ['tracking_url' => 'javascript:alert(1)']])['error']['code']);
        $this->assertSame(['invalid_delivered_at'], array_column($this->tool('previewShipmentDelivery', ['sales_order_id' => $this->order->id, 'shipment_id' => $shipment->id, 'delivered_at' => '2026-10-28T09:00:00+00:00'])['data']['issues'], 'code'));

        // Another store's order is invisible.
        $otherStore = $this->createStore($this->organization, $this->owner, 'Other store');
        $viewer = $this->member(['fulfillment.view', 'fulfillment.manage', 'fulfillment.mark_delivered'], $otherStore);
        $this->connect($viewer);
        $this->assertSame('MCP_SHIPMENT_NOT_FOUND', $this->tool('getSalesOrderShipments', ['sales_order_id' => $this->order->id])['error']['code']);
        $this->assertSame('MCP_SHIPMENT_NOT_FOUND', $this->tool('previewShipmentDelivery', ['sales_order_id' => $this->order->id, 'shipment_id' => $shipment->id])['error']['code']);
        $this->assertSame(FulfillmentStatus::HandedToCarrier, $shipment->fresh()->status);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function hand(): Fulfillment
    {
        return app(HandFulfillmentToCarrierAction::class)->execute($this->owner, $this->order->fresh(), [
            'carrier_contact_id' => $this->carrier->id, 'handed_at' => '2026-10-29 09:00:00', 'items' => [$this->line->id => '2'],
        ]);
    }

    private function stockOuts(): int
    {
        return InventoryMovement::query()->where('movement_type', InventoryMovementType::ReservationConsumed)->count();
    }

    private function carrier(string $name): OrganizationContact
    {
        $contact = new OrganizationContact;
        $contact->forceFill(['organization_id' => $this->organization->id, 'full_name' => $name, 'company_name' => $name, 'contact_type' => OrganizationContact::TYPE_TRANSPORT, 'active' => true])->save();

        return $contact;
    }

    /** @param list<string> $permissions */
    private function member(array $permissions, ?Store $store = null): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($this->organization, $user, $permissions);
        $this->addStoreMember($store ?? $this->store, $user);

        return $user;
    }

    /** @param list<string> $scopes */
    private function connect(User $user, array $scopes = self::SCOPES): void
    {
        app()->forgetScopedInstances();
        $issued = app(McpConnectionCredentials::class)->issue($user, $this->organization, 'Agent', $scopes, null);
        app(McpExecutionContext::class)->bind($issued['connection']->load(['organization', 'user', 'scopeRecords']));
    }

    /** @return array<string, mixed> */
    private function tool(string $method, array $arguments = []): array
    {
        return app(FulfillmentTools::class)->{$method}(...$arguments)->structuredContent;
    }

    /** @param list<string> $scopes @return list<string> */
    private function listTools(User $user, array $scopes): array
    {
        app()->forgetScopedInstances();
        $issued = app(McpConnectionCredentials::class)->issue($user, $this->organization, 'Agent', $scopes, null);
        $headers = ['Authorization' => 'Bearer '.$issued['secret'], 'Accept' => 'application/json, text/event-stream'];
        $session = $this->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]])->headers->get('Mcp-Session-Id');
        $headers += ['Mcp-Session-Id' => $session, 'Mcp-Protocol-Version' => '2025-11-25'];
        $this->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $names = array_column($this->withHeaders($headers)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass])->json('result.tools') ?? [], 'name');
        $this->flushHeaders();

        return $names;
    }
}
