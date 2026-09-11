<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\AssignTransferRequestDriverAction;
use App\Actions\Inventory\PrepareTransferRequestAction;
use App\Actions\Inventory\ReceiveTransferRequestAction;
use App\Actions\Inventory\ShipTransferRequestAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Enums\TransferRequestReason;
use App\Enums\TransferRequestStatus;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\TransferRequest;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DocumentPdfService;
use App\Services\TransferRequestDocumentRenderer;
use Illuminate\Validation\ValidationException;
use Tests\Support\PosTestCase;

class TransferRequestBonDeSortieTest extends PosTestCase
{
    /** @return array{User, Organization, Store, Warehouse, Warehouse, ProductVariant} */
    private function context(): array
    {
        $owner = User::factory()->create();
        $organization = $this->createOrganization($owner);
        $store = $this->createStore($organization, $owner);
        $showroom = $this->createWarehouse($organization, 'Showroom AV', 'SHW');
        $depot = $this->createWarehouse($organization, 'Dépôt principal', 'DEP');
        $tax = $this->createTaxRate($organization, 'TVA 20', '20.0000');
        $variant = $this->createProduct($organization, 'Micro MV7i', 'MV7I', ['default_sale_price' => '1000.0000', 'tax_rate_id' => $tax->id])->variants->first();
        $this->activate($owner, $organization, $store);
        $this->openStock($owner, $organization, $showroom, $variant, '5.0000');
        $this->openStock($owner, $organization, $depot, $variant, '30.0000');

        return [$owner, $organization, $store, $showroom, $depot, $variant];
    }

    /** A prepared (Depot → Showroom, qty 5) request from a real confirmed POS order. */
    private function preparedRequest(): array
    {
        [$owner, $org, $store, $showroom, $depot, $variant] = $this->context();
        $draft = $this->createPosDraft($owner, $org, $store, $showroom);
        $this->addCatalogLine($owner, $draft, $variant, $showroom, ['quantity' => '10.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $draft->fresh());

        $request = TransferRequest::query()->where('sales_order_id', $order->id)->firstOrFail();
        app(PrepareTransferRequestAction::class)->execute($owner, $request->fresh());

        return [$owner, $org, $store, $showroom, $depot, $variant, $request->fresh(), $order];
    }

    private function bonHtml(TransferRequest $request): string
    {
        return app(TransferRequestDocumentRenderer::class)->html($request->fresh());
    }

    // --- chauffeur data ------------------------------------------------

    public function test_a_manual_external_chauffeur_name_can_be_stored_without_an_erp_user(): void
    {
        [$owner, , , , , , $request] = $this->preparedRequest();

        app(AssignTransferRequestDriverAction::class)->execute($owner, $request, [
            'driver_name' => 'Youssef Transporteur',
            'driver_phone' => '0612345678',
        ]);

        $request->refresh();
        $this->assertNull($request->driver_user_id);
        $this->assertSame('Youssef Transporteur', $request->driver_name);
        $this->assertSame('0612345678', $request->driver_phone);
        $this->assertDatabaseHas('audit_logs', ['event' => 'transfer_request.driver_assigned', 'auditable_id' => $request->id]);
    }

    public function test_an_erp_member_can_be_selected_as_chauffeur_and_the_name_is_snapshotted(): void
    {
        [$owner, $org, , , , , $request] = $this->preparedRequest();
        $driver = User::factory()->create(['name' => 'Karim Livreur']);
        $this->addOrganizationMember($org, $driver, [], roleName: 'Driver');

        app(AssignTransferRequestDriverAction::class)->execute($owner, $request, ['driver_user_id' => $driver->id]);

        $request->refresh();
        $this->assertSame($driver->id, $request->driver_user_id);
        $this->assertSame('Karim Livreur', $request->driver_name);
    }

    public function test_optional_vehicle_information_persists(): void
    {
        [$owner, , , , , , $request] = $this->preparedRequest();

        app(AssignTransferRequestDriverAction::class)->execute($owner, $request, [
            'driver_name' => 'Ali',
            'vehicle' => 'Fourgon Renault',
            'vehicle_registration' => '12345-A-6',
        ]);

        $request->refresh();
        $this->assertSame('Fourgon Renault', $request->vehicle);
        $this->assertSame('12345-A-6', $request->vehicle_registration);
    }

    public function test_shipping_records_chauffeur_shipped_at_and_shipped_by(): void
    {
        [$owner, , , , , , $request] = $this->preparedRequest();

        app(ShipTransferRequestAction::class)->execute($owner, $request, [
            'driver_name' => 'Said',
            'vehicle' => 'Camionnette',
        ]);

        $request->refresh();
        $this->assertSame(TransferRequestStatus::Shipped, $request->status);
        $this->assertSame($owner->id, (int) $request->shipped_by_user_id);
        $this->assertNotNull($request->shipped_at);
        $this->assertSame('Said', $request->driver_name);
        $this->assertSame('Camionnette', $request->vehicle);
        $this->assertDatabaseHas('audit_logs', ['event' => 'transfer_request.driver_assigned', 'auditable_id' => $request->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'transfer_request.shipped', 'auditable_id' => $request->id]);
    }

    public function test_driver_cannot_be_assigned_on_a_received_request(): void
    {
        [$owner, , , , , , $request] = $this->preparedRequest();
        app(ShipTransferRequestAction::class)->execute($owner, $request);
        app(ReceiveTransferRequestAction::class)->execute($owner, $request->fresh());

        $this->expectException(ValidationException::class);
        app(AssignTransferRequestDriverAction::class)->execute($owner, $request->fresh(), ['driver_name' => 'Trop tard']);
    }

    // --- Bon de sortie content --------------------------------------

    public function test_bon_renders_from_the_right_request_with_route_and_articles_but_no_prices(): void
    {
        [$owner, $org, , $showroom, $depot, $variant, $request] = $this->preparedRequest();
        app(AssignTransferRequestDriverAction::class)->execute($owner, $request, ['driver_name' => 'Nabil', 'vehicle' => 'Trafic']);

        $html = $this->bonHtml($request);

        $this->assertStringContainsString('Bon de sortie', $html);
        $this->assertStringContainsString($request->request_number, $html);
        $this->assertStringContainsString('Showroom AV', $html);
        $this->assertStringContainsString('Dépôt principal', $html);
        $this->assertStringContainsString('MV7I', $html); // reference / sku
        $this->assertStringContainsString('Micro MV7i', $html); // designation
        $this->assertStringContainsString('Nabil', $html); // chauffeur
        $this->assertStringContainsString('Trafic', $html); // vehicle
        $this->assertStringContainsString('<td class="num">5</td>', $html); // quantity, no price columns

        // no finance data whatsoever
        foreach ([' HT', 'TVA', 'TTC', '1000.0000', '1 000,00', 'Prix', 'Montant'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, "Bon de sortie must not show {$forbidden}");
        }
    }

    public function test_bon_shows_a_dash_when_no_chauffeur_is_set(): void
    {
        [, , , , , , $request] = $this->preparedRequest();
        $html = $this->bonHtml($request);

        $this->assertStringContainsString('Chauffeur / Livreur', $html);
        // the meta value cell renders an em dash
        $this->assertStringContainsString('—', $html);
    }

    public function test_short_transfer_prints_two_copies_on_one_page(): void
    {
        [, , , , , , $request] = $this->preparedRequest();
        $payload = app(TransferRequestDocumentRenderer::class)->payload($request);
        $html = $this->bonHtml($request);

        $this->assertTrue($payload['two_on_one_page']);
        $this->assertStringContainsString('EXEMPLAIRE 1 — DÉPÔT / ARCHIVE', $html);
        $this->assertStringContainsString('EXEMPLAIRE 2 — TRANSPORT / DESTINATION', $html);
        $this->assertStringContainsString('COUPER ICI', $html);
    }

    public function test_long_transfer_prints_two_full_pages(): void
    {
        [$owner, $org, $store, $showroom, $depot] = $this->context();

        $request = new TransferRequest;
        $request->organization_id = $org->id;
        $request->request_number = 'TRQ-LONG';
        $request->source_warehouse_id = $depot->id;
        $request->destination_warehouse_id = $showroom->id;
        $request->status = TransferRequestStatus::Preparing;
        $request->requested_at = now();
        $request->save();

        for ($i = 1; $i <= 9; $i++) {
            $variant = $this->createProduct($org, "Produit {$i}", "SKU-{$i}")->variants->first();
            $line = new \App\Models\TransferRequestLine;
            $line->organization_id = $org->id;
            $line->transfer_request_id = $request->id;
            $line->product_variant_id = $variant->id;
            $line->quantity = '1.0000';
            $line->reason = TransferRequestReason::Manual;
            $line->save();
        }

        $payload = app(TransferRequestDocumentRenderer::class)->payload($request);
        $html = $this->bonHtml($request);

        $this->assertFalse($payload['two_on_one_page']);
        $this->assertStringContainsString('EXEMPLAIRE 1 — DÉPÔT / ARCHIVE', $html);
        $this->assertStringContainsString('EXEMPLAIRE 2 — TRANSPORT / DESTINATION', $html);
        $this->assertStringContainsString('full-page', $html); // page-break class
        $this->assertStringNotContainsString('COUPER ICI', $html);
    }

    // --- no inventory / lifecycle side effects ---------------------

    public function test_printing_the_bon_causes_no_stock_movement_and_no_lifecycle_change(): void
    {
        [$owner, $org, , $showroom, $depot, $variant, $request] = $this->preparedRequest();
        $movementsBefore = \App\Models\InventoryMovement::query()->count();
        $showroomBefore = $this->balance($org, $showroom, $variant)->only(['on_hand', 'reserved']);
        $depotBefore = $this->balance($org, $depot, $variant)->only(['on_hand', 'reserved']);

        // generate + reprint
        app(DocumentPdfService::class)->transferRequestBonDeSortie($request->fresh());
        app(DocumentPdfService::class)->transferRequestBonDeSortie($request->fresh());
        $this->actingAs($owner)->get(route('inventory.transfer-requests.bon', $request))->assertOk();
        $this->actingAs($owner)->get(route('inventory.transfer-requests.bon.download', $request))->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="Bon-de-sortie-'.$request->request_number.'.pdf"');

        $this->assertSame(TransferRequestStatus::Preparing, $request->fresh()->status);
        $this->assertSame($movementsBefore, \App\Models\InventoryMovement::query()->count());
        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertEquals($showroomBefore, $this->balance($org, $showroom, $variant)->only(['on_hand', 'reserved']));
        $this->assertEquals($depotBefore, $this->balance($org, $depot, $variant)->only(['on_hand', 'reserved']));
    }

    public function test_stock_still_moves_only_at_digital_reception_not_at_print_or_ship(): void
    {
        [$owner, $org, , $showroom, $depot, $variant, $request] = $this->preparedRequest();

        app(DocumentPdfService::class)->transferRequestBonDeSortie($request->fresh());
        app(ShipTransferRequestAction::class)->execute($owner, $request->fresh(), ['driver_name' => 'Omar']);
        app(DocumentPdfService::class)->transferRequestBonDeSortie($request->fresh()); // reprint after ship

        // still no ledger movement while only shipped
        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertSame('30.0000', $this->balance($org, $depot, $variant)->on_hand);
        $this->assertSame('5.0000', $this->balance($org, $showroom, $variant)->on_hand);

        app(ReceiveTransferRequestAction::class)->execute($owner, $request->fresh());

        $this->assertDatabaseCount('stock_transfers', 1);
        $this->assertSame('25.0000', $this->balance($org, $depot, $variant)->on_hand);
        $this->assertSame('10.0000', $this->balance($org, $showroom, $variant)->on_hand);
    }

    // --- historical stability ------------------------------------

    public function test_a_renamed_driver_user_does_not_change_an_already_shipped_bon(): void
    {
        [$owner, $org, , , , , $request] = $this->preparedRequest();
        $driver = User::factory()->create(['name' => 'Rachid Ancien']);
        $this->addOrganizationMember($org, $driver, [], roleName: 'Driver');

        app(ShipTransferRequestAction::class)->execute($owner, $request, ['driver_user_id' => $driver->id]);
        $this->assertSame('Rachid Ancien', $request->fresh()->driver_name);

        $driver->forceFill(['name' => 'Rachid Nouveau Nom'])->save();

        $request->refresh();
        $this->assertSame('Rachid Ancien', $request->driver_name);
        $this->assertStringContainsString('Rachid Ancien', $this->bonHtml($request));
        $this->assertStringNotContainsString('Rachid Nouveau Nom', $this->bonHtml($request));
    }

    // --- security -----------------------------------------------

    public function test_bon_is_tenant_isolated_and_authorization_is_enforced(): void
    {
        [$owner, $org, $store, , , , $request] = $this->preparedRequest();

        // foreign tenant → hidden 404
        $outsider = User::factory()->create();
        $otherOrg = $this->createOrganization($outsider);
        $otherStore = $this->createStore($otherOrg, $outsider);
        $this->activate($outsider, $otherOrg, $otherStore);
        $this->actingAs($outsider)->get(route('inventory.transfer-requests.bon', $request))->assertNotFound();

        // member with only view can read the Bon but cannot assign a driver
        $clerk = User::factory()->create();
        $this->addOrganizationMember($org, $clerk, ['inventory.transfer_requests.view'], roleName: 'Viewer');
        $this->addStoreMember($store, $clerk);
        $this->activate($clerk, $org, $store);
        $this->actingAs($clerk)->get(route('inventory.transfer-requests.bon', $request))->assertOk();
        $this->actingAs($clerk)->patch(route('inventory.transfer-requests.driver', $request), ['driver_name' => 'X'])->assertForbidden();

        // requested state has no Bon yet
        $freshRequested = new TransferRequest;
        $freshRequested->organization_id = $org->id;
        $freshRequested->request_number = 'TRQ-NOBON';
        $freshRequested->source_warehouse_id = $request->source_warehouse_id;
        $freshRequested->destination_warehouse_id = $request->destination_warehouse_id;
        $freshRequested->status = TransferRequestStatus::Requested;
        $freshRequested->requested_at = now();
        $freshRequested->save();
        $this->actingAs($owner)->get(route('inventory.transfer-requests.bon', $freshRequested))->assertNotFound();
    }
}
