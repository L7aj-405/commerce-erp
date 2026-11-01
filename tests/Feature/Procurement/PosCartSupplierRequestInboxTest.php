<?php

namespace Tests\Feature\Procurement;

use App\Actions\Pos\ConvertPosCartToSalesOrderDraftAction;
use App\Actions\Pos\CreateCartSupplierRequestAction;
use App\Actions\Pos\RecordCartSupplierAvailabilityAction;
use App\Actions\Quotations\CreateQuotationSupplierRequestAction;
use App\Actions\Sales\CreateSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Enums\QuotationSupplierAvailabilityStatus as Status;
use App\Enums\SalesOrderSource;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\QuotationSupplierAvailabilityRequest;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesOrderProcurement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Pos\PosCartSourcing;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\QuotationTestCase;

/**
 * Achats › Demandes fournisseur lists POS-cart supplier requests next to Devis
 * requests — one inbox, one evidence table, the canonical actions of each origin.
 */
class PosCartSupplierRequestInboxTest extends QuotationTestCase
{
    private const PAGE = 'Procurement/SupplierRequests/Index';

    private User $owner;

    private Organization $organization;

    private Store $store;

    private Warehouse $warehouse;

    private ProductVariant $variant;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00'));

        $this->owner = User::factory()->create();
        $this->organization = $this->createOrganization($this->owner);
        $this->store = $this->createStore($this->organization, $this->owner);
        $this->warehouse = $this->createWarehouse($this->organization, 'Showroom', 'SHW');
        $this->variant = $this->createProduct($this->organization, 'Canapé Rio', 'RIO', ['default_sale_price' => '1000.0000'])->variants->firstOrFail();
        $this->activate($this->owner, $this->organization, $this->store);
        $this->supplier = new Supplier;
        $this->supplier->organization_id = $this->organization->id;
        $this->supplier->name = 'Mobilia Import';
        $this->supplier->active = true;
        $this->supplier->save();
    }

    public function test_quotation_and_pos_origin_requests_appear_together_with_their_source(): void
    {
        $quotationRequest = $this->quotationRequest();
        $customer = $this->createCustomer($this->organization, 'Client Cart');
        [$cart, $line] = $this->cart('3', $customer->id);
        $cartRequest = $this->cartRequest($cart, $line);

        $this->inbox()->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component(self::PAGE)
            ->has('requests.data', 2)
            ->where('requests.data', function ($rows) use ($quotationRequest, $cartRequest, $cart, $customer) {
                $rows = collect($rows)->keyBy('id');
                $quote = $rows[$quotationRequest->id];
                $pos = $rows[$cartRequest->id];

                return $quote['source'] === 'quotation'
                    && $quote['quotation']['id'] === $quotationRequest->quotation_id
                    && $quote['cart'] === null
                    && $pos['source'] === 'pos'
                    && $pos['quotation'] === null
                    && $pos['cart'] === ['id' => $cart->id, 'number' => $cart->order_number, 'is_open' => true, 'stage' => 'pos_cart']
                    && $pos['customer'] === ['id' => $customer->id, 'name' => 'Client Cart']
                    && $pos['article']['name'] === 'Canapé Rio'
                    && $pos['supplier_name'] === 'Mobilia Import'
                    && $pos['requested_quantity'] === '3.0000'
                    && $pos['status'] === 'pending';
            })
            ->where('can.viewSalesOrders', true));

        // Source filter and search reach the cart context.
        $this->inbox(['source' => 'pos'])->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.id', $cartRequest->id));
        $this->inbox(['source' => 'quotation'])->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.id', $quotationRequest->id));
        $this->inbox(['search' => $cart->order_number])->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.id', $cartRequest->id));
    }

    public function test_recording_from_the_inbox_updates_the_same_request_the_pos_modal_shows(): void
    {
        [$cart, $line] = $this->cart('3');
        $request = $this->cartRequest($cart, $line);

        // A back-office buyer (no POS access) answers for the supplier.
        $buyer = $this->member(['quotations.view', 'procurement.view', 'procurement.manage', 'suppliers.view']);
        $this->actingAs($buyer)->from(route('procurement.supplier-requests.index'))
            ->patch(route('procurement.supplier-requests.record', $request), ['outcome' => 'available', 'confirmed_quantity' => '2'])
            ->assertRedirect(route('procurement.supplier-requests.index'))
            ->assertSessionHasNoErrors();

        // No duplicate evidence: the very same row, now partial.
        $this->assertSame(1, QuotationSupplierAvailabilityRequest::query()->count());
        $this->assertSame(Status::Partial, $request->fresh()->status);
        $this->assertSame('2.0000', $request->fresh()->confirmed_quantity);

        // The POS sourcing modal (cart payload) reads that same answer.
        $sourcing = app(PosCartSourcing::class)->evaluate($cart->fresh())['lines'][$line->id];
        $this->assertSame('partial', $sourcing['state']);
        $this->assertSame([$request->id], array_column($sourcing['requests'], 'id'));
        $this->actingAs($this->owner)->postJson(route('pos.drafts.store'), ['warehouse_id' => $this->warehouse->id])
            ->assertOk()
            ->assertJsonPath('active_sale.lines.0.sourcing.requests.0.id', $request->id)
            ->assertJsonPath('active_sale.lines.0.sourcing.requests.0.status', 'partial');

        // Same canonical rule on re-record: full quantity → available.
        $this->actingAs($buyer)->patch(route('procurement.supplier-requests.record', $request), ['outcome' => 'available'])->assertSessionHasNoErrors();
        $this->assertSame(Status::Available, $request->fresh()->status);
        $this->assertSame(1, QuotationSupplierAvailabilityRequest::query()->count());
    }

    public function test_cancelling_a_cart_request_from_the_inbox_uses_the_cart_action(): void
    {
        [$cart, $line] = $this->cart('2');
        $request = $this->cartRequest($cart, $line);

        $this->actingAs($this->owner)->post(route('procurement.supplier-requests.cancel', $request))->assertSessionHasNoErrors();

        $this->assertSame(Status::Cancelled, $request->fresh()->status);
        $this->assertSame('to_source', app(PosCartSourcing::class)->evaluate($cart->fresh())['lines'][$line->id]['state']);
    }

    public function test_a_converted_cart_request_is_read_only_and_never_duplicates_its_evidence(): void
    {
        [$cart, $line] = $this->cart('2');
        $request = $this->cartRequest($cart, $line);
        app(RecordCartSupplierAvailabilityAction::class)->execute($this->owner, $cart, $request, ['outcome' => 'available']);
        app(ConvertPosCartToSalesOrderDraftAction::class)->execute($this->owner, $cart->fresh());

        $this->inbox(['status' => 'all'])->assertInertia(fn (Assert $page) => $page
            ->where('requests.data.0.cart.is_open', false)
            ->where('requests.data.0.cart.stage', 'order_draft'));

        // The evidence is consumed by its requirement: no further answer from the inbox.
        $this->actingAs($this->owner)->patch(route('procurement.supplier-requests.record', $request), ['outcome' => 'unavailable'])
            ->assertSessionHasErrors('order');
        $this->assertSame(Status::Available, $request->fresh()->status);
        $this->assertSame(1, QuotationSupplierAvailabilityRequest::query()->count());
        $this->assertSame(1, SalesOrderProcurement::query()->where('source_supplier_request_id', $request->id)->count());
    }

    public function test_quotation_requests_cannot_be_driven_through_the_cart_routes(): void
    {
        $quotationRequest = $this->quotationRequest();

        $this->actingAs($this->owner)->patch(route('procurement.supplier-requests.record', $quotationRequest), ['outcome' => 'available'])->assertNotFound();
        $this->actingAs($this->owner)->post(route('procurement.supplier-requests.cancel', $quotationRequest))->assertNotFound();
        $this->assertSame(Status::Pending, $quotationRequest->fresh()->status);
    }

    public function test_tenant_store_and_permission_rules_are_enforced_for_cart_requests(): void
    {
        [$cart, $line] = $this->cart('2');
        $request = $this->cartRequest($cart, $line);

        // Read-only purchasing viewer: sees the row, cannot answer or withdraw it.
        $viewer = $this->member(['quotations.view', 'procurement.view', 'suppliers.view']);
        $this->actingAs($viewer)->get(route('procurement.supplier-requests.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)
                ->where('can.record', false)->where('can.cancelCart', false)->where('can.viewSalesOrders', false));
        $this->actingAs($viewer)->patch(route('procurement.supplier-requests.record', $request), ['outcome' => 'available'])->assertForbidden();
        $this->actingAs($viewer)->post(route('procurement.supplier-requests.cancel', $request))->assertForbidden();

        // Same organisation, another active store: not listed, not reachable.
        $secondStore = $this->createStore($this->organization, $this->owner, 'Second');
        $this->activate($this->owner, $this->organization, $secondStore);
        $this->inbox()->assertInertia(fn (Assert $page) => $page->has('requests.data', 0));
        $this->actingAs($this->owner)->patch(route('procurement.supplier-requests.record', $request), ['outcome' => 'available'])->assertNotFound();

        // Another organisation: nothing leaks and nothing is reachable.
        $stranger = User::factory()->create();
        $other = $this->createOrganization($stranger, 'Other');
        $otherStore = $this->createStore($other, $stranger);
        $this->activate($stranger, $other, $otherStore);
        $this->actingAs($stranger)->get(route('procurement.supplier-requests.index'))->assertInertia(fn (Assert $page) => $page->has('requests.data', 0));
        $this->actingAs($stranger)->patch(route('procurement.supplier-requests.record', $request), ['outcome' => 'available'])->assertNotFound();

        $this->assertSame(Status::Pending, $request->fresh()->status);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function inbox(array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->get(route('procurement.supplier-requests.index', $query));
    }

    /** @return array{SalesOrder, SalesOrderLine} */
    private function cart(string $quantity, ?int $customerId = null): array
    {
        $this->activate($this->owner, $this->organization, $this->store);
        $cart = app(CreateSalesOrderAction::class)->execute($this->owner, $this->organization, $this->store, [
            'customer_id' => $customerId, 'sale_date' => '2026-10-01', 'currency_code' => 'MAD', 'notes' => null,
        ], SalesOrderSource::Pos);
        $cart->pos_warehouse_id = $this->warehouse->id;
        $cart->pos_global_discount_type = 'none';
        $cart->pos_global_discount_value = '0.0000';
        $cart->pos_fulfillment_mode = 'pickup';
        $cart->pos_shipping_fee = '0.0000';
        $cart->save();
        $line = app(SaveSalesOrderLineAction::class)->execute($this->owner, $cart, [
            'line_type' => 'catalog', 'product_variant_id' => $this->variant->id, 'warehouse_id' => $this->warehouse->id,
            'quantity' => $quantity, 'discount_type' => 'none', 'discount_value' => '0.0000',
        ]);

        return [$cart->fresh(), $line->fresh()];
    }

    private function cartRequest(SalesOrder $cart, SalesOrderLine $line): QuotationSupplierAvailabilityRequest
    {
        return app(CreateCartSupplierRequestAction::class)->execute($this->owner, $cart, $line, ['supplier_id' => $this->supplier->id]);
    }

    private function quotationRequest(): QuotationSupplierAvailabilityRequest
    {
        $quotation = $this->createQuotation($this->owner, $this->organization, $this->store);
        $line = $this->addCatalogQuotationLine($this->owner, $quotation, $this->variant, ['quantity' => '4']);

        return app(CreateQuotationSupplierRequestAction::class)->execute($this->owner, $quotation, $line, ['supplier_id' => $this->supplier->id]);
    }

    /** @param list<string> $permissions */
    private function member(array $permissions): User
    {
        $user = User::factory()->create();
        $this->addOrganizationMember($this->organization, $user, $permissions);
        $this->addStoreMember($this->store, $user);
        $this->activate($user, $this->organization, $this->store);

        return $user;
    }
}
